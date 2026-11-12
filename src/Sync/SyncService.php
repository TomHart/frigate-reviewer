<?php

declare(strict_types=1);

namespace App\Sync;

use App\Domain\BoundingBox;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Frigate\EventListQuery;
use App\Frigate\FrigateApiClientInterface;
use App\Frigate\FrigateEventDto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The canonical sync entry point (design.md D6): fetches events from the
 * Frigate HTTP API and stores new events, detections and snapshots.
 *
 * Idempotent by Frigate event ID: an event already present is skipped and
 * its stored facts are never touched. Snapshot download happens before the
 * transaction; file write and database rows commit together, so a partial
 * run never leaves a half-written detection.
 */
final class SyncService
{
    public function __construct(
        private readonly FrigateApiClientInterface $frigate,
        private readonly EntityManagerInterface $entityManager,
        private readonly SnapshotStore $snapshotStore,
    ) {
    }

    public function sync(EventListQuery $query): SyncResult
    {
        $storedEvents = 0;
        $skippedEvents = 0;
        $errors = [];

        foreach ($this->frigate->events($query) as $event) {
            try {
                if ($this->syncEvent($event)) {
                    ++$storedEvents;
                } else {
                    ++$skippedEvents;
                }
            } catch (\Throwable $error) {
                $errors[] = sprintf(
                    'Event %s: %s',
                    $event->frigateEventId,
                    $error->getMessage(),
                );
            }
        }

        return new SyncResult($storedEvents, $skippedEvents, $errors);
    }

    /** @return bool true when the event was newly stored, false when it was skipped. */
    private function syncEvent(FrigateEventDto $event): bool
    {
        $alreadySynced = $this->entityManager
            ->getRepository(FrigateEvent::class)
            ->findOneBy(['frigateEventId' => $event->frigateEventId]);

        if ($alreadySynced !== null) {
            return false; // Immutable Frigate facts are never modified on re-run.
        }

        if ($event->startTime === null) {
            throw new \RuntimeException('event has no start time; cannot be positioned in the review timeline');
        }

        if (!$event->hasSnapshot) {
            throw new \RuntimeException('event has no snapshot; there is no image to review');
        }

        // Downloaded outside the transaction: HTTP cannot be rolled back,
        // so nothing inside the transaction depends on it succeeding.
        $snapshotBytes = $this->frigate->snapshotBytes($event->frigateEventId);

        $this->entityManager->wrapInTransaction(function () use ($event, $snapshotBytes): void {
            $frigateEvent = new FrigateEvent(
                $event->frigateEventId,
                $event->camera,
                $event->startTime,
                $event->endTime,
                $event->hasClip ? $this->frigate->clipLink($event->camera, $event->frigateEventId) : null,
            );

            $snapshot = $this->snapshotStore->storeOriginal($snapshotBytes);

            $detection = Detection::fromFrigate(
                $frigateEvent,
                $snapshot,
                $event->sourceClass ?? 'unknown',
                $event->confidence,
                $event->startTime,
                $event->endTime,
                $this->normalizeBox($event),
            );

            $this->entityManager->persist($frigateEvent);
            $this->entityManager->persist($detection);
            $this->entityManager->flush();
        });

        return true;
    }

    /**
     * The DTO delivers the box as corners [xmin, ymin, xmax, ymax] (Frigate's
     * [x, y, w, h] wire format is converted at the boundary). Values within
     * 0..1 are already normalised ratios; larger values are pixels of the
     * camera's detection frame, normalised against its dimensions.
     */
    private function normalizeBox(FrigateEventDto $event): ?BoundingBox
    {
        if ($event->box === null) {
            return null;
        }

        [$xmin, $ymin, $xmax, $ymax] = $event->box;

        if (self::isNormalised($xmin, $ymin, $xmax, $ymax)) {
            return $this->boxFromCorners($xmin, $ymin, $xmax, $ymax, $event->frigateEventId);
        }

        $frame = $this->frigate->frameDimensions($event->camera);

        return $this->boxFromCorners(
            $xmin / $frame->width,
            $ymin / $frame->height,
            $xmax / $frame->width,
            $ymax / $frame->height,
            $event->frigateEventId,
        );
    }

    private static function isNormalised(float ...$coordinates): bool
    {
        foreach ($coordinates as $coordinate) {
            if ($coordinate < 0.0 || $coordinate > 1.0) {
                return false;
            }
        }

        return true;
    }

    private function boxFromCorners(
        float $xmin,
        float $ymin,
        float $xmax,
        float $ymax,
        string $frigateEventId,
    ): BoundingBox {
        $width = $xmax - $xmin;
        $height = $ymax - $ymin;

        if ($width <= 0.0 || $height <= 0.0) {
            throw new \RuntimeException(sprintf(
                'event box [%s] has no positive area after normalisation',
                implode(', ', array_map(strval(...), [$xmin, $ymin, $xmax, $ymax])),
            ));
        }

        if ($xmin < 0.0 || $ymin < 0.0) {
            throw new \RuntimeException(sprintf(
                'event "%s" box has negative origin (%f, %f).',
                $frigateEventId,
                $xmin,
                $ymin,
            ));
        }

        return new BoundingBox($xmin, $ymin, $width, $height);
    }
}
