<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEntry;
use App\Entity\Detection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side queries over the append-only audit trail (design.md D9). Audit
 * entries are only ever written through the domain layer next to the change
 * itself; this repository only reads.
 */
final class AuditEntryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * All audit entries of one detection, newest first (ties broken by id,
     * since entries written in one transaction share a timestamp).
     *
     * @return list<AuditEntry>
     */
    public function forDetection(Detection $detection): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AuditEntry::class, 'a')
            ->where('a.detection = :detection')
            ->setParameter('detection', $detection)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
