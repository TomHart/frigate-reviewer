<?php

declare(strict_types=1);

namespace App\Retention;

use App\Entity\Snapshot;

/**
 * One snapshot selected for deletion by the retention policy, with the
 * reason it expired: the longest applicable age limit among all cameras
 * that reference it (see RetentionPolicy).
 */
final class ExpiredSnapshot
{
    /**
     * @param list<string> $cameras Cameras whose detections reference the snapshot.
     */
    public function __construct(
        public readonly Snapshot $snapshot,
        public readonly int $limitDays,
        public readonly array $cameras,
    ) {
    }
}
