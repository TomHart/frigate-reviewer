<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Count of reviewed objects carrying one identity class (spec "Dataset
 * quality dashboard"). Only true-positive and unsure objects count: a
 * false positive never carries a positive identity classification
 * (design.md D7). {@see $unidentified} marks the "no identity assigned"
 * bucket, counted over the same true-positive/unsure population.
 */
final readonly class IdentityCount
{
    public function __construct(
        public string $name,
        public int $count,
        public bool $unidentified = false,
    ) {
    }
}
