<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Label;
use App\Entity\Review;
use App\Entity\Snapshot;
use App\Enum\ReviewValidity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single write path for review data, so the domain rules (false positive
 * clears identity; label kind constrains Frigate source classes) are honoured
 * no matter who calls in (design.md D5, D7).
 */
final class ReviewService
{
    private readonly AuditTrail $auditTrail;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?AuditTrail $auditTrail = null,
    ) {
        $this->auditTrail = $auditTrail ?? new AuditTrail($entityManager);
    }

    /** Get-or-create the review row of a detection. A fresh review defaults to unreviewed. */
    public function reviewFor(Detection $detection): Review
    {
        $review = $detection->getReview();

        if ($review !== null) {
            return $review;
        }

        $review = new Review($detection);
        $this->entityManager->persist($review);
        $this->entityManager->flush();

        return $review;
    }

    public function setValidity(
        Detection $detection,
        ReviewValidity $validity,
        string $reviewer = AuditTrail::DEFAULT_REVIEWER,
    ): Review {
        return $this->mutate($detection, static function (Review $review) use ($validity): void {
            $review->setValidity($validity);
        }, $reviewer);
    }

    public function setIdentity(
        Detection $detection,
        ?Label $identityLabel,
        string $reviewer = AuditTrail::DEFAULT_REVIEWER,
    ): Review {
        return $this->mutate($detection, static function (Review $review) use ($identityLabel): void {
            $review->setIdentityLabel($identityLabel);
        }, $reviewer);
    }

    public function setCorrectedBox(
        Detection $detection,
        ?BoundingBox $correctedBox,
        string $reviewer = AuditTrail::DEFAULT_REVIEWER,
    ): Review {
        return $this->mutate($detection, static function (Review $review) use ($correctedBox): void {
            $review->setCorrectedBox($correctedBox);
        }, $reviewer);
    }

    /**
     * One-click positive classification (spec "Detection listing" speed
     * flow): confirm the object as a true positive carrying exactly this
     * identity label, validity and identity in the same audited transaction.
     * Everything else already recorded on the review (corrected box,
     * false-positive reason, notes) is left untouched — this mutation is
     * deliberately narrower than a full form submission.
     */
    public function confirmIdentity(
        Detection $detection,
        Label $identityLabel,
        string $reviewer = AuditTrail::DEFAULT_REVIEWER,
    ): Review {
        return $this->mutate($detection, static function (Review $review) use ($identityLabel): void {
            // Validity first: the identity setter refuses to attach to a
            // false positive, so confirm before classifying.
            $review->setValidity(ReviewValidity::TruePositive);
            $review->setIdentityLabel($identityLabel);
        }, $reviewer);
    }

    /**
     * Apply a full review form submission: the single write path used by the
     * review UI. Every submitted field is applied (the form always submits
     * all fields), changed fields are recorded in the audit trail inside the
     * same transaction, and everything commits or rolls back together.
     */
    public function save(Detection $detection, ReviewSubmission $submission, string $reviewer = AuditTrail::DEFAULT_REVIEWER): Review
    {
        return $this->mutate($detection, static function (Review $review) use ($submission): void {
            $review->setValidity($submission->validity);
            $review->setIdentityLabel($submission->identityApplicable());

            if ($submission->correctedBoxProvided) {
                $review->setCorrectedBox($submission->correctedBox);
            }

            $review->setFalsePositiveReason($submission->falsePositiveReason);
            $review->setReviewerNotes($submission->reviewerNotes);
        }, $reviewer);
    }

    /**
     * Every review mutation funnels through here: get-or-create the review
     * row, apply the change, and record one audit entry per changed field in
     * the same transaction as the change itself (design.md D9).
     *
     * @param \Closure(Review): void $apply
     */
    private function mutate(Detection $detection, \Closure $apply, string $reviewer): Review
    {
        return $this->entityManager->wrapInTransaction(function () use ($detection, $apply, $reviewer): Review {
            $review = $this->reviewFor($detection);
            $before = self::snapshot($review);

            $apply($review);

            $this->auditTrail->recordChanges($detection, $before, self::snapshot($review), $reviewer);
            $this->entityManager->flush();

            return $review;
        });
    }

    /**
     * Bulk classification over a group selection (design.md D8, spec "Bulk
     * classification applies to group"): validity and false-positive reason
     * are applied to every given detection in one transaction, and each
     * object records its own audit entries for exactly the fields that
     * changed on it (design.md D9). Nothing else on a review is touched.
     *
     * A false positive never carries a positive identity classification
     * (design.md D7), so bulk-classifying as false positive clears identity.
     *
     * @param list<Detection> $detections
     *
     * @return int the number of objects classified
     */
    public function bulkClassify(
        array $detections,
        ReviewValidity $validity,
        ?string $falsePositiveReason,
        string $reviewer = AuditTrail::DEFAULT_REVIEWER,
    ): int {
        if ($detections === []) {
            throw new \InvalidArgumentException('Bulk classification requires at least one detection.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($detections, $validity, $falsePositiveReason, $reviewer): int {
            foreach ($detections as $detection) {
                $review = $this->reviewFor($detection);
                $before = self::snapshot($review);

                $review->setValidity($validity);
                $review->setIdentityLabel(
                    $validity === ReviewValidity::FalsePositive ? null : $review->getIdentityLabel(),
                );
                $review->setFalsePositiveReason($falsePositiveReason);

                $this->auditTrail->recordChanges($detection, $before, self::snapshot($review), $reviewer);
            }

            $this->entityManager->flush();

            return \count($detections);
        });
    }

    /**
     * A reviewer-drawn object on an existing event's snapshot: origin manual,
     * null Frigate facts, and (lazily) default validity unreviewed — the
     * spec "Manual objects added by reviewer".
     */
    public function createManualObject(FrigateEvent $event, Snapshot $snapshot, BoundingBox $box): Detection
    {
        $detection = Detection::manual($snapshot, $box, $event);
        $this->entityManager->persist($detection);
        $this->entityManager->flush();

        return $detection;
    }

    /**
     * Human-readable field values for audit diffs: identity by label name,
     * boxes as "x,y,width,height".
     *
     * @return array<string, ?string>
     */
    private static function snapshot(Review $review): array
    {
        $correctedBox = $review->getCorrectedBox();

        return [
            'validity' => $review->getValidity()->value,
            'identity' => $review->getIdentityLabel()?->getName(),
            'corrected_box' => $correctedBox === null
                ? null
                : sprintf('%.6f,%.6f,%.6f,%.6f', $correctedBox->x, $correctedBox->y, $correctedBox->width, $correctedBox->height),
            'false_positive_reason' => $review->getFalsePositiveReason(),
            'reviewer_notes' => $review->getReviewerNotes(),
        ];
    }
}
