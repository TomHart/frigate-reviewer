<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\BoundingBox;
use App\Domain\SimilarSnapshotGrouper;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Repository\DetectionFilter;
use App\Repository\DetectionRepository;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Grouping by Hamming distance over perceptual hashes (design.md D8, task
 * 5.2): seeded snapshots with known hashes group correctly, and grouping is
 * strictly presentation — no row is ever deleted, merged or modified.
 */
final class SimilarSnapshotGrouperTest extends AbstractDatabaseTestCase
{
    private const SIMILAR_HASH = '0000000000000000';
    private const NEAR_HASH = '0000000000000003'; // 2 bits from SIMILAR_HASH.
    private const FAR_HASH = 'f0f0f0f0f0f0f0f0'; // 32 bits from SIMILAR_HASH.

    public function testSnapshotsWithKnownHashesGroupWithinThreshold(): void
    {
        $this->bootDatabase();

        $onSimilarSnapshot = $this->createDetectionWithHash(self::SIMILAR_HASH);
        $onSameHashAgain = $this->createDetectionWithHash(self::SIMILAR_HASH);
        $onNearSnapshot = $this->createDetectionWithHash(self::NEAR_HASH);
        $onFarSnapshot = $this->createDetectionWithHash(self::FAR_HASH);

        $groups = $this->detectionRepository()->similarSnapshotGroups(
            new DetectionFilter(null, null, null, null, null),
            20,
            0,
        );

        self::assertCount(2, $groups);

        $groupedBySize = $groups;
        usort($groupedBySize, static fn (array $left, array $right): int => \count($right) <=> \count($left));

        self::assertSame(
            [$onSimilarSnapshot->getId(), $onSameHashAgain->getId(), $onNearSnapshot->getId()],
            array_map(static fn (Detection $detection): ?int => $detection->getId(), $groupedBySize[0]),
            'Snapshots within the Hamming distance threshold (including two detections on equally-hashed snapshots) group together.',
        );
        self::assertSame(
            [$onFarSnapshot->getId()],
            array_map(static fn (Detection $detection): ?int => $detection->getId(), $groupedBySize[1]),
            'A snapshot far beyond the threshold forms its own group.',
        );
    }

    public function testSnapshotWithoutHashFormsItsOwnGroup(): void
    {
        $this->bootDatabase();

        $hashed = $this->createDetectionWithHash(self::SIMILAR_HASH);
        $unhashed = $this->createDetectionWithHash(null);

        $groups = $this->detectionRepository()->similarSnapshotGroups(
            new DetectionFilter(null, null, null, null, null),
            20,
            0,
        );

        self::assertCount(2, $groups, 'An unhashable snapshot never joins a similarity group.');
        self::assertCount(1, $groups[0]);
        self::assertCount(1, $groups[1]);
    }

    public function testGroupingNeverDeletesMergesOrModifiesRecords(): void
    {
        $this->bootDatabase();

        $this->createDetectionWithHash(self::SIMILAR_HASH);
        $this->createDetectionWithHash(self::SIMILAR_HASH);
        $this->createDetectionWithHash(self::FAR_HASH);

        $entityManager = $this->entityManager();
        $countsBefore = $this->entityCounts();
        $snapshotsBefore = array_map(
            static fn (Snapshot $snapshot): ?string => $snapshot->getPerceptualHash(),
            $entityManager->getRepository(Snapshot::class)->findAll(),
        );

        $groups = $this->detectionRepository()->similarSnapshotGroups(
            new DetectionFilter(null, null, null, null, null),
            20,
            0,
            new SimilarSnapshotGrouper(maxHammingDistance: 8),
        );

        self::assertNotSame([], $groups);

        // Grouping must be pure presentation: identical record counts, and
        // every snapshot still there with its hash untouched.
        self::assertSame($countsBefore, $this->entityCounts());
        self::assertSame(
            $snapshotsBefore,
            array_map(
                static fn (Snapshot $snapshot): ?string => $snapshot->getPerceptualHash(),
                $entityManager->getRepository(Snapshot::class)->findAll(),
            ),
        );
    }

    public function testGrouperDoesNotFlushOrPersistAnything(): void
    {
        $this->bootDatabase();

        $this->createDetectionWithHash(self::SIMILAR_HASH);
        $this->createDetectionWithHash(self::SIMILAR_HASH);

        $entityManager = $this->entityManager();
        $entityManager->clear();

        $detections = $entityManager->getRepository(Detection::class)->findAll();
        $grouper = new SimilarSnapshotGrouper();

        $groups = $grouper->group($detections);

        self::assertCount(1, $groups, 'Two detections on equally-hashed snapshots form one group.');
        self::assertCount(2, $groups[0]);
        self::assertCount(0, $entityManager->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertCount(0, $entityManager->getUnitOfWork()->getScheduledEntityUpdates());
    }

    private function createDetectionWithHash(?string $perceptualHash): Detection
    {
        $snapshot = new Snapshot(
            hash('sha256', bin2hex(random_bytes(16))),
            '/snapshots/'.bin2hex(random_bytes(8)).'.jpg',
        );

        if ($perceptualHash !== null) {
            $snapshot->setPerceptualHash($perceptualHash);
        }

        $detection = Detection::fromFrigate(
            new FrigateEvent(
                'event-'.bin2hex(random_bytes(8)),
                'front_door',
                new \DateTimeImmutable('2026-11-12 10:00:00'),
            ),
            $snapshot,
            'person',
            0.87,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
            new \DateTimeImmutable('2026-11-12 10:00:05'),
            new BoundingBox(0.1, 0.2, 0.4, 0.5),
        );

        $this->persist($snapshot, $detection);

        return $detection;
    }

    private function detectionRepository(): DetectionRepository
    {
        return new DetectionRepository($this->entityManager());
    }

    /**
     * @return array<string, int>
     */
    private function entityCounts(): array
    {
        $entityManager = $this->entityManager();

        return [
            'snapshots' => \count($entityManager->getRepository(Snapshot::class)->findAll()),
            'detections' => \count($entityManager->getRepository(Detection::class)->findAll()),
        ];
    }
}
