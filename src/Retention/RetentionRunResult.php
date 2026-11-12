<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * Outcome of one retention run: counts of everything permanently deleted,
 * plus warnings for files that could not be removed after the database
 * commit (orphan files are harmless; see SnapshotDeletionService).
 */
final class RetentionRunResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly int $deletedSnapshots = 0,
        public readonly int $deletedDetections = 0,
        public readonly int $deletedReviews = 0,
        public readonly int $deletedAuditEntries = 0,
        public readonly int $deletedEvents = 0,
        public readonly array $warnings = [],
    ) {
    }
}
