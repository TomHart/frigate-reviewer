<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Number of detections whose Frigate event started on one day, "YYYY-MM-DD"
 * (spec "Dataset quality dashboard"). Days with zero detections are omitted;
 * days are ordered oldest first.
 */
final readonly class DayCount
{
    public function __construct(
        public string $day,
        public int $count,
    ) {
    }
}
