<?php

declare(strict_types=1);

namespace App\Tests\Retention;

use App\Domain\BoundingBox;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Tests\AbstractDatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Retention enforcement (design.md D11, task 8.1): global default age limit
 * plus per-camera overrides, executed by the schedulable console command.
 * Shared snapshots are only deleted when expired under every referencing
 * camera's limit, so one camera's short limit never destroys another
 * camera's data.
 */
final class RetentionCommandTest extends AbstractDatabaseTestCase
{
    private const GLOBAL_DEFAULT_DAYS = '30';
    private const FRONT_DOOR_OVERRIDE_DAYS = '7';

    private string $storageRoot;

    /** Varies each generated image so content hashes (and files) never collide. */
    private static int $imageSequence = 0;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-retention-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', null);
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', null);
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testWithoutPolicyConfiguredNothingIsDeleted(): void
    {
        $snapshot = $this->createStoredSnapshot(400);
        $this->createDetectionOnCamera('front_door', $snapshot);

        $output = $this->runCommand('frigate:retention');

        self::assertStringContainsString('No retention policy configured', $output);
        $this->assertSnapshotStillStored($snapshot);
    }

    public function testPerCameraOnlyPolicyNeverTouchesCameralessObjects(): void
    {
        // No global default: only front_door has a policy. Its old snapshot
        // expires, but a camera-less manual object's snapshot has no
        // applicable limit and must survive, however old it is.
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', '7');

        $expiredFrontDoor = $this->createStoredSnapshot(400);
        $this->createDetectionOnCamera('front_door', $expiredFrontDoor);

        $cameraless = $this->createStoredSnapshot(400);
        $this->persist(Detection::manual($cameraless, new BoundingBox(0.3, 0.3, 0.2, 0.2)));

        $expiredFrontDoorId = $expiredFrontDoor->getId();

        $output = $this->runCommand('frigate:retention');

        self::assertStringContainsString('Permanently deleted 1 snapshot(s)', $output);
        self::assertNull($this->entityManager()->find(Snapshot::class, $expiredFrontDoorId));
        $this->assertSnapshotStillStored($cameraless);
        self::assertFileExists($cameraless->getOriginalPath());
    }

    public function testDryRunReportsExpiredSnapshotsWithoutDeletingAnything(): void
    {
        $this->configurePolicy();
        $expired = $this->seedMixedAgesAndCameras();

        $output = $this->runCommand('frigate:retention', ['--dry-run' => true]);

        self::assertStringContainsString('dry run', $output);
        self::assertStringContainsString('4 snapshot(s) would be permanently deleted', $output);
        $this->assertSnapshotStillStored($expired['oldFrontDoor']);
        $this->assertSnapshotStillStored($expired['oldSharedAcrossCameras']);
    }

    public function testDeletesExpiredSnapshotsAndKeepsEverythingElse(): void
    {        $this->configurePolicy();
        $seeded = $this->seedMixedAgesAndCameras();

        // Doctrine nulls identifiers of deleted entities, so capture the ids
        // of the snapshots that must be gone before running the command.
        $deletedSnapshotIds = array_map(
            static fn (Snapshot $snapshot): int => (int) $snapshot->getId(),
            [
                $seeded['oldFrontDoor'],
                $seeded['oldBackGarden'],
                $seeded['oldSharedAcrossCameras'],
                $seeded['oldManualObject'],
            ],
        );
        $keptSnapshots = [
            $seeded['newFrontDoor'],
            $seeded['youngBackGarden'],
            $seeded['recentSharedAcrossCameras'],
        ];

        $output = $this->runCommand('frigate:retention');

        self::assertStringContainsString('Permanently deleted 4 snapshot(s)', $output);
        self::assertStringContainsString('5 object(s)', $output);
        self::assertStringContainsString('3 orphaned event(s)', $output);

        $entityManager = $this->entityManager();

        // Expired and deleted: old front_door (7-day override), old
        // back_garden (30-day global), the shared snapshot expired under
        // BOTH cameras (40 days > max(7, 30) = 30), and the camera-less
        // manual object's snapshot (30-day global) — rows and files both.
        foreach (
            [
                $seeded['oldFrontDoor'],
                $seeded['oldBackGarden'],
                $seeded['oldSharedAcrossCameras'],
                $seeded['oldManualObject'],
            ] as $index => $deleted
        ) {
            self::assertNull($entityManager->find(Snapshot::class, $deletedSnapshotIds[$index]));
            self::assertFileDoesNotExist($deleted->getOriginalPath());
            self::assertFileDoesNotExist((string) $deleted->getThumbnailPath());
        }

        // Kept: new snapshot (young), old back_garden within the global
        // limit, and the shared snapshot that back_garden still needs —
        // front_door's 7-day limit alone must not destroy it.
        foreach ($keptSnapshots as $kept) {
            $this->assertSnapshotStillStored($kept);
            self::assertFileExists($kept->getOriginalPath());
            self::assertFileExists((string) $kept->getThumbnailPath());
        }

        self::assertSame(3, $entityManager->getRepository(Snapshot::class)->count([]));
        self::assertSame(4, $entityManager->getRepository(Detection::class)->count([]));
        self::assertSame(4, $entityManager->getRepository(FrigateEvent::class)->count([]));
    }

