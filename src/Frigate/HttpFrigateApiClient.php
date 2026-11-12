<?php

declare(strict_types=1);

namespace App\Frigate;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony HttpClient implementation of {@see FrigateApiClientInterface}.
 * Every Frigate-specific detail lives here: endpoint paths, query
 * parameters, pagination and payload shapes. Frigate is only ever read.
 */
final class HttpFrigateApiClient implements FrigateApiClientInterface
{
    private const EVENTS_ENDPOINT = '/api/events';
    private const SNAPSHOT_ENDPOINT = '/api/events/%s/snapshot.jpg';
    private const CONFIG_ENDPOINT = '/api/config';
    private const CLIP_LINK_TEMPLATE = '/api/%s/clips/%s.mp4';

    /** Events fetched per request while walking the list backwards in time. */
    private const PAGE_SIZE = 100;

    /** @var array<string, FrameDimensions> Camera detect dimensions, resolved once per camera. */
    private array $frameDimensionsByCamera = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {
    }

    public function events(EventListQuery $query): array
    {
        $events = [];
        $oldestStartTimeFetched = null;

        while (true) {
            $remaining = $query->maxResults === null ? null : $query->maxResults - count($events);

            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $limit = $remaining === null ? $this->pageSize : min($this->pageSize, $remaining);
            $page = array_slice(
                $this->fetchEventPage(
                    camera: $query->camera,
                    limit: $limit,
                    startedBefore: $oldestStartTimeFetched,
                    onlyWithSnapshot: $query->onlyWithSnapshot,
                ),
                0,
                $limit,
            );

            if ($page === []) {
                break;
            }

            foreach ($page as $event) {
                $events[] = $event;
            }

            if (count($page) < $limit) {
                break; // Short page: Frigate has no older events to offer.
            }

            $oldestStartTimeFetched = self::oldestStartTime($page);
        }

        return $events;
    }

    public function snapshotBytes(string $frigateEventId): string
    {
        $url = $this->baseUrl.sprintf(self::SNAPSHOT_ENDPOINT, rawurlencode($frigateEventId));
        $response = $this->httpClient->request('GET', $url);

        if ($response->getStatusCode() >= 300) {
            throw FrigateApiException::requestFailed($url, $response->getStatusCode());
        }

        return $response->getContent();
    }

    public function frameDimensions(string $camera): FrameDimensions
    {
        if (isset($this->frameDimensionsByCamera[$camera])) {
            return $this->frameDimensionsByCamera[$camera];
        }

        $dimensions = $this->resolveFrameDimensions($camera);
        $this->frameDimensionsByCamera[$camera] = $dimensions;

        return $dimensions;
    }

    public function clipLink(string $camera, string $frigateEventId): string
    {
        return sprintf(self::CLIP_LINK_TEMPLATE, rawurlencode($camera), rawurlencode($frigateEventId));
    }

    /**
     * @return list<FrigateEventDto> A single page, possibly empty.
     */
    private function fetchEventPage(
        ?string $camera,
        int $limit,
        ?\DateTimeImmutable $startedBefore,
        bool $onlyWithSnapshot,
    ): array {
        $parameters = array_filter([
            'camera' => $camera,
            'limit' => $limit,
            'before' => $startedBefore?->getTimestamp(),
            'has_snapshot' => $onlyWithSnapshot ? 1 : null,
            'include_thumbnails' => 0,
            'ascending' => 0,
        ], static fn (mixed $value): bool => $value !== null);

        $url = $this->baseUrl.self::EVENTS_ENDPOINT;
        $response = $this->httpClient->request('GET', $url, ['query' => $parameters]);

        if ($response->getStatusCode() >= 300) {
            throw FrigateApiException::requestFailed($url, $response->getStatusCode());
        }

        $payload = $response->toArray(false);

        if (!is_array($payload)) {
            throw FrigateApiException::malformedResponse($url, 'expected a JSON list of events');
        }

        return array_map(
            static fn (mixed $event): FrigateEventDto => FrigateEventDto::fromArray(
                self::assertEventArray($event),
            ),
            array_values($payload),
        );
    }

    private function resolveFrameDimensions(string $camera): FrameDimensions
    {
        $url = $this->baseUrl.self::CONFIG_ENDPOINT;
        $response = $this->httpClient->request('GET', $url);

        if ($response->getStatusCode() >= 300) {
            throw FrigateApiException::requestFailed($url, $response->getStatusCode());
        }

        $config = $response->toArray(false);
        $detect = $config['cameras'][$camera]['detect'] ?? null;

        if (!is_array($detect) || !is_numeric($detect['width'] ?? null) || !is_numeric($detect['height'] ?? null)) {
            throw FrigateApiException::malformedResponse(
                $url,
                sprintf('no detect frame dimensions found for camera "%s"', $camera),
            );
        }

        return new FrameDimensions((float) $detect['width'], (float) $detect['height']);
    }

    private static function assertEventArray(mixed $event): array
    {
        if (!is_array($event)) {
            throw FrigateApiException::malformedResponse(self::EVENTS_ENDPOINT, 'event list contains a non-object entry');
        }

        return $event;
    }

    /**
     * @param list<FrigateEventDto> $page
     */
    private static function oldestStartTime(array $page): \DateTimeImmutable
    {
        $oldest = null;

        foreach ($page as $event) {
            if ($event->startTime === null) {
                continue;
            }

            if ($oldest === null || $event->startTime < $oldest) {
                $oldest = $event->startTime;
            }
        }

        // An event without a start time cannot be positioned in time; the
        // caller reports it as a per-event sync error before we get here.
        if ($oldest === null) {
            throw new \LogicException('Cannot paginate a page of events that all lack start times.');
        }

        return $oldest;
    }
}
