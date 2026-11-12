<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Every dataset-quality metric of the dashboard (spec "Dataset quality
 * dashboard"), computed by {@see \App\Repository\DashboardRepository} and
 * rendered by the dashboard page. Plain data: no behaviour beyond the
 * rate derived on {@see CameraCoverage}.
 */
final readonly class DashboardStats
{
    /**
     * @param list<CameraCoverage>   $cameraCoverage   per camera, ordered by camera name
     * @param list<IdentityCount>    $identityCounts   labelled identities most-used first, "Unidentified" last
     * @param list<ReasonCount>      $falsePositiveReasons most frequent first
     * @param list<ConfidenceBucket> $confidenceBuckets exactly ten 0.1-wide buckets, low to high
     * @param list<DayCount>         $detectionsPerDay days with detections, oldest first
     */
    public function __construct(
        public array $cameraCoverage,
        public array $identityCounts,
        public array $falsePositiveReasons,
        public array $confidenceBuckets,
        public array $detectionsPerDay,
        public int $totalDetections,
        public int $correctedBoundingBoxes,
        public int $distinctFrigateEvents,
    ) {
    }
}
