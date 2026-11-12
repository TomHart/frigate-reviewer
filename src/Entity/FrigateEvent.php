<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable metadata synced from a Frigate event (design.md D3). Nothing in
 * the review path ever writes to this table.
 */
#[ORM\Entity]
class FrigateEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Frigate's own event identifier; the idempotency key for sync. */
    #[ORM\Column(unique: true)]
    private string $frigateEventId;

    #[ORM\Column]
    private string $camera;

    #[ORM\Column]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(nullable: true)]
    private ?string $clipPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $frigateEventId,
        string $camera,
        \DateTimeImmutable $startTime,
        ?\DateTimeImmutable $endTime = null,
        ?string $clipPath = null,
    ) {
        $this->frigateEventId = $frigateEventId;
        $this->camera = $camera;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->clipPath = $clipPath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFrigateEventId(): string
    {
        return $this->frigateEventId;
    }

    public function getCamera(): string
    {
        return $this->camera;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function getClipPath(): ?string
    {
        return $this->clipPath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
