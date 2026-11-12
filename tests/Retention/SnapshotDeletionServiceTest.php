<?php

declare(strict_types=1);

namespace App\Tests\Retention;

use App\Domain\BoundingBox;
use App\Domain\ReviewService;
use App\Domain\ReviewSubmission;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Review;
use App\Entity\Snapshot;
use App\Enum\ReviewValidity;
use App\Retention\SnapshotDeletionService;
use App\Repository\AuditEntryRepository;
use App\Repository\DetectionRepository;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Permanent snapshot deletion (design.md D11, task 8.2): dependent rows go
 * in one transaction, files strictly after the commit, shared snapshots only
 * when their last reference goes away, orphaned events with them.
 */
final class SnapshotDeletionServiceTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    /** Varies each generated image so content hashes (and files) never collide. */
    private static int $imageSequence = 0;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-deletion-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testDeletesSnapshotRowFilesReviewAndAuditEntriesTogether(): void
    {
        $snapshot = $this->createStoredSnapshot();
        $detection = $this->createFrigateDetectionOnCamera('front_door', $snapshot);

        $reviewService = new ReviewService($this->entityManager());
        $reviewService->save($detection, new ReviewSubmission(
            ReviewValidity::FalsePositive,
            null,
            null,
            false,
            'tree shadow',
            null,
        ));

        $snapshotId = $snapshot->getId();
        $detectionId = $detection->getId();

        $result = $this->deletionService()->deleteSnapshot($snapshot);

        self::assertSame(1, $result->deletedDetections);
        self::assertSame(1, $result->deletedReviews);
        self::assertSame(2, $result->deletedAuditEntries);
        self::assertSame(1, $result->deletedEvents);
        self::assertSame([], $result->fileWarnings);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        self::assertNull($entityManager->find(Snapshot::class, $snapshotId));
        self::assertNull($entityManager->find(Detection::class, $detectionId));
        self::assertCount(0, $entityManager->getRepository(Review::class)->findAll());
        self::assertCount(0, $entityManager->getRepository(\App\Entity\AuditEntry::class)->findAll());

        self::assertFileDoesNotExist($snapshot->getOriginalPath());
        self::assertFileDoesNotExist((string) $snapshot->getThumbnailPath());
        self::assertSame(
            [$snapshot->getOriginalPath(), $snapshot->getThumbnailPath()],
            $result->deletedFiles,
        );
    }

    public function testEventSurvivesWhenItStillHasOtherDetections(): void
    {
        $event = $this->createEvent('front_door');
        $survivingDetection = $this->createDetectionForEvent($event, $this->createStoredSnapshot());
        $deletedDetection = $this->createDetectionForEvent($event, $this->createStoredSnapshot());

        $this->deletionService()->deleteSnapshot($deletedDetection->getSnapshot());

        self::assertNotNull(
            $this->entityManager()->find(FrigateEvent::class, $event->getId()),
            'Event with a surviving detection must not be deleted.',
        );
        self::assertSame(1, $this->detectionRepository()->countDetectionsForEvent($event));
        self::assertNotNull($this->entityManager()->find(Detection::class, $survivingDetection->getId()));
    }

    public function testDeletesEveryDetectionSharingOneSnapshot(): void
    {
        $snapshot = $this->createStoredSnapshot();
        $first = $this->createFrigateDetectionOnCamera('front_door', $snapshot);
        $second = $this->createFrigateDetectionOnCamera('back_garden', $snapshot);

        $firstId = $first->getId();
        $secondId = $second->getId();
        $snapshotId = $snapshot->getId();

        $result = $this->deletionService()->deleteSnapshot($snapshot);

        self::assertSame(2, $result->deletedDetections);
        self::assertNull($this->entityManager()->find(Snapshot::class, $snapshotId));
        self::assertNull($this->entityManager()->find(Detection::class, $firstId));
        self::assertNull($this->entityManager()->find(Detection::class, $secondId));
        self::assertFileDoesNotExist($snapshot->getOriginalPath());
    }

    public function testUnremovableFileBecomesWarningWhileRowsAreStillDeleted(): void
    {
        $snapshot = $this->createStoredSnapshot();
        $detection = $this->createFrigateDetectionOnCamera('front_door', $snapshot);

        // A directory cannot be unlinked: simulates a file that survives.
        $blockedPath = $this->storageRoot.'/thumbs/blocked.jpg';
        mkdir(\dirname($blockedPath), 0775, true);
        mkdir($blockedPath);
        $snapshot->setThumbnailPath($blockedPath);
        $this->persist($snapshot);

        $detectionId = $detection->getId();

        $result = $this->deletionService()->deleteSnapshot($snapshot);

        self::assertSame([$snapshot->getOriginalPath()], $result->deletedFiles);
        self::assertCount(1, $result->fileWarnings);
        self::assertStringContainsString('orphan', $result->fileWarnings[0]);
        self::assertNull($this->entityManager()->find(Detection::class, $detectionId));
        self::assertFileDoesNotExist($snapshot->getOriginalPath());
    }

    private function deletionService(): SnapshotDeletionService
    {
        return new SnapshotDeletionService(
            $this->entityManager(),
            new AuditEntryRepository($this->entityManager()),
        );
    }

    private function detectionRepository(): DetectionRepository
    {
        return new DetectionRepository($this->entityManager());
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

    private function createFrigateDetectionOnCamera(string $camera, Snapshot $snapshot): Detection
    {
        return $this->createDetectionForEvent($this->createEvent($camera), $snapshot);
    }

    private function createDetectionForEvent(FrigateEvent $event, Snapshot $snapshot): Detection
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

    private function createStoredSnapshot(): Snapshot
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 320, 240, imagecolorallocate($image, 40 + self::$imageSequence++, 120, 200));
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
        $this->persist($snapshot);

        return $snapshot;
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
