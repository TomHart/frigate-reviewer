<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\LabelPolicy;
use App\Enum\ReviewState;
use App\Enum\ReviewValidity;
use App\Entity\Detection;
use App\Entity\Label;
use App\Frigate\EventListQuery;
use App\Repository\DetectionFilter;
use App\Repository\DetectionRepository;
use App\Repository\LabelRepository;
use App\Sync\SyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Detection listing with filters (spec "Detection listing") and the event
 * grouping view (spec "Grouping and bulk classification"): detections that
 * share a Frigate event are presented together.
 */
final class DetectionListController extends AbstractController
{
    private const PER_PAGE = 20;

    /**
     * Only a local path may ask to return after the sync; any other
     * return_to value falls back to this (open-redirect guard, same
     * contract as review saves).
     */
    private const DETECTIONS_LIST_PATH = '/detections';

    private const DEFAULT_SYNC_LIMIT = 50;
    private const MAX_SYNC_LIMIT = 500;

    public function __construct(
        private readonly DetectionRepository $detectionRepository,
        private readonly LabelRepository $labelRepository,
    ) {
    }

    #[Route('/detections', name: 'app_detection_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = $this->filterFrom($request);
        $page = $this->pageFrom($request);
        $total = $this->detectionRepository->countFiltered($filter);

