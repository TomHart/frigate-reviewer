<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\AuditEntry;
use App\Entity\Detection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Append-only audit trail (design.md D9): one entry per changed field,
 * recorded in the same transaction as the change itself. Field values are
 * stored as human-readable strings (identity by label name, boxes as
 * "x,y,width,height").
 */
final class AuditTrail
{
    public const DEFAULT_REVIEWER = 'reviewer';

    private const TRACKED_FIELDS = [
        'validity',
        'identity',
        'corrected_box',
        'false_positive_reason',
        'reviewer_notes',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, ?string> $before field values before the change
     * @param array<string, ?string> $after  field values after the change
     */
    public function recordChanges(
        Detection $detection,
        array $before,
        array $after,
        string $who = self::DEFAULT_REVIEWER,
    ): void {
        foreach (self::TRACKED_FIELDS as $field) {
            $previousValue = $before[$field] ?? null;
            $newValue = $after[$field] ?? null;

            if ($previousValue === $newValue) {
                continue;
            }

            $this->entityManager->persist(new AuditEntry($detection, $who, $field, $previousValue, $newValue));
        }
    }
}
