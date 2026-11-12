<?php

declare(strict_types=1);

namespace App\Retention;

use App\Entity\Detection;
use App\Entity\Snapshot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds snapshots that are older than the configured retention limits and
 * permanently deletes them with everything that references them
 * (design.md D11, spec "Privacy and retention").
 *
 * Snapshot age is measured from the moment the image was stored
 * (Snapshot::createdAt). Because identical image bytes are stored once and
 * shared across detections and cameras (design.md D3), a snapshot is expired
 * only when it is older than the LONGEST age limit among every camera that
 * references it — one camera's shorter limit can never destroy another
 * camera's data. A snapshot referenced only by cameras without any policy
 * never expires.
 */
final class RetentionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SnapshotDeletionService $deletionService,
    ) {
    }

    /**
     * Snapshots that the policy would delete right now, oldest first.
     *
     * @return list<ExpiredSnapshot>
     */
    public function findExpiredSnapshots(\DateTimeImmutable $now, RetentionPolicy $policy): array
    {
        $shortestLimit = $policy->shortestLimit();

        if ($shortestLimit === null) {
            return []; // No policy configured: nothing ever expires.
        }

        // Necessary condition only (age > shortest limit); the exact
        // per-snapshot limit is computed below.
        $candidates = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Snapshot::class, 's')
            ->where('s.createdAt < :cutoff')
            ->setParameter('cutoff', $now->modify(sprintf('-%d days', $shortestLimit)))
            ->getQuery()
            ->getResult();

        $expired = [];

        foreach ($candidates as $candidate) {
            \assert($candidate instanceof Snapshot);

            $expiredSnapshot = $this->expiredSnapshot($candidate, $now, $policy);

            if ($expiredSnapshot !== null) {
                $expired[] = $expiredSnapshot;
            }
        }

        usort($expired, static fn (ExpiredSnapshot $a, ExpiredSnapshot $b): int
            => $a->snapshot->getCreatedAt() <=> $b->snapshot->getCreatedAt());

        return $expired;
    }

    /**
     * Runs the policy to completion: every expired snapshot is permanently
     * deleted (rows in one transaction, files after commit — see
     * SnapshotDeletionService). Each snapshot is deleted in its own
     * transaction so one failure never takes down the whole run.
     */
    public function enforce(\DateTimeImmutable $now, RetentionPolicy $policy): RetentionRunResult
    {
        $expired = $this->findExpiredSnapshots($now, $policy);

        $deletedSnapshots = 0;
        $deletedDetections = 0;
        $deletedReviews = 0;
        $deletedAuditEntries = 0;
        $deletedEvents = 0;
        $warnings = [];

        foreach ($expired as $candidate) {
            $result = $this->deletionService->deleteSnapshot($candidate->snapshot);

            $deletedSnapshots++;
            $deletedDetections += $result->deletedDetections;
            $deletedReviews += $result->deletedReviews;
            $deletedAuditEntries += $result->deletedAuditEntries;
            $deletedEvents += $result->deletedEvents;

            foreach ($result->fileWarnings as $fileWarning) {
                $warnings[] = $fileWarning;
            }
        }

        return new RetentionRunResult(
            deletedSnapshots: $deletedSnapshots,
            deletedDetections: $deletedDetections,
            deletedReviews: $deletedReviews,
            deletedAuditEntries: $deletedAuditEntries,
            deletedEvents: $deletedEvents,
            warnings: $warnings,
        );
    }

    private function expiredSnapshot(
        Snapshot $snapshot,
        \DateTimeImmutable $now,
        RetentionPolicy $policy,
    ): ?ExpiredSnapshot {
        $cameras = $this->camerasReferencing($snapshot);

        $limits = [];

        foreach ($cameras as $camera) {
            $limit = $policy->limitForCamera($camera);

            if ($limit === null) {
                return null; // A referencing camera has no policy: the shared image survives.
            }

            $limits[] = $limit;
        }

        $limitDays = $limits === [] ? $policy->limitForCamera(null) : max($limits);

        if ($limitDays === null) {
            return null; // No camera with a policy references it and there is no global default.
        }

        $expiresAt = $snapshot->getCreatedAt()->modify(sprintf('+%d days', $limitDays));

        if ($expiresAt >= $now) {
            return null;
        }

        return new ExpiredSnapshot($snapshot, $limitDays, $cameras);
    }

    /** @return list<string> Cameras whose detections reference this snapshot (DB query: the inverse collection can be stale). */
    private function camerasReferencing(Snapshot $snapshot): array
    {
        $cameras = $this->entityManager->createQueryBuilder()
            ->select('e.camera')
            ->from(Detection::class, 'd')
            ->leftJoin('d.event', 'e')
            ->where('d.snapshot = :snapshot')
            ->setParameter('snapshot', $snapshot)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_filter(
            $cameras,
            static fn (?string $camera): bool => $camera !== null,
        )));
    }
}
