<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * Outcome of permanently deleting one snapshot image and everything that
 * references it (design.md D11). Database rows are gone when this returns;
 * $deletedFiles lists files removed after the commit, $fileWarnings lists
 * files that could not be removed (they remain on disk as harmless orphans).
 */
final class SnapshotDeletionResult
{
    /**
     * @param list<string> $deletedFiles
     * @param list<string> $fileWarnings
     */
    public function __construct(
        public readonly int $deletedDetections,
        public readonly int $deletedReviews,
        public readonly int $deletedAuditEntries,
        public readonly int $deletedEvents,
        public readonly array $deletedFiles,
        public readonly array $fileWarnings,
    ) {
    }
}
