<?php

declare(strict_types=1);

namespace App\Frigate;

/**
 * Thrown when Frigate's HTTP API returns something we cannot parse. Fail
 * loud at the boundary so Frigate API drift is never silently swallowed.
 */
final class FrigateApiException extends \RuntimeException
{
    public static function requestFailed(string $url, int $statusCode): self
    {
        return new self(sprintf('Frigate API request to "%s" failed with HTTP status %d.', $url, $statusCode));
    }

    public static function malformedEvent(array $event, string $reason): self
    {
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '(unknown)';

        return new self(sprintf('Frigate event "%s" is malformed: %s.', $eventId, $reason));
    }

    public static function malformedResponse(string $url, string $reason): self
    {
        return new self(sprintf('Frigate API response from "%s" could not be parsed: %s.', $url, $reason));
    }
}
