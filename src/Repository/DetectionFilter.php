<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ReviewState;
use App\Enum\ReviewValidity;

/**
 * Parsed listing filters (spec "Detection listing"): every field is either a
 * trusted parsed value or null (no filter). Parsed in the controller from the
 * query string.
 */
final readonly class DetectionFilter
{
    public function __construct(
        public ?string $camera,
        public ?\DateTimeImmutable $day,
        public ?ReviewValidity $validity,
        public ?string $sourceClass,
        public ?ReviewState $reviewState,
    ) {
    }
}
