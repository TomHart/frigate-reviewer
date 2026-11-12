<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Snapshot;

/**
 * Confirmation token for permanent deletion (design.md D11): an HMAC over
 * the snapshot id using the application secret. The token is only ever
 * handed out on the confirmation page, so a successful POST proves the
 * confirmation page was rendered — the deletion flow cannot be triggered by
 * a bare POST.
 */
final class DeleteConfirmationToken
{
    private const PURPOSE = 'permanent-snapshot-deletion';

    public static function forSnapshot(Snapshot $snapshot, string $secret): string
    {
        return hash_hmac('sha256', self::PURPOSE.':'.$snapshot->getId(), $secret);
    }

    public static function matches(string $submitted, Snapshot $snapshot, string $secret): bool
    {
        return hash_equals(self::forSnapshot($snapshot, $secret), $submitted);
    }
}
