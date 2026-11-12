<?php

declare(strict_types=1);

namespace App\Frigate;

/**
 * Read-only access to Frigate's HTTP API. Frigate itself is never modified;
 * every request here is a GET (design.md "Frigate integration is read-only").
 * Isolating Frigate behind this interface keeps API adaptations local.
 */
interface FrigateApiClientInterface
{
    /**
     * Lists Frigate events, transparently paginating until the query is
     * satisfied.
     *
     * @return list<FrigateEventDto>
     */
    public function events(EventListQuery $query): array;

    /**
     * The raw original snapshot bytes of an event, exactly as Frigate
     * serves them.
     *
     * @throws FrigateApiException when Frigate cannot serve the snapshot.
     */
    public function snapshotBytes(string $frigateEventId): string;

    /**
     * The detection frame dimensions of a camera, needed to normalise
     * Frigate's pixel bounding boxes.
     *
     * @throws FrigateApiException when the camera is unknown or the config
     *                             payload cannot be parsed.
     */
    public function frameDimensions(string $camera): FrameDimensions;

    /**
     * The link under which Frigate serves this event's clip. The clip is
     * never downloaded; only the link is stored (design.md D3).
     */
    public function clipLink(string $camera, string $frigateEventId): string;
}
