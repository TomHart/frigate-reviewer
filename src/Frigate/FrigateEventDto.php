<?php

declare(strict_types=1);

namespace App\Frigate;

/**
 * One Frigate event, parsed at the boundary into a trusted, typed value.
 * Frigate reports `data.box` as [x, y, w, h]; that is converted here to
 * corners ([xmin, ymin, xmax, ymax]) so nothing downstream needs to know
 * Frigate's wire format. Normalisation to the snapshot's 0..1 coordinates
 * happens in the sync layer, which knows the camera's frame dimensions.
 */
final readonly class FrigateEventDto
{
    /**
     * @param list<float>|null $box The Frigate box as [xmin, ymin, xmax, ymax].
     */
    public function __construct(
        public string $frigateEventId,
        public string $camera,
        public ?\DateTimeImmutable $startTime,
        public ?\DateTimeImmutable $endTime,
        public ?string $sourceClass,
        public ?float $confidence,
        public ?array $box,
        public bool $hasSnapshot,
        public bool $hasClip,
    ) {
    }

    /**
     * @param array<string, mixed> $event One element of the /api/events payload.
     */
    public static function fromArray(array $event): self
    {
        $frigateEventId = $event['id'] ?? null;

        if (!is_string($frigateEventId) || $frigateEventId === '') {
            throw FrigateApiException::malformedEvent($event, 'missing or empty "id"');
        }

        $camera = $event['camera'] ?? null;

        if (!is_string($camera) || $camera === '') {
            throw FrigateApiException::malformedEvent($event, 'missing or empty "camera"');
        }

        return new self(
            frigateEventId: $frigateEventId,
            camera: $camera,
            startTime: self::timestamp($event['start_time'] ?? null),
            endTime: self::timestamp($event['end_time'] ?? null),
            sourceClass: self::sourceClass($event),
            confidence: self::confidence($event),
            box: self::box($event),
            hasSnapshot: $event['has_snapshot'] === true,
            hasClip: $event['has_clip'] === true,
        );
    }

    private static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        // Frigate reports unix timestamps as floats; seconds precision is
        // plenty for a review tool.
        return new \DateTimeImmutable('@'.(int) $value);
    }

    private static function sourceClass(array $event): ?string
    {
        $label = $event['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function confidence(array $event): ?float
    {
        $data = $event['data'] ?? null;
        $data = is_array($data) ? $data : [];

        $topScore = $data['top_score'] ?? null;

        if (is_numeric($topScore)) {
            return (float) $topScore;
        }

        $firstDetection = $data['detections'][0] ?? null;

        if (is_array($firstDetection) && is_numeric($firstDetection['score'] ?? null)) {
            return (float) $firstDetection['score'];
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    private static function box(array $event): ?array
    {
        $data = $event['data'] ?? null;
        $data = is_array($data) ? $data : [];

        $raw = $data['detections'][0]['box'] ?? $data['box'] ?? null;

        if (!is_array($raw) || count($raw) !== 4) {
            return null;
        }

        $coordinates = [];

        foreach ($raw as $coordinate) {
            if (!is_numeric($coordinate)) {
                return null;
            }

            $coordinates[] = (float) $coordinate;
        }

        [$x, $y, $width, $height] = $coordinates;

        return [$x, $y, $x + $width, $y + $height];
    }
}
