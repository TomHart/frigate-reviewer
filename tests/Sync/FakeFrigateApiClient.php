<?php

declare(strict_types=1);

namespace App\Tests\Sync;

use App\Frigate\EventListQuery;
use App\Frigate\FrameDimensions;
use App\Frigate\FrigateApiClientInterface;
use App\Frigate\FrigateApiException;
use App\Frigate\FrigateEventDto;

/**
 * In-memory stand-in for the Frigate HTTP API, so sync behaviour is tested
 * without any network traffic.
 */
final class FakeFrigateApiClient implements FrigateApiClientInterface
{
    /**
     * @param list<FrigateEventDto>               $events
     * @param array<string, string>               $snapshotBytesByEventId
     * @param array<string, string|\Throwable>    $snapshotFailuresByEventId
     * @param array<string, FrameDimensions>      $frameDimensionsByCamera
     */
    public function __construct(
        private readonly array $events = [],
        private readonly array $snapshotBytesByEventId = [],
        private readonly array $snapshotFailuresByEventId = [],
        private readonly array $frameDimensionsByCamera = [],
    ) {
    }

    public function events(EventListQuery $query): array
    {
        $events = array_values(array_filter(
            $this->events,
            fn (FrigateEventDto $event): bool => ($query->camera === null || $event->camera === $query->camera)
                && (!$query->onlyWithSnapshot || $event->hasSnapshot),
        ));

        if ($query->maxResults !== null) {
            $events = array_slice($events, 0, $query->maxResults);
        }

        return $events;
    }

    public function snapshotBytes(string $frigateEventId): string
    {
        $failure = $this->snapshotFailuresByEventId[$frigateEventId] ?? null;

        if ($failure instanceof \Throwable) {
            throw $failure;
        }

        if (is_string($failure)) {
            throw new FrigateApiException($failure);
        }

        $bytes = $this->snapshotBytesByEventId[$frigateEventId] ?? null;

        if ($bytes === null) {
            throw new FrigateApiException(sprintf('No snapshot configured for event "%s".', $frigateEventId));
        }

        return $bytes;
    }

    public function frameDimensions(string $camera): FrameDimensions
    {
        return $this->frameDimensionsByCamera[$camera]
            ?? throw new FrigateApiException(sprintf('No frame dimensions configured for camera "%s".', $camera));
    }

    public function clipLink(string $camera, string $frigateEventId): string
    {
        return sprintf('/api/%s/clips/%s.mp4', $camera, $frigateEventId);
    }

    /**
     * A Frigate event payload shaped like the real /api/events response, so
     * the DTO parser is exercised on every sync test.
     *
     * @param list<float>|null $box
     */
    public static function eventPayload(
        string $id,
        string $camera,
        int $startTime,
        ?int $endTime,
        string $label,
        ?float $topScore,
        ?array $box,
        bool $hasSnapshot = true,
        bool $hasClip = false,
    ): FrigateEventDto {
        return FrigateEventDto::fromArray([
            'id' => $id,
            'camera' => $camera,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'label' => $label,
            'has_snapshot' => $hasSnapshot,
            'has_clip' => $hasClip,
            'data' => [
                'top_score' => $topScore,
                'box' => $box,
            ],
        ]);
    }
}
