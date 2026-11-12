<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Label;
use App\Enum\LabelKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and maintains labels. The exported class ID is allocated as
 * max+1 inside a transaction at creation time and is never freed or
 * reassigned: deactivation and rename never touch it (design.md D5).
 */
final class LabelManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function create(string $name, LabelKind $kind, int $sortOrder): Label
    {
        return $this->entityManager->wrapInTransaction(function () use ($name, $kind, $sortOrder): Label {
            $this->assertNameIsAvailable($name);

            $label = new Label(
                $name,
                $kind,
                $sortOrder,
                $this->nextExportedClassId(),
            );

            $this->entityManager->persist($label);
            $this->entityManager->flush();

            return $label;
        });
    }

    public function rename(Label $label, string $newName): void
    {
        $this->entityManager->wrapInTransaction(function () use ($label, $newName): void {
            $this->assertNameIsAvailable($newName, excluding: $label);

            $label->rename($newName);
            $this->entityManager->flush();
        });
    }

    public function deactivate(Label $label): void
    {
        $this->entityManager->wrapInTransaction(function () use ($label): void {
            $label->deactivate();
            $this->entityManager->flush();
        });
    }

    public function activate(Label $label): void
    {
        $this->entityManager->wrapInTransaction(function () use ($label): void {
            $label->activate();
            $this->entityManager->flush();
        });
    }

    private function nextExportedClassId(): int
    {
        $highest = $this->entityManager->createQuery(
            'SELECT MAX(l.exportedClassId) FROM App\Entity\Label l',
        )->getSingleScalarResult();

        return ((int) $highest) + 1;
    }

    private function assertNameIsAvailable(string $name, ?Label $excluding = null): void
    {
        $existing = $this->entityManager->getRepository(Label::class)->findOneBy(['name' => $name]);

        if ($existing !== null && $existing !== $excluding) {
            throw new \DomainException(sprintf('A label named "%s" already exists.', $name));
        }
    }
}
