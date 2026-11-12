<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Review coverage for one camera (spec "Dataset quality dashboard").
 *
 * "Reviewed" means the detection's validity is anything other than
 * unreviewed (same semantics as App\Enum\ReviewState). The false-positive
 * rate's denominator is the reviewed count, so it answers "of the objects
 * a reviewer has judged, how many were false positives?" — null when
 * nothing has been reviewed yet on that camera.
 */
final readonly class CameraCoverage
{
    public function __construct(
        public string $camera,
        public int $reviewedCount,
        public int $unreviewedCount,
        public int $falsePositiveCount,
    ) {
    }

    public function totalCount(): int
    {
        return $this->reviewedCount + $this->unreviewedCount;
    }

    public function falsePositiveRate(): ?float
    {
        if ($this->reviewedCount === 0) {
            return null;
        }

        return round($this->falsePositiveCount / $this->reviewedCount * 100, 1);
    }
}
