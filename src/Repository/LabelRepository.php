<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Label;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side label queries. Label creation/deactivation goes through
 * {@see \App\Domain\LabelManager}; this repository only reads.
 */
final class LabelRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Active labels in presentation order (design.md D5).
     *
     * @return list<Label>
     */
    public function findActive(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(Label::class, 'l')
            ->where('l.active = true')
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