        // "Similar snapshots" view (design.md D8): the same filtered page,
        // arranged into groups of visually similar snapshots by Hamming
        // distance in PHP. Presentation and bulk-selection only.
        $similar = $request->query->get('similar') === '1';
        $detections = $this->detectionRepository->filter($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $similarGroups = $similar
            ? $this->detectionRepository->similarSnapshotGroups($filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : null;

        return $this->render('detection/list.html.twig', [
            'detections' => $detections,
            'similarGroups' => $similarGroups,
            'similar' => $similar,
            'filter' => $filter,
            'cameras' => $this->detectionRepository->distinctCameras(),
            'sourceClasses' => $this->detectionRepository->distinctSourceClasses(),
            // Per-card quick-classify options: each card's dropdown only
            // offers labels its source class accepts (design.md D5), so
            // speed-reviewing never submits a label the domain would reject.
            'assignableLabels' => $this->assignableLabelsByDetection(
                $similarGroups === null ? $detections : array_merge($detections, ...$similarGroups),
            ),
            'total' => $total,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /**
     * Web-triggered sync (design.md D6): reuses the canonical SyncService
     * directly — the console command stays the canonical entry point, this
     * is only a convenience for the listing page.
     *
     * Synchronous by agreement: the run completes, a flash message reports
     * the counts, then the browser returns to the listing it came from.
     */
    #[Route('/detections/sync', name: 'app_detection_sync', methods: ['POST'])]
    public function sync(Request $request, SyncService $syncService): Response
    {
        // Fetching up to 500 events and downloading each snapshot over HTTP
        // can take minutes; the run must not be cut off by the default
        // execution timeout. The operation itself stays bounded by the
        // validated limit.
        set_time_limit(0);

        $result = $syncService->sync(new EventListQuery(
            maxResults: $this->syncLimitFrom($request),
        ));

        foreach ($result->errors as $error) {
            $this->addFlash('error', $error);
        }

        $this->addFlash('success', sprintf(
            'Stored %d new event(s), skipped %d already synced.',
            $result->storedEvents,
            $result->skippedEvents,
        ));

        return $this->redirect(
            $this->trustedReturnTo($request->request->get('return_to')) ?? self::DETECTIONS_LIST_PATH,
        );
    }

    /**
     * Assignable identity labels per detection id, in label presentation
     * order, for the card quick-classify dropdowns.
     *
     * @param list<Detection> $detections
     *
     * @return array<int, list<Label>>
     */
    private function assignableLabelsByDetection(array $detections): array
    {
        $labels = $this->labelRepository->findActive();
        $assignable = [];

        foreach ($detections as $detection) {
            $assignable[$detection->getId()] = LabelPolicy::assignableTo($detection, $labels);
        }

        return $assignable;
    }

    #[Route('/event/{frigateEventId}', name: 'app_event_detail', methods: ['GET'])]
    public function event(string $frigateEventId): Response
    {
        $event = $this->detectionRepository->eventByFrigateEventId($frigateEventId);

        if ($event === null) {
            throw new NotFoundHttpException(sprintf('Frigate event "%s" does not exist.', $frigateEventId));
        }

        return $this->render('detection/event.html.twig', [
            'event' => $event,
            'detections' => $this->detectionRepository->detectionsForEvent($event),
            'snapshot' => $this->detectionRepository->firstSnapshotForEvent($event),
        ]);
    }

    private function filterFrom(Request $request): DetectionFilter
    {
        $query = $request->query;

        return new DetectionFilter(
            camera: $this->optionalTrimmed($query->get('camera')),
            day: $this->optionalDay($query->get('date')),
            validity: $this->optionalValidity($query->get('validity')),
            sourceClass: $this->optionalTrimmed($query->get('class')),
            reviewState: $this->reviewStateFrom($query->get('review_state')),
        );
    }

    private function optionalTrimmed(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null; // Absent query parameter.
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalDay(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($day === false) {
            throw new BadRequestHttpException(sprintf('Invalid date filter "%s"; expected YYYY-MM-DD.', $value));
        }

        return $day;
    }

    private function optionalValidity(mixed $value): ?ReviewValidity
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $validity = ReviewValidity::tryFrom(trim($value));

        if ($validity === null) {
            throw new BadRequestHttpException(sprintf('Invalid validity filter "%s".', $value));
        }

        return $validity;
    }

    /**
     * Absent/empty means the page default (unreviewed, the review queue);
     * "all" means no review-state condition; anything else must be a valid
     * ReviewState value or the request is rejected.
     */
    private function reviewStateFrom(mixed $value): ?ReviewState
    {
        if (!is_string($value) || trim($value) === '') {
            return ReviewState::Unreviewed;
        }

        $trimmed = trim($value);

        if ($trimmed === 'all') {
            return null;
        }

        $reviewState = ReviewState::tryFrom($trimmed);

        if ($reviewState === null) {
            throw new BadRequestHttpException(sprintf('Invalid review state filter "%s".', $value));
        }

        return $reviewState;
    }

    private function pageFrom(Request $request): int
    {
        $value = $request->query->get('page', '1');

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new BadRequestHttpException(sprintf('Invalid page "%s".', is_string($value) ? $value : ''));
        }

        return (int) $value;
    }

    /**
     * How many Frigate events one sync may fetch, strictly parsed at the
     * boundary: a positive integer capped so a hand-crafted request can
     * never trigger an unbounded run.
     */
    private function syncLimitFrom(Request $request): int
    {
        $value = $request->query->get('limit', (string) self::DEFAULT_SYNC_LIMIT);

        if (!is_string($value) || !ctype_digit($value)) {
            throw new BadRequestHttpException(sprintf(
                'Sync limit "%s" must be a positive integer.',
                is_string($value) ? $value : '',
            ));
        }

        $limit = (int) $value;

        if ($limit < 1 || $limit > self::MAX_SYNC_LIMIT) {
            throw new BadRequestHttpException(sprintf(
                'Sync limit must be between 1 and %d, got %d.',
                self::MAX_SYNC_LIMIT,
                $limit,
            ));
        }

        return $limit;
    }

    /**
     * Only a local /detections path may ask to return after the sync; any
     * other return_to value falls back to the plain list (open-redirect
     * guard, same contract as review saves).
     */
    private function trustedReturnTo(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null; // Absent: caller falls back to the plain list.
        }

        $candidate = trim($value);

        return preg_match('#^/detections(\?[^\r\n]*)?$#', $candidate) === 1
            ? $candidate
            : null;
    }
}
