<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\FrigateSourceClasses;
use App\Domain\LabelPolicy;
use App\Domain\ReviewService;
use App\Domain\ReviewSubmission;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Label;
use App\Enum\ReviewValidity;
use App\Http\CorrectedBoxInput;
use App\Repository\AuditEntryRepository;
use App\Repository\DetectionRepository;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-object review (spec "Per-object review"): the review form for one
 * detection — validity, identity, false-positive reason, reviewer notes,
 * corrected bounding box — all editable at any time, saved through the domain
 * layer. Also serves false-positive reason suggestions (design.md D10) and
 * manual object creation (spec "Manual objects added by reviewer").
 */
final class ReviewController extends AbstractController
{
    /**
     * The only local path a form may ask to return to after a write; any
     * other return_to value falls back to this (open-redirect guard).
     */
    private const DETECTIONS_LIST_PATH = '/detections';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReviewService $reviewService,
        private readonly DetectionRepository $detectionRepository,
        private readonly LabelRepository $labelRepository,
        private readonly AuditEntryRepository $auditEntryRepository,
    ) {
    }

    #[Route('/review/{id}', name: 'app_review_object', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $detection = $this->detection($id);

        return $this->render('review/object.html.twig', [
            'detection' => $detection,
            'review' => $detection->getReview(),
            'labels' => $this->assignableLabels($detection),
            'auditEntries' => $this->auditEntryRepository->forDetection($detection),
            // Validated at the boundary so the form's hidden field and back
            // link can only ever carry the detections listing path.
            'return_to' => $this->trustedReturnTo($request->query->get('return_to')),
        ]);
    }

    #[Route('/review/{id}', name: 'app_review_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        $detection = $this->detection($id);
        $this->reviewService->save($detection, $this->parseSubmission($request));

        // Back to the detections list the reviewer came from: with the
        // default unreviewed filter the just-saved object drops out of the
        // queue, so the next one is already on top.
        return $this->redirect(
            $this->trustedReturnTo($request->request->get('return_to')) ?? self::DETECTIONS_LIST_PATH,
        );
    }

    #[Route('/detections/{id}/quick-classify', name: 'app_detection_quick_classify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quickClassify(int $id, Request $request): Response
    {
        $detection = $this->detection($id);
        $identityLabel = $this->quickClassifyLabel($request->request->get('label'));

        try {
            $this->reviewService->confirmIdentity($detection, $identityLabel);
        } catch (\DomainException $exception) {
            // The card dropdown only ever offers kind-matching labels; a
            // hand-crafted request asking for a mismatched one is a bad
            // request, not a server error (design.md D5).
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        // Same return-to-the-list contract as the review save: with the
        // default unreviewed filter the just-classified object drops out of
        // the queue, so the next one is already on top.
        return $this->redirect(
            $this->trustedReturnTo($request->request->get('return_to')) ?? self::DETECTIONS_LIST_PATH,
        );
    }

    #[Route('/review/false-positive-reasons', name: 'app_review_fp_reasons', methods: ['GET'])]
    public function falsePositiveReasons(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        return $this->json([
            'suggestions' => $this->detectionRepository->falsePositiveReasonSuggestions($query === '' ? null : $query),
        ]);
    }

    #[Route('/review/bulk', name: 'app_review_bulk', methods: ['POST'])]
    public function bulkClassify(Request $request): Response
    {
        $validity = $this->parseValidity($request->request->get('validity'));
        $falsePositiveReason = $this->optionalText($request->request->get('false_positive_reason'));
        $detections = $this->selectedDetections($request->request->all('detection_ids'));

        $this->reviewService->bulkClassify($detections, $validity, $falsePositiveReason);

        // Same return-to-the-list contract as the single save: preserve the
        // reviewer's filter context when it was carried through the form.
        return $this->redirect(
            $this->trustedReturnTo($request->request->get('return_to')) ?? self::DETECTIONS_LIST_PATH,
        );
    }

    /**
     * The local detections-list path a form asked to return to, or null when
     * absent or untrustworthy. Only "/detections" with an optional query
     * string is accepted, so a tampered return_to can never redirect the
     * reviewer off-site or onto another route (open-redirect guard).
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

    /**
     * The detections named by a bulk-selection form, in submitted order.
     * Every submitted id must exist; a partial selection is never silently
     * classified (fail fast).
     *
     * @param list<mixed> $submittedIds
     *
     * @return list<Detection>
     */
    private function selectedDetections(array $submittedIds): array
    {
        if ($submittedIds === []) {
            throw new BadRequestHttpException('Select at least one object to classify.');
        }

        $requestedIds = [];

        foreach ($submittedIds as $submittedId) {
            if (!is_string($submittedId) || !ctype_digit(trim($submittedId))) {
                throw new BadRequestHttpException(sprintf(
                    'Detection id "%s" is not numeric.',
                    is_scalar($submittedId) ? (string) $submittedId : get_debug_type($submittedId),
                ));
            }

            $requestedIds[] = (int) trim($submittedId);
        }

        $uniqueIds = array_values(array_unique($requestedIds));

        $found = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Detection::class, 'd')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', $uniqueIds)
            ->getQuery()
            ->getResult();

        $detectionsById = [];

        foreach ($found as $detection) {
            $detectionsById[$detection->getId()] = $detection;
        }

        $missingIds = array_values(array_filter(
            $uniqueIds,
            static fn (int $id): bool => !\array_key_exists($id, $detectionsById),
        ));

        if ($missingIds !== []) {
            throw new BadRequestHttpException(sprintf(
                'Unknown detection ids: %s.',
                implode(', ', $missingIds),
            ));
        }

        return array_map(
            static fn (int $id): Detection => $detectionsById[$id],
            $uniqueIds,
        );
    }

    #[Route('/event/{frigateEventId}/manual-object', name: 'app_manual_object_create', methods: ['POST'])]
    public function createManualObject(string $frigateEventId, Request $request): Response
    {
        // Parse the drawn box first so a missing box is a clean 400 even
        // before looking anything up.
        $box = CorrectedBoxInput::required($request->request);

        $event = $this->detectionRepository->eventByFrigateEventId($frigateEventId);

        if ($event === null) {
            throw new NotFoundHttpException(sprintf('Frigate event "%s" does not exist.', $frigateEventId));
        }

        $snapshot = $this->detectionRepository->firstSnapshotForEvent($event);

        if ($snapshot === null) {
            throw new NotFoundHttpException(sprintf('Frigate event "%s" has no snapshot to draw on.', $frigateEventId));
        }

        $detection = $this->reviewService->createManualObject($event, $snapshot, $box);

        return $this->redirectToRoute('app_review_object', ['id' => (int) $detection->getId()]);
    }

    private function parseSubmission(Request $request): ReviewSubmission
    {
        $parsedBox = CorrectedBoxInput::optional($request->request);

        return new ReviewSubmission(
            validity: $this->parseValidity($request->request->get('validity')),
            identityLabel: $this->parseIdentityLabel($request->request->get('identity_label_id')),
            correctedBox: $parsedBox->box,
            correctedBoxProvided: $parsedBox->provided,
            falsePositiveReason: $this->optionalText($request->request->get('false_positive_reason')),
            reviewerNotes: $this->optionalText($request->request->get('reviewer_notes')),
        );
    }

    private function parseValidity(mixed $value): ReviewValidity
    {
        if (!is_string($value)) {
            throw new BadRequestHttpException('Validity must be provided.');
        }

        $validity = ReviewValidity::tryFrom(trim($value));

        if ($validity === null) {
            throw new BadRequestHttpException(sprintf('Unknown validity "%s".', $value));
        }

        return $validity;
    }

    private function parseIdentityLabel(mixed $value): ?Label
    {
        if (!is_string($value) || trim($value) === '') {
            return null; // No identity selected: identity is clearable.
        }

        if (!ctype_digit(trim($value))) {
            throw new BadRequestHttpException(sprintf('Identity label id "%s" must be numeric.', $value));
        }

        $label = $this->entityManager->find(Label::class, (int) trim($value));

        if ($label === null) {
            throw new BadRequestHttpException(sprintf('Unknown identity label "%s".', $value));
        }

        return $label;
    }

    /**
     * The identity label a quick-classify request names, strictly by label
     * id: unknown or inactive labels are rejected rather than silently
     * skipped (fail fast — the UI only ever submits ids it rendered).
     */
    private function quickClassifyLabel(mixed $value): Label
    {
        if (!\is_string($value) || !ctype_digit(trim($value))) {
            throw new BadRequestHttpException(sprintf(
                'Quick-classify label "%s" must be a numeric label id.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        $label = $this->entityManager->find(Label::class, (int) trim($value));

        if ($label === null || !$label->isActive()) {
            throw new BadRequestHttpException(sprintf('Unknown identity label "%s".', trim($value)));
        }

        return $label;
    }

    private function optionalText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Labels the reviewer may assign to this object, per {@see LabelPolicy}
     * (design.md D5, spec "Kind constrains source classes").
     *
     * @return list<Label>
     */
    private function assignableLabels(Detection $detection): array
    {
        return LabelPolicy::assignableTo($detection, $this->labelRepository->findActive());
    }

    private function detection(int $id): Detection
    {
        $detection = $this->entityManager->find(Detection::class, $id);

        if ($detection === null) {
            throw new NotFoundHttpException(sprintf('Detection %d does not exist.', $id));
        }

        return $detection;
    }
}
