<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LabelKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configurable identity label (design.md D5). The exported class ID is
 * allocated once at creation, monotonically, and is never reassigned or
 * freed: there is deliberately no setter for it, and deactivate/rename leave
 * it untouched.
 */
#[ORM\Entity]
class Label
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private string $name;

    #[ORM\Column(enumType: LabelKind::class)]
    private LabelKind $kind;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'exported_class_id', unique: true)]
    private int $exportedClassId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @internal Use {@see \App\Domain\LabelManager::create()}, which allocates
     * the exported class ID inside a transaction. Never construct labels directly.
     */
    public function __construct(string $name, LabelKind $kind, int $sortOrder, int $exportedClassId)
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Label name must not be empty.');
        }

        $this->name = $name;
        $this->kind = $kind;
        $this->sortOrder = $sortOrder;
        $this->exportedClassId = $exportedClassId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $newName): void
    {
        if (trim($newName) === '') {
            throw new \InvalidArgumentException('Label name must not be empty.');
        }

        $this->name = $newName;
    }

    public function getKind(): LabelKind
    {
        return $this->kind;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function getExportedClassId(): int
    {
        return $this->exportedClassId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
