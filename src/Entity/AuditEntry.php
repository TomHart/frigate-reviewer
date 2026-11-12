<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit trail for classification changes and bounding-box edits
 * (design.md D9). One row per changed field, written in the same transaction
 * as the change (writing entries is implemented in the audit task).
 */
#[ORM\Entity]
#[ORM\Index(columns: ['detection_id'], name: 'idx_audit_entry_detection')]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The reviewed object this entry describes. */
    #[ORM\ManyToOne(targetEntity: Detection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Detection $detection;

    #[ORM\Column]
    private string $who;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column]
    private string $field;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $previousValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $newValue = null;

    public function __construct(
        Detection $detection,
        string $who,
        string $field,
        ?string $previousValue,
        ?string $newValue,
    ) {
        $this->detection = $detection;
        $this->who = $who;
        $this->field = $field;
        $this->previousValue = $previousValue;
        $this->newValue = $newValue;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDetection(): Detection
    {
        return $this->detection;
    }

    public function getWho(): string
    {
        return $this->who;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getPreviousValue(): ?string
    {
        return $this->previousValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }
}
