<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Snapshot;
use App\Service\ThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a snapshot's web thumbnail, generating it lazily on the first
 * request (design.md D6). The original snapshot file is never touched.
 */
final class SnapshotThumbnailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/snapshot/{id}/thumbnail', name: 'app_snapshot_thumbnail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function thumbnail(int $id, ThumbnailGenerator $thumbnailGenerator): Response
    {
        $snapshot = $this->entityManager->find(Snapshot::class, $id);

        if ($snapshot === null) {
            throw new NotFoundHttpException(sprintf('Snapshot %d does not exist.', $id));
        }

        $response = new BinaryFileResponse($thumbnailGenerator->ensureThumbnail($snapshot));
        $response->setAutoEtag();

        return $response;
    }
}
