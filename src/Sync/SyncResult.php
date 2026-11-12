<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Outcome of one sync run. Per-event errors are collected (not thrown) so
 * one broken event cannot block the rest of the run; the command fails
 * loudly with all of them at the end.
 */
final readonly class SyncResult
{
    /**
     * @param list<string> $errors Human-readable failure per event that could not be synced.
     */
    public function __construct(
        public int $storedEvents,
        public int $skippedEvents,
        public array $errors,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
