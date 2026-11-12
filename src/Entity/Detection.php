<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\BoundingBox;
use App\Enum\DetectionOrigin;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reviewed object. Frigate-originated objects carry their Frigate facts
 * (source class, confidence, timestamps, original bounding box); manual
 * objects have null Frigate facts (design.md D3). The original Frigate box
 * stays here; the corrected box lives on Review.
 */
#[ORM\Entity]
class Detection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FrigateEvent::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'frigate_event_id', nullable: true)]
    private ?FrigateEvent $event = null;

    #[ORM\ManyToOne(targetEntity: Snapshot::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?Snapshot $snapshot = null;

    #[ORM\Column(enumType: DetectionOrigin::class)]
    private DetectionOrigin $origin;

    #[ORM\Column(nullable: true)]
    private ?string $sourceClass = null;

    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    /** Original Frigate bounding box, stored as four columns for portability and queryability. */
    #[ORM\Column(nullable: true)]
    private ?float $boxX = null;

    #[ORM\Column(nullable: true)]
    private ?float $boxY = null;

    #[ORM\Column(nullable: true)]
    private ?float $boxWidth = null;

    #[ORM\Column(nullable: true)]
    private ?float $boxHeight = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(targetEntity: Review::class, mappedBy: 'detection')]
    private ?Review $review = null;

    public static function fromFrigate(
        FrigateEvent $event,
        Snapshot $snapshot,
        string $sourceClass,
        ?float $confidence,
        ?\DateTimeImmutable $startTime,
        ?\DateTimeImmutable $endTime,
        ?BoundingBox $originalBox,
    ): static {
        $detection = new static();
        $detection->origin = DetectionOrigin::Frigate;
        $detection->event = $event;
        $detection->snapshot = $snapshot;
        $detection->sourceClass = $sourceClass;
        $detection->confidence = $confidence;
        $detection->startTime = $startTime;
        $detection->endTime = $endTime;
        $detection->setOriginalBox($originalBox);

        return $detection;
    }

    public static function manual(Snapshot $snapshot, ?BoundingBox $box, ?FrigateEvent $event = null): static
    {
        $detection = new static();
        $detection->origin = DetectionOrigin::Manual;
        $detection->event = $event;
        $detection->snapshot = $snapshot;
        $detection->setOriginalBox($box);

        return $detection;
    }

    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?FrigateEvent
    {
        return $this->event;
    }

    public function getSnapshot(): ?Snapshot
    {
        return $this->snapshot;
    }

    public function getOrigin(): DetectionOrigin
    {
        return $this->origin;
    }

    public function getSourceClass(): ?string
    {
        return $this->sourceClass;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function getOriginalBox(): ?BoundingBox
    {
        if ($this->boxX === null || $this->boxY === null || $this->boxWidth === null || $this->boxHeight === null) {
            return null;
        }

        return new BoundingBox($this->boxX, $this->boxY, $this->boxWidth, $this->boxHeight);
    }

    /**
     * Private: the original Frigate bounding box is an immutable fact set
     * once by {@see fromFrigate()} / {@see manual()} at creation time. No
     * review path may ever rewrite it (design.md D3).
     */
    private function setOriginalBox(?BoundingBox $box): void
    {
        if ($box === null) {
            $this->boxX = null;
            $this->boxY = null;
            $this->boxWidth = null;
            $this->boxHeight = null;

            return;
        }

        $this->boxX = $box->x;
        $this->boxY = $box->y;
        $this->boxWidth = $box->width;
        $this->boxHeight = $box->height;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReview(): ?Review
    {
        return $this->review;
    }

    /** @internal Called by {@see Review::__construct()} to keep the inverse side in sync. */
    public function setReview(?Review $review): void
    {
        $this->review = $review;
    }
}
