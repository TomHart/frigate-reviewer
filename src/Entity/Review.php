<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\BoundingBox;
use App\Domain\FrigateSourceClasses;
use App\Enum\DetectionOrigin;
use App\Enum\ReviewValidity;
use Doctrine\ORM\Mapping as ORM;

/**
 * The human annotation of one detection (design.md D3). Validity and identity
 * are independent fields; validity defaults to Unreviewed. The corrected
 * bounding box lives here, separate from the original Frigate box on Detection.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['validity'], name: 'idx_review_validity')]
class Review
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Detection::class, inversedBy: 'review')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Detection $detection;

    #[ORM\Column(enumType: ReviewValidity::class)]
    private ReviewValidity $validity = ReviewValidity::Unreviewed;

    #[ORM\ManyToOne(targetEntity: Label::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Label $identityLabel = null;

    /** Reviewer-corrected bounding box, stored as four columns for portability and queryability. */
    #[ORM\Column(nullable: true)]
    private ?float $correctedX = null;

    #[ORM\Column(nullable: true)]
    private ?float $correctedY = null;

    #[ORM\Column(nullable: true)]
    private ?float $correctedWidth = null;

    #[ORM\Column(nullable: true)]
    private ?float $correctedHeight = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $falsePositiveReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewerNotes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @internal Use {@see \App\Domain\ReviewService}, which get-or-creates
     * review rows so every write path goes through the domain rules.
     */
    public function __construct(Detection $detection)
    {
        $this->detection = $detection;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $detection->setReview($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDetection(): Detection
    {
        return $this->detection;
    }

    public function getValidity(): ReviewValidity
    {
        return $this->validity;
    }

    public function setValidity(ReviewValidity $validity): void
    {
        $this->validity = $validity;

        // Spec: "setting validity false positive SHALL clear identity" — a
        // false positive must never carry a positive identity classification.
        if ($validity === ReviewValidity::FalsePositive) {
            $this->identityLabel = null;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getIdentityLabel(): ?Label
    {
        return $this->identityLabel;
    }

    public function setIdentityLabel(?Label $identityLabel): void
    {
        if ($identityLabel !== null && $this->validity === ReviewValidity::FalsePositive) {
            throw new \DomainException('A false positive cannot be assigned an identity; set a different validity first.');
        }

        if ($identityLabel !== null) {
            $this->assertLabelKindMatchesSourceClass($identityLabel);
        }

        $this->identityLabel = $identityLabel;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCorrectedBox(): ?BoundingBox
    {
        if ($this->correctedX === null || $this->correctedY === null || $this->correctedWidth === null || $this->correctedHeight === null) {
            return null;
        }

        return new BoundingBox($this->correctedX, $this->correctedY, $this->correctedWidth, $this->correctedHeight);
    }

    public function setCorrectedBox(?BoundingBox $box): void
    {
        if ($box === null) {
            $this->correctedX = null;
            $this->correctedY = null;
            $this->correctedWidth = null;
            $this->correctedHeight = null;
        } else {
            $this->correctedX = $box->x;
            $this->correctedY = $box->y;
            $this->correctedWidth = $box->width;
            $this->correctedHeight = $box->height;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFalsePositiveReason(): ?string
    {
        return $this->falsePositiveReason;
    }

    public function setFalsePositiveReason(?string $falsePositiveReason): void
    {
        $this->falsePositiveReason = $falsePositiveReason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getReviewerNotes(): ?string
    {
        return $this->reviewerNotes;
    }

    public function setReviewerNotes(?string $reviewerNotes): void
    {
        $this->reviewerNotes = $reviewerNotes;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Spec: "Kind constrains source classes" — a person-kind label may only
     * be applied to person-class Frigate detections, a dog-kind label to
     * dog-class detections. Manual objects accept any kind (design.md D5).
     */
    private function assertLabelKindMatchesSourceClass(Label $label): void
    {
        if ($this->detection->getOrigin() === DetectionOrigin::Manual) {
            return;
        }

        $sourceClass = $this->detection->getSourceClass();
        $acceptedKind = $sourceClass === null ? null : FrigateSourceClasses::acceptedKind($sourceClass);

        if ($acceptedKind === null) {
            throw new \DomainException(sprintf(
                'Frigate source class "%s" does not accept any label kind; only manual objects accept any kind.',
                $sourceClass ?? '(none)',
            ));
        }

        if ($acceptedKind !== $label->getKind()) {
            throw new \DomainException(sprintf(
                'A %s-kind label cannot be applied to a Frigate detection with source class "%s".',
                $label->getKind()->value,
                $sourceClass,
            ));
        }
    }
}
