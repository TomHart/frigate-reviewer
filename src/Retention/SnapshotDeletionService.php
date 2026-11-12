<?php

declare(strict_types=1);

namespace App\Retention;

use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Repository\AuditEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanent deletion of one snapshot image and everything that references it
 * (design.md D11, spec "Immutable original snapshots" — deletion is the one
 * explicit, user-initiated exception). No soft-delete tombstones:
 * "permanently delete" means permanently.
 *
 * Ordering guarantee — a crash can never leave database rows pointing at
 * missing files:
 *
 *  1. ONE database transaction removes dependent rows: audit entries,
 *     reviews, detections, the snapshot row itself, and any Frigate event
 *     that becomes orphaned.
 *  2. Files (original + thumbnail) are deleted only AFTER the commit. If the
 *     process dies between commit and unlink, orphan files remain on disk —
 *     harmless, unreferenced, and cheap to sweep later. The reverse order
 *     would leave rows pointing at files that no longer exist, which the
 *     system must never do.
 *
 * Shared snapshots are handled correctly: identical image bytes are stored
 * once and may be referenced by several detections (design.md D3). All
 * referencing detections are deleted together with the snapshot; before the
 * transaction commits, a guard verifies no detection still references the
 * snapshot row, failing loudly rather than deleting an image still in use.
 */
final class SnapshotDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditEntryRepository $auditEntryRepository,
    ) {
    }

    /**
     * @throws \RuntimeException when the deletion cannot be completed; the
     *                           database transaction is rolled back first.
     */
    public function deleteSnapshot(Snapshot $snapshot): SnapshotDeletionResult
    {
        // Doctrine clears the identifier of removed entities at flush, so
        // the id is captured up front for the shared-reference guard below.
        $snapshotId = $snapshot->getId();

        if ($snapshotId === null) {
            throw new \RuntimeException('Cannot delete a snapshot that has not been persisted.');
        }

        $detections = $this->entityManager
            ->getRepository(Detection::class)
            ->createQueryBuilder('d')
            ->where('d.snapshot = :snapshot')
            ->setParameter('snapshot', $snapshot)
            ->getQuery()
            ->getResult();

        \assert(\is_array($detections));
        $events = $this->distinctEvents($detections);

        $deletedReviews = 0;
        $deletedAuditEntries = 0;
        $deletedEvents = 0;

        $this->entityManager->beginTransaction();

        try {
            foreach ($detections as $detection) {
                \assert($detection instanceof Detection);

                $review = $detection->getReview();

                if ($review !== null) {
                    $this->entityManager->remove($review);
                    $deletedReviews++;
                }

                foreach ($this->auditEntryRepository->forDetection($detection) as $auditEntry) {
                    $this->entityManager->remove($auditEntry);
                    $deletedAuditEntries++;
                }

                $this->entityManager->remove($detection);
            }

            $this->entityManager->remove($snapshot);
            $this->entityManager->flush();

            // Events whose last detection just went away are deleted with
            // it; events that still have detections survive untouched.
            foreach ($events as $event) {
                if ($this->detectionRepositoryCount($event) === 0) {
                    $this->entityManager->remove($event);
                    $deletedEvents++;
                }
            }

            $this->entityManager->flush();

            // Fail-fast guard: after the flush, nothing may reference the
            // snapshot row any more. If it does, roll everything back
            // instead of deleting a shared image out from under its users.
            $remainingReferences = $this->entityManager
                ->getRepository(Detection::class)
                ->count(['snapshot' => $snapshotId]);

            if ($remainingReferences > 0) {
                throw new \RuntimeException(sprintf(
                    'Snapshot %d is still referenced by %d detection(s); refusing to delete it.',
                    $snapshotId,
                    $remainingReferences,
                ));
            }

            $this->entityManager->commit();
        } catch (\Throwable $failure) {
            $this->entityManager->rollback();

            throw $failure;
        }

        [$deletedFiles, $fileWarnings] = $this->deleteFiles($snapshot);

        return new SnapshotDeletionResult(
            deletedDetections: \count($detections),
            deletedReviews: $deletedReviews,
            deletedAuditEntries: $deletedAuditEntries,
            deletedEvents: $deletedEvents,
            deletedFiles: $deletedFiles,
            fileWarnings: $fileWarnings,
        );
    }

    /**
     * Files are removed strictly after the database commit (see class doc):
     * unlink failures are reported as warnings, never thrown — the rows are
     * already gone and an orphan file is harmless.
     *
     * @return list<string> Paths successfully unlinked.
     */
    private function deleteFiles(Snapshot $snapshot): array
    {
        $paths = [$snapshot->getOriginalPath()];

        if ($snapshot->getThumbnailPath() !== null) {
            $paths[] = $snapshot->getThumbnailPath();
        }

        $deleted = [];
        $warnings = [];

        foreach ($paths as $path) {
            if (@unlink($path)) {
                $deleted[] = $path;
            } else {
                $warnings[] = sprintf(
                    'Snapshot file "%s" could not be deleted and remains on disk as an unreferenced orphan.',
                    $path,
                );
            }
        }

        return [$deleted, $warnings];
    }

    /** @param list<Detection> $detections @return list<FrigateEvent> */
    private function distinctEvents(array $detections): array
    {
        $events = [];

        foreach ($detections as $detection) {
            \assert($detection instanceof Detection);

            $event = $detection->getEvent();

            if ($event !== null && !\in_array($event, $events, true)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function detectionRepositoryCount(FrigateEvent $event): int
    {
        return (int) $this->entityManager
            ->getRepository(Detection::class)
            ->count(['event' => $event->getId()]);
    }
}
