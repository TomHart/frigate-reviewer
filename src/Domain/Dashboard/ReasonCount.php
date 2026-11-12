<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * One false-positive reason and how many false-positive objects carry it
 * (spec "Dataset quality dashboard"). Ordered most frequent first.
 */
final readonly class ReasonCount
{
    public function __construct(
        public string $reason,
        public int $count,
    ) {
    }
}
