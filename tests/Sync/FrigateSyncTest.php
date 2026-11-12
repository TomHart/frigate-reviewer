<?php

declare(strict_types=1);

namespace App\Tests\Sync;

use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Frigate\FrameDimensions;
use App\Frigate\FrigateApiException;
use App\Sync\SnapshotStore;
use App\Sync\SyncService;
use App\Tests\AbstractDatabaseTestCase;

final class FrigateSyncTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testSyncStoresEventsDetectionsAndSnapshots(): void
    {
        $result = $this->syncWith($this->fakeClient());

        self::assertFalse($result->hasErrors());
        self::assertSame(2, $result->storedEvents);
        self::assertSame(2, $this->entityCount(FrigateEvent::class));
        self::assertSame(2, $this->entityCount(Detection::class));
        self::assertSame(2, $this->entityCount(Snapshot::class));

        $detections = $this->detectionsByEventId();
        $frontDoor = $detections['e1'];

        // Frigate facts stored exactly as reported, box normalised to 0..1
        // against the camera's 1280x720 detection frame.
        self::assertSame('person', $frontDoor->getSourceClass());
        self::assertEqualsWithDelta(0.87, $frontDoor->getConfidence(), 0.000001);
        self::assertSame('front_door', $frontDoor->getEvent()->getCamera());
        self::assertEquals(new \DateTimeImmutable('@1762900000'), $frontDoor->getStartTime());
        self::assertEquals(new \DateTimeImmutable('@1762900005'), $frontDoor->getEndTime());
        // data.box arrives as [x, y, w, h] on the wire and the DTO converts
        // it to corners: x=0.1, y=0.2, w=0.5, h=0.5 → (0.1, 0.2, 0.6, 0.7).
        self::assertEqualsWithDelta(0.1, $frontDoor->getOriginalBox()->x, 0.000001);
        self::assertEqualsWithDelta(0.2, $frontDoor->getOriginalBox()->y, 0.000001);
        self::assertEqualsWithDelta(0.5, $frontDoor->getOriginalBox()->width, 0.000001);
        self::assertEqualsWithDelta(0.5, $frontDoor->getOriginalBox()->height, 0.000001);

        // Snapshot stored locally at its content-addressed path.
        $snapshot = $frontDoor->getSnapshot();
        self::assertNotNull($snapshot);
        self::assertFileExists($snapshot->getOriginalPath());
        self::assertSame('front-door-snapshot-bytes', file_get_contents($snapshot->getOriginalPath()));
        self::assertSame(hash('sha256', 'front-door-snapshot-bytes'), $snapshot->getContentHash());

        // Clip stored as a link, never downloaded.
        self::assertSame('/api/front_door/clips/e1.mp4', $frontDoor->getEvent()->getClipPath());
        self::assertNull($detections['e2']->getEvent()->getClipPath());

        // A synced detection starts unreviewed; sync never writes review data.
        self::assertNull($frontDoor->getReview());
    }

    public function testRerunningSyncStoresNothingTwiceAndMutatesNothing(): void
    {
        $this->syncWith($this->fakeClient());

        $beforeDetections = $this->detectionsByEventId();
        $beforeEvents = $this->eventsByFrigateId();
        $beforeSnapshotHashes = $this->snapshotHashes();
        $beforeSnapshotFileHashes = $this->snapshotFileHashes();

        $this->entityManager()->clear();

        $result = $this->syncWith($this->fakeClient());

        self::assertSame(0, $result->storedEvents);
        self::assertSame(2, $result->skippedEvents);
        self::assertFalse($result->hasErrors());

        // Nothing stored twice.
        self::assertSame(2, $this->entityCount(FrigateEvent::class));
        self::assertSame(2, $this->entityCount(Detection::class));
        self::assertSame(2, $this->entityCount(Snapshot::class));
        self::assertSame($beforeSnapshotHashes, $this->snapshotHashes());

        // Existing Frigate facts were never modified.
        $afterDetections = $this->detectionsByEventId();
        $afterEvents = $this->eventsByFrigateId();

        foreach ($beforeDetections as $frigateEventId => $before) {
            $after = $afterDetections[$frigateEventId];
            self::assertSame($before->getSourceClass(), $after->getSourceClass(), 'source class mutated');
            self::assertSame($before->getConfidence(), $after->getConfidence(), 'confidence mutated');
            self::assertEquals($before->getStartTime(), $after->getStartTime(), 'start time mutated');
            self::assertEquals($before->getEndTime(), $after->getEndTime(), 'end time mutated');
            // Corners are reconstructed as x + w / y + h, so compare each
            // component with a float delta rather than exact equality.
            $beforeBox = $before->getOriginalBox();
            $afterBox = $after->getOriginalBox();

            if ($beforeBox === null || $afterBox === null) {
                self::assertSame($beforeBox, $afterBox, 'original box mutated');
            } else {
                self::assertEqualsWithDelta($beforeBox->x, $afterBox->x, 0.000001, 'original box x mutated');
                self::assertEqualsWithDelta($beforeBox->y, $afterBox->y, 0.000001, 'original box y mutated');
                self::assertEqualsWithDelta($beforeBox->width, $afterBox->width, 0.000001, 'original box width mutated');
                self::assertEqualsWithDelta($beforeBox->height, $afterBox->height, 0.000001, 'original box height mutated');
            }
            self::assertSame($before->getSnapshot()?->getId(), $after->getSnapshot()?->getId(), 'snapshot link mutated');
        }

        foreach ($beforeEvents as $frigateEventId => $before) {
            $after = $afterEvents[$frigateEventId];
            self::assertSame($before->getCamera(), $after->getCamera());
            self::assertEquals($before->getStartTime(), $after->getStartTime());
            self::assertEquals($before->getEndTime(), $after->getEndTime());
            self::assertSame($before->getClipPath(), $after->getClipPath());
            self::assertSame($before->getCreatedAt()->getTimestamp(), $after->getCreatedAt()->getTimestamp());
        }

        // Original snapshot files were never overwritten.
        self::assertSame($beforeSnapshotFileHashes, $this->snapshotFileHashes());
    }

    public function testSnapshotDownloadFailureLeavesNoHalfWrittenEvent(): void
    {
        $fake = new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload('e1', 'front_door', 1762900000, 1762900005, 'person', 0.87, [0.1, 0.2, 0.5, 0.5]),
                FakeFrigateApiClient::eventPayload('e2', 'front_door', 1762900100, 1762900110, 'dog', 0.5, [0.05, 0.05, 0.25, 0.25]),
            ],
            snapshotBytesByEventId: ['e1' => 'front-door-snapshot-bytes'],
            snapshotFailuresByEventId: [
                'e2' => new FrigateApiException('Frigate API request to snapshot failed with HTTP status 404.'),
            ],
        );

        $result = $this->syncWith($fake);

        self::assertTrue($result->hasErrors());
        self::assertSame(1, $result->storedEvents);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('e2', $result->errors[0]);

        // e1 committed completely.
        self::assertSame(1, $this->entityCount(FrigateEvent::class));
        self::assertSame(1, $this->entityCount(Detection::class));
        self::assertArrayHasKey('e1', $this->detectionsByEventId());

        // e2 left no trace: no half-written event, detection or snapshot.
        self::assertArrayNotHasKey('e2', $this->detectionsByEventId());
        $events = $this->entityManager()->getRepository(FrigateEvent::class)->findAll();
        self::assertSame('e1', $events[0]->getFrigateEventId());
    }

    public function testIdenticalSnapshotBytesAreStoredExactlyOnce(): void
    {
        $sharedBytes = 'identical-snapshot-bytes';
        $fake = new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload('e1', 'front_door', 1762900000, 1762900005, 'person', 0.87, [0.1, 0.2, 0.5, 0.5]),
                FakeFrigateApiClient::eventPayload('e2', 'front_door', 1762900100, 1762900110, 'person', 0.9, [0.2, 0.2, 0.5, 0.5]),
            ],
            snapshotBytesByEventId: ['e1' => $sharedBytes, 'e2' => $sharedBytes],
        );

        $result = $this->syncWith($fake);

        self::assertFalse($result->hasErrors());
        self::assertSame(1, $this->entityCount(Snapshot::class), 'identical content must share one snapshot row');

        $detections = $this->detectionsByEventId();
        self::assertSame(
            $detections['e1']->getSnapshot()?->getId(),
            $detections['e2']->getSnapshot()?->getId(),
        );
    }

    public function testSyncStoresPerceptualHashOnSnapshot(): void
    {
        $fake = new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload('e1', 'front_door', 1762900000, 1762900005, 'person', 0.87, [0.1, 0.2, 0.5, 0.5]),
                FakeFrigateApiClient::eventPayload('e2', 'front_door', 1762900100, 1762900110, 'dog', 0.5, [0.05, 0.05, 0.25, 0.25]),
            ],
            snapshotBytesByEventId: [
                'e1' => $this->jpegBytes(),
                'e2' => 'not-a-decodable-image',
            ],
            frameDimensionsByCamera: ['front_door' => new FrameDimensions(1280, 720)],
        );

        $result = $this->syncWith($fake);

        self::assertFalse($result->hasErrors());
        self::assertSame(2, $this->entityCount(Snapshot::class));

        $snapshotsByEventId = [];
        foreach ($this->detectionsByEventId() as $frigateEventId => $detection) {
            $snapshotsByEventId[$frigateEventId] = $detection->getSnapshot();
        }

        $hashed = $snapshotsByEventId['e1'];
        self::assertInstanceOf(Snapshot::class, $hashed);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $hashed->getPerceptualHash(), 'A decodable snapshot gets a 64-bit dHash at ingest (task 5.1).');

        $unhashed = $snapshotsByEventId['e2'];
        self::assertInstanceOf(Snapshot::class, $unhashed);
        self::assertNull($unhashed->getPerceptualHash(), 'Undecodable bytes are stored anyway; their hash simply stays null.');

        // Idempotent re-run: the stored hash is never recomputed or changed.
        $hashBefore = $hashed->getPerceptualHash();
        $this->entityManager()->clear();
        $this->syncWith($fake);
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Snapshot::class, $hashed->getId());
        self::assertInstanceOf(Snapshot::class, $reloaded);
        self::assertSame($hashBefore, $reloaded->getPerceptualHash());
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(100, 100);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 99, 99, $black);

        for ($stripe = 0; $stripe < 10; ++$stripe) {
            if ($stripe % 2 === 1) {
                imagefilledrectangle($image, 0, $stripe * 10, 99, $stripe * 10 + 9, $white);
            }
        }

        \ob_start();
        imagejpeg($image, null, 90);

        return (string) \ob_get_clean();
    }

    public function testCameraAndLimitOptionsNarrowWhatIsSynced(): void
    {
        $fake = $this->fakeClient();

        $result = $this->syncServiceFor($fake)->sync(new \App\Frigate\EventListQuery(camera: 'side_garden'));
        self::assertSame(1, $result->storedEvents);
        self::assertSame(1, $this->entityCount(FrigateEvent::class));

        $this->entityManager()->clear();

        $result = $this->syncServiceFor($fake)->sync(new \App\Frigate\EventListQuery(maxResults: 1));
        self::assertSame(1, $result->storedEvents);
        self::assertSame(2, $this->entityCount(FrigateEvent::class));
    }

    private function fakeClient(): FakeFrigateApiClient
    {
        return new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload(
                    'e1',
                    'front_door',
                    1762900000,
                    1762900005,
                    'person',
                    0.87,
                    // Pixel coordinates of a 1280x720 detection frame.
                    [128, 144, 640, 360],
                    hasSnapshot: true,
                    hasClip: true,
                ),
                FakeFrigateApiClient::eventPayload(
                    'e2',
                    'side_garden',
                    1762900100,
                    1762900110,
                    'dog',
                    0.5,
                    // Already-normalised ratio box.
                    [0.05, 0.05, 0.25, 0.25],
                    hasSnapshot: true,
                    hasClip: false,
                ),
            ],
            snapshotBytesByEventId: [
                'e1' => 'front-door-snapshot-bytes',
                'e2' => 'side-garden-snapshot-bytes',
            ],
            frameDimensionsByCamera: [
                'front_door' => new FrameDimensions(1280, 720),
            ],
        );
    }

    private function syncWith(FakeFrigateApiClient $fake): \App\Sync\SyncResult
    {
        return $this->syncServiceFor($fake)->sync(new \App\Frigate\EventListQuery());
    }

    private function syncServiceFor(FakeFrigateApiClient $fake): SyncService
    {
        return new SyncService(
            $fake,
            $this->entityManager(),
            new SnapshotStore($this->entityManager(), $this->storageRoot),
        );
    }

    /**
     * @return array<string, Detection> keyed by Frigate event ID
     */
    private function detectionsByEventId(): array
    {
        $detections = [];

        foreach ($this->entityManager()->getRepository(Detection::class)->findAll() as $detection) {
            $detections[$detection->getEvent()->getFrigateEventId()] = $detection;
        }

        return $detections;
    }

    /**
     * @return array<string, FrigateEvent> keyed by Frigate event ID
     */
    private function eventsByFrigateId(): array
    {
        $events = [];

        foreach ($this->entityManager()->getRepository(FrigateEvent::class)->findAll() as $event) {
            $events[$event->getFrigateEventId()] = $event;
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function snapshotHashes(): array
    {
        return array_map(
            static fn (Snapshot $snapshot): string => $snapshot->getContentHash(),
            $this->entityManager()->getRepository(Snapshot::class)->findAll(),
        );
    }

    /**
     * @return array<string, string> original path => file content hash
     */
    private function snapshotFileHashes(): array
    {
        $hashes = [];

        foreach ($this->entityManager()->getRepository(Snapshot::class)->findAll() as $snapshot) {
            clearstatcache(true, $snapshot->getOriginalPath());
            $hashes[$snapshot->getOriginalPath()] = hash_file('sha256', $snapshot->getOriginalPath());
        }

        return $hashes;
    }

    private function entityCount(string $entityClass): int
    {
        return \count($this->entityManager()->getRepository($entityClass)->findAll());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
