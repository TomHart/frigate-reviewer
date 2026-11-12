<?php

declare(strict_types=1);

namespace App\Frigate;

/**
 * What to fetch from Frigate's event list. Everything Frigate-specific
 * (parameters, pagination) is resolved inside the client implementation.
 */
final readonly class EventListQuery
{
    public function __construct(
        public ?string $camera = null,
        public ?int $maxResults = null,
        public bool $onlyWithSnapshot = true,
    ) {
    }
}
