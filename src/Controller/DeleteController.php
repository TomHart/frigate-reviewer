<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\DeleteConfirmationToken;
use App\Entity\Detection;
use App\Entity\Snapshot;
use App\Retention\SnapshotDeletionService;
use App\Repository\DetectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmed permanent deletion of a snapshot image (design.md D11, tasks 8.2).
 * The image is content-addressed and may be shared by several detections
 * (design.md D3): deleting it permanently deletes the image file(s), its
 * thumbnail, and every object annotated on it — reviews, audit entries —
 * plus any Frigate event that becomes orphaned, all in one transaction.
 * Flow: GET renders the confirmation page (which alone hands out the token),
 * POST with that token performs the deletion. No tombstones.
 */
final class DeleteController extends AbstractController
{
    public function __construct(
        private readonly DetectionRepository $detectionRepository,
        private readonly SnapshotDeletionService $deletionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/detection/{id}/delete', name: 'app_detection_delete_confirm', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirm(int $id): Response
    {
        $detection = $this->detectionOrNotFound($id);
        $snapshot = $this->snapshotFor($detection);

        return $this->render('detection/delete_confirm.html.twig', [
            'detection' => $detection,
            'snapshot' => $snapshot,
            'sharedDetections' => $this->detectionRepository->detectionsReferencingSnapshot($snapshot),
            'confirmToken' => DeleteConfirmationToken::forSnapshot($snapshot, $this->secret()),
        ]);
    }

    #[Route('/detection/{id}/delete', name: 'app_detection_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $detection = $this->detectionOrNotFound($id);
        $snapshot = $this->snapshotFor($detection);

        $submittedToken = $request->request->getString('confirm_token', '');

        if (!DeleteConfirmationToken::matches($submittedToken, $snapshot, $this->secret())) {
            throw new BadRequestHttpException(
                'Permanent deletion requires the confirmation token from the confirmation page; nothing was deleted.',
            );
        }

        $event = $detection->getEvent();

        $this->deletionService->deleteSnapshot($snapshot);

        // Redirect to the event that remains, or to the listing when the
        // event was deleted with its last detection.
        if ($event !== null && $this->detectionRepository->eventByFrigateEventId($event->getFrigateEventId()) !== null) {
            return $this->redirectToRoute('app_event_detail', ['frigateEventId' => $event->getFrigateEventId()]);
        }

        return $this->redirectToRoute('app_detection_list');
    }

    private function detectionOrNotFound(int $id): Detection
    {
        $detection = $this->entityManager->find(Detection::class, $id);

        if ($detection === null) {
            throw new NotFoundHttpException(sprintf('Detection %d does not exist.', $id));
        }

        return $detection;
    }

    private function snapshotFor(Detection $detection): Snapshot
    {
        $snapshot = $detection->getSnapshot();

        if ($snapshot === null) {
            throw new BadRequestHttpException('This object has no snapshot image to delete.');
        }

        return $snapshot;
    }

    private function secret(): string
    {
        return (string) $this->getParameter('kernel.secret');
    }
}
