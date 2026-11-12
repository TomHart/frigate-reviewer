<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * One 0.1-wide confidence bucket and the number of Frigate detections whose
 * confidence falls inside it (spec "Dataset quality dashboard"). The label
 * is the bucket's range, e.g. "0.3–0.4"; the last bucket is closed at 1.0.
 */
final readonly class ConfidenceBucket
{
    public function __construct(
        public string $label,
        public int $count,
    ) {
    }
}