    /**
     * Seeds the matrix the policy must get right, with global default 30
     * days and front_door overridden to 7 days.
     *
     * @return array<string, Snapshot>
     */
    private function seedMixedAgesAndCameras(): array
    {
        $seeded = [];

        // front_door (7-day override):
        $seeded['oldFrontDoor'] = $this->createStoredSnapshot(40);   // 40 > 7: expired
        $seeded['newFrontDoor'] = $this->createStoredSnapshot(1);    // young: kept

        // back_garden (no override, global 30 days):
        $seeded['youngBackGarden'] = $this->createStoredSnapshot(20); // 20 < 30: kept
        $seeded['oldBackGarden'] = $this->createStoredSnapshot(40);  // 40 > 30: expired

        // Shared snapshot on front_door (7d) AND back_garden (30d):
        $seeded['recentSharedAcrossCameras'] = $this->createSharedSnapshot(20); // 20 < 30: kept for back_garden
        $seeded['oldSharedAcrossCameras'] = $this->createSharedSnapshot(40);    // 40 > 30: expired for both

        // Manual object with no event: global default applies.
        $seeded['oldManualObject'] = $this->createStoredSnapshot(40);
        $this->persist(Detection::manual(
            $seeded['oldManualObject'],
            new BoundingBox(0.3, 0.3, 0.2, 0.2),
        ));

        // Shared snapshots are created with one detection on each camera by
        // createSharedSnapshot(); no extra detections are added here.

        // The old front_door snapshot shares its event with the new
        // snapshot, so the event must survive the deletion.
        $sharedEvent = $this->createEvent('front_door');
        $this->createDetectionFor($sharedEvent, $seeded['oldFrontDoor']);
        $this->createDetectionFor($sharedEvent, $seeded['newFrontDoor']);

        // Everything else gets its own event: old back_garden becomes
        // orphaned when its snapshot goes.
        $this->createDetectionFor($this->createEvent('back_garden'), $seeded['youngBackGarden']);
        $this->createDetectionFor($this->createEvent('back_garden'), $seeded['oldBackGarden']);

        return $seeded;
    }

    private function configurePolicy(): void
    {
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', self::GLOBAL_DEFAULT_DAYS);
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', self::FRONT_DOOR_OVERRIDE_DAYS);
    }

    private function runCommand(string $name, array $options = []): string
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        // The migrations run in setUp() passes --quiet, which makes Symfony
        // stash SHELL_VERBOSITY=-1 in the process environment; without this
        // reset, every later Application::run in the same process silently
        // runs at QUIET verbosity and captures nothing.
        putenv('SHELL_VERBOSITY');
        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);

        $output = new BufferedOutput();
        $exitCode = $application->run(
            new ArrayInput(['command' => $name] + $options),
            $output,
        );

        $captured = $output->fetch();

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'Command "%s" failed with exit code %d: %s',
                $name,
                $exitCode,
                $captured,
            ));
        }

        return $captured;
    }

    private function assertSnapshotStillStored(Snapshot $snapshot): void
    {
        self::assertNotNull(
            $this->entityManager()->find(Snapshot::class, $snapshot->getId()),
            'Snapshot must still be stored.',
        );
    }

    private function createEvent(string $camera): FrigateEvent
    {
        $event = new FrigateEvent(
            'event-'.bin2hex(random_bytes(8)),
            $camera,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
        );
        $this->persist($event);

        return $event;
    }

    private function createDetectionOnCamera(string $camera, Snapshot $snapshot): Detection
    {
        return $this->createDetectionFor($this->createEvent($camera), $snapshot);
    }

    private function createDetectionFor(FrigateEvent $event, Snapshot $snapshot): Detection
    {
        $detection = Detection::fromFrigate(
            $event,
            $snapshot,
            'person',
            0.87,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
            new \DateTimeImmutable('2026-11-12 10:00:05'),
            new BoundingBox(0.1, 0.2, 0.4, 0.5),
        );
        $this->persist($detection);

        return $detection;
    }

    /**
     * A snapshot stored once, referenced by one detection on each of two
     * cameras (content-addressed sharing, design.md D3).
     */
    private function createSharedSnapshot(int $ageDays): Snapshot
    {
        $snapshot = $this->createStoredSnapshot($ageDays);
        $this->createDetectionFor($this->createEvent('front_door'), $snapshot);
        $this->createDetectionFor($this->createEvent('back_garden'), $snapshot);

        return $snapshot;
    }

    private function createStoredSnapshot(int $ageDays): Snapshot
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 320, 240, imagecolorallocate($image, 40 + self::$imageSequence, 120, 200));
        // Text pixels make the bytes (and therefore the content hash) unique
        // even where JPEG quantisation would flatten a 1-step colour change.
        imagestring($image, 5, 10, 10, (string) self::$imageSequence++, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        $contentHash = hash('sha256', $bytes);
        $originalPath = $this->storageRoot.'/originals/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';
        $thumbnailPath = $this->storageRoot.'/thumbs/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';

        foreach ([$originalPath, $thumbnailPath] as $path) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0775, true);
            }
            file_put_contents($path, $bytes);
        }

        $snapshot = new Snapshot($contentHash, $originalPath);
        $snapshot->setThumbnailPath($thumbnailPath);
        $this->backdate($snapshot, $ageDays);
        $this->persist($snapshot);

        return $snapshot;
    }

    /** Backdates createdAt so the snapshot has a deterministic age in days. */
    private function backdate(Snapshot $snapshot, int $ageDays): void
    {
        $createdAt = new \ReflectionProperty(Snapshot::class, 'createdAt');
        $createdAt->setValue($snapshot, new \DateTimeImmutable(sprintf('-%d days', $ageDays)));
    }

    private function setEnvironment(string $variable, ?string $value): void
    {
        if ($value === null) {
            putenv($variable);
            unset($_ENV[$variable], $_SERVER[$variable]);

            return;
        }

        putenv($variable.'='.$value);
        $_ENV[$variable] = $value;
        $_SERVER[$variable] = $value;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file
        ) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
