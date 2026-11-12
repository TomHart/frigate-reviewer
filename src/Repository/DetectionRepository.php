<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\SimilarSnapshotGrouper;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Review;
use App\Entity\Snapshot;
use App\Enum\ReviewState;
use App\Enum\ReviewValidity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Read-side queries over detections: the filtered, paginated listing, the
 * event grouping view, distinct filter options, and false-positive reason
 * suggestions aggregated by usage (design.md D10). All review writes go
 * through the domain layer; this repository only reads.
 */
final class DetectionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Detection>
     */
    public function filter(DetectionFilter $filter, int $limit, int $offset): array
    {
        return $this->filteredQueryBuilder($filter)
            ->orderBy('e.startTime', 'DESC')
            ->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(DetectionFilter $filter): int
    {
        $count = $this->filteredQueryBuilder($filter)
            ->select('COUNT(d.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * The filtered detections of one page, arranged into groups of visually
     * similar snapshots (design.md D8, spec "Grouping and bulk
     * classification"). Grouping happens in PHP over the page's perceptual
     * hashes — O(k²) with k at most the page size, no SQL bit operations —
     * and is presentation-only: nothing is ever merged, discarded or deleted.
     *
     * @return list<list<Detection>>
     */
    public function similarSnapshotGroups(
        DetectionFilter $filter,
        int $limit,
        int $offset,
        SimilarSnapshotGrouper $grouper = new SimilarSnapshotGrouper(),
    ): array {
        return $grouper->group($this->filter($filter, $limit, $offset));
    }

    public function eventByFrigateEventId(string $frigateEventId): ?FrigateEvent
    {
        $event = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(FrigateEvent::class, 'e')
            ->where('e.frigateEventId = :frigateEventId')
            ->setParameter('frigateEventId', $frigateEventId)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($event === null || $event instanceof FrigateEvent);

        return $event;
    }

    /** @return list<Detection> */
    public function detectionsForEvent(FrigateEvent $event): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('d', 'r')
            ->from(Detection::class, 'd')
            ->leftJoin('d.review', 'r')
            ->where('d.event = :event')
            ->setParameter('event', $event)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The shared snapshot of an event (all of its detections reference it).
     */
    public function firstSnapshotForEvent(FrigateEvent $event): ?Snapshot
    {
        $detections = $this->entityManager->createQueryBuilder()
            ->select('d', 's')
            ->from(Detection::class, 'd')
            ->join('d.snapshot', 's')
            ->where('d.event = :event')
            ->setParameter('event', $event)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $first = $detections[0] ?? null;
        \assert($first === null || $first instanceof Detection);

        return $first?->getSnapshot();
    }

    /**
     * Every detection that references a snapshot, oldest first. Deletion
     * (design.md D11) needs the full picture: the image is content-addressed
     * and shared, so all referencing objects go together.
     *
     * @return list<Detection>
     */
    public function detectionsReferencingSnapshot(Snapshot $snapshot): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Detection::class, 'd')
            ->where('d.snapshot = :snapshot')
            ->setParameter('snapshot', $snapshot)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** How many detections still reference an event. */
    public function countDetectionsForEvent(FrigateEvent $event): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Detection::class, 'd')
            ->where('d.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /** @return list<string> */
    public function distinctCameras(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT e.camera')
            ->from(FrigateEvent::class, 'e')
            ->orderBy('e.camera', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** @return list<string> */
    public function distinctSourceClasses(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT d.sourceClass')
            ->from(Detection::class, 'd')
            ->where('d.sourceClass IS NOT NULL')
            ->orderBy('d.sourceClass', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Distinct previously used false-positive reasons, most used first
     * (design.md D10). An optional query filters by substring; new values are
     * never constrained by this list.
     *
     * @return list<array{reason: string, usage: int}>
     */
    public function falsePositiveReasonSuggestions(?string $query, int $limit = 20): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('r.falsePositiveReason AS reason', 'COUNT(r.id) AS usageCount')
            ->from(Review::class, 'r')
            ->where('r.falsePositiveReason IS NOT NULL')
            ->andWhere('r.falsePositiveReason <> :empty')
            ->setParameter('empty', '')
            ->groupBy('r.falsePositiveReason')
            ->orderBy('usageCount', 'DESC')
            ->addOrderBy('reason', 'ASC')
            ->setMaxResults($limit);

        if ($query !== null) {
            $queryBuilder
                ->andWhere('r.falsePositiveReason LIKE :query')
                ->setParameter('query', '%'.addcslashes($query, '%_').'%');
        }

        return array_map(
            static fn (array $row): array => ['reason' => (string) $row['reason'], 'usage' => (int) $row['usageCount']],
            $queryBuilder->getQuery()->getResult(),
        );
    }

    private function filteredQueryBuilder(DetectionFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('d', 'e', 'r')
            ->from(Detection::class, 'd')
            ->leftJoin('d.event', 'e')
            ->leftJoin('d.review', 'r');

        if ($filter->camera !== null) {
            $queryBuilder
                ->andWhere('e.camera = :camera')
                ->setParameter('camera', $filter->camera);
        }

        if ($filter->day !== null) {
            $queryBuilder
                ->andWhere('e.startTime >= :dayStart')
                ->andWhere('e.startTime < :dayEnd')
                ->setParameter('dayStart', $filter->day)
                ->setParameter('dayEnd', $filter->day->modify('+1 day'));
        }

        if ($filter->validity !== null) {
            $queryBuilder
                ->andWhere('r.validity = :validity')
                ->setParameter('validity', $filter->validity);
        }

        if ($filter->sourceClass !== null) {
            $queryBuilder
                ->andWhere('d.sourceClass = :sourceClass')
                ->setParameter('sourceClass', $filter->sourceClass);
        }

        if ($filter->reviewState !== null) {
            match ($filter->reviewState) {
                ReviewState::Reviewed => $queryBuilder
                    ->andWhere('r.id IS NOT NULL')
                    ->andWhere('r.validity <> :unreviewed')
                    ->setParameter('unreviewed', ReviewValidity::Unreviewed),
                ReviewState::Unreviewed => $queryBuilder
                    ->andWhere($queryBuilder->expr()->orX(
                        'r.id IS NULL',
                        'r.validity = :unreviewed',
                    ))
                    ->setParameter('unreviewed', ReviewValidity::Unreviewed),
            };
        }

        return $queryBuilder;
    }
}
