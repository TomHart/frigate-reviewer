<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Dashboard\CameraCoverage;
use App\Domain\Dashboard\ConfidenceBucket;
use App\Domain\Dashboard\DashboardStats;
use App\Domain\Dashboard\DayCount;
use App\Domain\Dashboard\IdentityCount;
use App\Domain\Dashboard\ReasonCount;
use App\Entity\Detection;
use App\Entity\Review;
use App\Enum\ReviewValidity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side queries behind the dataset-quality dashboard (spec "Dataset
 * quality dashboard"). All queries are portable DQL (design.md D2); grouping
 * and bucketing happen in pure functions on scalar results, so no
 * platform-specific SQL functions are used. This repository only reads.
 */
final class DashboardRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function stats(): DashboardStats
    {
        return new DashboardStats(
            cameraCoverage: $this->cameraCoverage(),
            identityCounts: $this->identityCounts(),
            falsePositiveReasons: $this->falsePositiveReasons(),
            confidenceBuckets: $this->confidenceBuckets(),
            detectionsPerDay: $this->detectionsPerDay(),
            totalDetections: $this->totalDetections(),
            correctedBoundingBoxes: $this->correctedBoundingBoxCount(),
            distinctFrigateEvents: $this->distinctFrigateEventCount(),
        );
    }

    /**
     * Reviewed/unreviewed/false-positive counts per camera. "Reviewed" is
     * validity other than unreviewed (App\Enum\ReviewState), so
     * reviewed = total - unreviewed; the false-positive rate on the page
     * divides by the reviewed count (see CameraCoverage).
     *
     * @return list<CameraCoverage>
     */
    private function cameraCoverage(): array
    {
        $totals = $this->countsPerCamera(null);
        $unreviewed = $this->countsPerCamera(ReviewValidity::Unreviewed);
        $falsePositives = $this->countsPerCamera(ReviewValidity::FalsePositive);

        $cameras = array_unique(array_merge(
            array_keys($totals),
            array_keys($unreviewed),
            array_keys($falsePositives),
        ));
        sort($cameras);

        $coverage = [];
        foreach ($cameras as $camera) {
            $unreviewedCount = $unreviewed[$camera] ?? 0;
            $coverage[] = new CameraCoverage(
                camera: $camera,
                reviewedCount: ($totals[$camera] ?? 0) - $unreviewedCount,
                unreviewedCount: $unreviewedCount,
                falsePositiveCount: $falsePositives[$camera] ?? 0,
            );
        }

        return $coverage;
    }

    /**
     * Detection count per camera; with a validity, only detections whose
     * review carries that validity (unreviewed includes objects without any
     * review row yet). "No camera" buckets eventless manual objects.
     *
     * @return array<string, int>
     */
    private function countsPerCamera(?ReviewValidity $validity): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e.camera AS camera', 'COUNT(d.id) AS total')
            ->from(Detection::class, 'd')
            ->leftJoin('d.event', 'e')
            ->leftJoin('d.review', 'r')
            ->groupBy('e.camera');

        if ($validity === ReviewValidity::Unreviewed) {
            $queryBuilder
                ->where('r.id IS NULL OR r.validity = :validity')
                ->setParameter('validity', $validity);
        } elseif ($validity !== null) {
            $queryBuilder
                ->where('r.validity = :validity')
                ->setParameter('validity', $validity);
        }

        return $this->countsByFirstColumn($queryBuilder->getQuery()->getArrayResult());
    }

    /**
     * Counts per identity class over true-positive and unsure reviews, most
     * used first. "Unidentified" counts the same population with no identity
     * label assigned; a false positive never appears here because the domain
     * rule clears identity on false positives (design.md D7).
     *
     * @return list<IdentityCount>
     */
    private function identityCounts(): array
    {
        $positiveValidity = 'r.validity = :truePositive OR r.validity = :unsure';

        $labelled = $this->countsByFirstColumn(
            $this->entityManager->createQueryBuilder()
                ->select('l.name AS label', 'COUNT(r.id) AS total')
                ->from(Review::class, 'r')
                ->join('r.identityLabel', 'l')
                ->where($positiveValidity)
                ->setParameter('truePositive', ReviewValidity::TruePositive)
                ->setParameter('unsure', ReviewValidity::Unsure)
                ->groupBy('l.name')
                ->getQuery()
                ->getArrayResult(),
        );

        $unidentified = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Review::class, 'r')
            ->where($positiveValidity)
            ->andWhere('r.identityLabel IS NULL')
            ->setParameter('truePositive', ReviewValidity::TruePositive)
            ->setParameter('unsure', ReviewValidity::Unsure)
            ->getQuery()
            ->getSingleScalarResult();

        $counts = [];
        foreach ($labelled as $name => $count) {
            $counts[] = new IdentityCount($name, $count);
        }
        usort($counts, static fn (IdentityCount $a, IdentityCount $b) => $b->count <=> $a->count
            ?: strcasecmp($a->name, $b->name));

        $counts[] = new IdentityCount('Unidentified', $unidentified, unidentified: true);

        return $counts;
    }

    /**
     * Breakdown of false-positive reasons, most frequent first; reasons are
     * the reviewer-entered free text (design.md D10), so identical spellings
     * group together.
     *
     * @return list<ReasonCount>
     */
    private function falsePositiveReasons(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.falsePositiveReason AS reason', 'COUNT(r.id) AS total')
            ->from(Review::class, 'r')
            ->where('r.validity = :falsePositive')
            ->andWhere('r.falsePositiveReason IS NOT NULL')
            ->setParameter('falsePositive', ReviewValidity::FalsePositive)
            ->groupBy('r.falsePositiveReason')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[] = new ReasonCount((string) $row['reason'], (int) $row['total']);
        }
        usort($counts, static fn (ReasonCount $a, ReasonCount $b) => $b->count <=> $a->count
            ?: strcasecmp($a->reason, $b->reason));

        return $counts;
    }

    /**
     * Confidence distribution over every detection Frigate reported a
     * confidence for, in ten 0.1-wide buckets (last one closed at 1.0).
     *
     * @return list<ConfidenceBucket>
     */
    private function confidenceBuckets(): array
    {
        $confidences = $this->entityManager->createQueryBuilder()
            ->select('d.confidence')
            ->from(Detection::class, 'd')
            ->where('d.confidence IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        $counts = array_fill(0, 10, 0);
        foreach ($confidences as $confidence) {
            $bucket = (int) floor(((float) $confidence) * 10);
            ++$counts[max(0, min(9, $bucket))];
        }

        $buckets = [];
        foreach ($counts as $index => $count) {
            $buckets[] = new ConfidenceBucket(self::bucketLabel($index), $count);
        }

        return $buckets;
    }

    private static function bucketLabel(int $index): string
    {
        return sprintf('%.1f–%.1f', $index / 10, ($index + 1) / 10);
    }

    /**
     * Detection counts per day of the event's start time, oldest first.
     * Start times arrive as raw scalar column values (platform-dependent:
     * string on SQLite/PDO), so the day is parsed at this one boundary.
     *
     * @return list<DayCount>
     */
    private function detectionsPerDay(): array
    {
        $startTimes = $this->entityManager->createQueryBuilder()
            ->select('e.startTime')
            ->from(Detection::class, 'd')
            ->join('d.event', 'e')
            ->where('d.event IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        $counts = [];
        foreach ($startTimes as $startTime) {
            $day = $startTime instanceof \DateTimeInterface
                ? $startTime->format('Y-m-d')
                : substr((string) $startTime, 0, 10);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        ksort($counts);

        $perDay = [];
        foreach ($counts as $day => $count) {
            $perDay[] = new DayCount($day, $count);
        }

        return $perDay;
    }

    private function totalDetections(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Detection::class, 'd')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Reviews with a reviewer-corrected bounding box (corrected box lives on
     * Review, separate from the immutable original Frigate box).
     */
    private function correctedBoundingBoxCount(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Review::class, 'r')
            ->where('r.correctedX IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Distinct Frigate events that have at least one detection.
     */
    private function distinctFrigateEventCount(): int
    {
        return count($this->entityManager->createQueryBuilder()
            ->select('DISTINCT e.id')
            ->from(Detection::class, 'd')
            ->join('d.event', 'e')
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Turns "SELECT thing AS ..., COUNT(...) AS total" rows into a
     * thing => count map.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function countsByFirstColumn(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = (string) reset($row);
            $counts[$key] = (int) end($row);
        }

        return $counts;
    }
}
