<?php

declare(strict_types=1);

namespace App\Tests\Frigate;

use App\Frigate\EventListQuery;
use App\Frigate\FrigateApiException;
use App\Frigate\HttpFrigateApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpFrigateApiClientTest extends TestCase
{
    public function testEventsParseFrigatePayloadFields(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient($this->respondWith([[$this->eventPayload()]])),
            'http://frigate.test',
        );

        [$event] = $client->events(new EventListQuery());

        self::assertSame('1762900000.123456', $event->frigateEventId);
        self::assertSame('front_door', $event->camera);
        self::assertEquals(new \DateTimeImmutable('@1762900000'), $event->startTime);
        self::assertEquals(new \DateTimeImmutable('@1762900005'), $event->endTime);
        self::assertSame('person', $event->sourceClass);
        self::assertEqualsWithDelta(0.87, $event->confidence, 0.000001);
        // Frigate's wire format is [x, y, w, h]; the DTO converts it to corners.
        self::assertSame([128.0, 144.0, 768.0, 504.0], $event->box);
        self::assertTrue($event->hasSnapshot);
        self::assertTrue($event->hasClip);
    }

    public function testEventsPaginateBackwardsThroughTimeUntilExhausted(): void
    {
        $requests = [];
        $client = new HttpFrigateApiClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = $options['query'] ?? [];
                $before = $options['query']['before'] ?? null;

                if ($before === null) {
                    return $this->jsonResponse([$this->eventPayload(start: 1762900100), $this->eventPayload(start: 1762900050)]);
                }

                return $this->jsonResponse([$this->eventPayload(start: 1762900000)]);
            }),
            'http://frigate.test',
            pageSize: 2,
        );

        $events = $client->events(new EventListQuery());

        self::assertCount(3, $events);
        self::assertCount(2, $requests, 'short page must terminate pagination');
        self::assertSame(1762900050, $requests[1]['before'], 'next page must start before the oldest fetched event');
        self::assertArrayNotHasKey('before', $requests[0]);
    }

    public function testEventsStopAtMaxResults(): void
    {
        $limits = [];
        $client = new HttpFrigateApiClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$limits): MockResponse {
                $limits[] = $options['query']['limit'] ?? null;

                return $this->jsonResponse([$this->eventPayload(), $this->eventPayload()]);
            }),
            'http://frigate.test',
            pageSize: 2,
        );

        $events = $client->events(new EventListQuery(maxResults: 3));

        self::assertCount(3, $events);
        self::assertSame([2, 1], $limits);
    }

    public function testEventsSendCameraAndSnapshotFilters(): void
    {
        $queries = [];
        $client = new HttpFrigateApiClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$queries): MockResponse {
                $queries[] = $options['query'] ?? [];

                return $this->jsonResponse([]);
            }),
            'http://frigate.test',
        );

        $client->events(new EventListQuery(camera: 'front_door'));

        self::assertSame('front_door', $queries[0]['camera']);
        self::assertSame(1, $queries[0]['has_snapshot']);
        self::assertSame(100, $queries[0]['limit']);
    }

    public function testEventsFailLoudlyOnHttpError(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            'http://frigate.test',
        );

        $this->expectException(FrigateApiException::class);
        $this->expectExceptionMessage('500');

        $client->events(new EventListQuery());
    }

    public function testSnapshotBytesReturnsRawContent(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient(new MockResponse('snapshot-bytes')),
            'http://frigate.test',
        );

        self::assertSame('snapshot-bytes', $client->snapshotBytes('1762900000.123456'));
    }

    public function testSnapshotBytesFailLoudlyOnHttpError(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            'http://frigate.test',
        );

        $this->expectException(FrigateApiException::class);

        $client->snapshotBytes('1762900000.123456');
    }

    public function testFrameDimensionsComeFromCameraConfig(): void
    {
        $requests = [];
        $client = new HttpFrigateApiClient(
            new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $url;

                return $this->jsonResponse([
                    'cameras' => [
                        'front_door' => ['detect' => ['width' => 1280, 'height' => 720]],
                    ],
                ]);
            }),
            'http://frigate.test',
        );

        $dimensions = $client->frameDimensions('front_door');

        self::assertSame(1280.0, $dimensions->width);
        self::assertSame(720.0, $dimensions->height);
        self::assertCount(1, $requests, 'frame dimensions must be cached per camera');

        $again = $client->frameDimensions('front_door');
        self::assertEquals($again, $dimensions);
        self::assertCount(1, $requests);
    }

    public function testFrameDimensionsFailLoudlyForUnknownCamera(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient($this->respondWith([['cameras' => ['front_door' => ['detect' => ['width' => 1280, 'height' => 720]]]]])),
            'http://frigate.test',
        );

        $this->expectException(FrigateApiException::class);
        $this->expectExceptionMessage('side_garden');

        $client->frameDimensions('side_garden');
    }

    public function testClipLinkIsTheFrigateApiPath(): void
    {
        $client = new HttpFrigateApiClient(new MockHttpClient(), 'http://frigate.test');

        self::assertSame('/api/front_door/clips/1762900000.123456.mp4', $client->clipLink('front_door', '1762900000.123456'));
    }

    public function testMalformedEventFailsLoudly(): void
    {
        $client = new HttpFrigateApiClient(
            new MockHttpClient($this->respondWith([[['camera' => 'front_door']]])),
            'http://frigate.test',
        );

        $this->expectException(FrigateApiException::class);
        $this->expectExceptionMessage('id');

        $client->events(new EventListQuery());
    }

    public static function eventPayload(int $start = 1762900000, ?int $end = 1762900005): array
    {
        return [
            'id' => '1762900000.123456',
            'camera' => 'front_door',
            'start_time' => $start,
            'end_time' => $end,
            'label' => 'person',
            'has_snapshot' => true,
            'has_clip' => true,
            'data' => [
                'top_score' => 0.87,
                'box' => [128, 144, 640, 360],
            ],
        ];
    }

    private function jsonResponse(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @param list<mixed> $pages Pages returned in order, then empty pages.
     */
    private function respondWith(array $pages): \Closure
    {
        return function () use (&$pages): MockResponse {
            return $this->jsonResponse(array_shift($pages) ?? []);
        };
    }
}
