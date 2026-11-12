<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dataset-quality dashboard (spec "Dataset quality dashboard"): one page of
 * read-only statistics computed from the database, no external requests.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardRepository $dashboardRepository,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'stats' => $this->dashboardRepository->stats(),
        ]);
    }
}
