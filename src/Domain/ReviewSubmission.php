<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Label;
use App\Enum\ReviewValidity;

/**
 * One complete review form submission for a detection (spec "Per-object
 * review"). The review form always submits every field, so applying a
 * submission overwrites each field with the submitted value — including
 * clearing identity, reason or notes.
 *
 * The corrected box is three-state: {@see $correctedBoxProvided} distinguishes
 * "no box submitted" (leave the stored box untouched) from "box cleared"
 * ($correctedBox null, provided true) and "box set" (provided true).
 */
final readonly class ReviewSubmission
{
    public function __construct(
        public ReviewValidity $validity,
        public ?Label $identityLabel,
        public ?BoundingBox $correctedBox,
        public bool $correctedBoxProvided,
        public ?string $falsePositiveReason,
        public ?string $reviewerNotes,
    ) {
    }

    /**
     * A false positive never carries a positive identity classification
     * (design.md D7): the domain layer clears identity, so a submitted
     * identity is ignored when validity is false positive.
     */
    public function identityApplicable(): ?Label
    {
        return $this->validity === ReviewValidity::FalsePositive ? null : $this->identityLabel;
    }
}
