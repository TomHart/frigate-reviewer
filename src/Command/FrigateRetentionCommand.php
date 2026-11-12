<?php

declare(strict_types=1);

namespace App\Command;

use App\Retention\RetentionPolicyResolver;
use App\Retention\RetentionService;
use App\Repository\DetectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention enforcement (design.md D11): permanently deletes snapshots older
 * than the configured age limits — a global default plus per-camera overrides
 * from the environment (see RetentionPolicyResolver) — together with their
 * detections, reviews, audit entries, orphaned events, original files and
 * thumbnails.
 *
 * Designed for in-container scheduling, e.g. daily cron:
 *   15 3 * * * php /app/bin/console frigate:retention
 */
#[AsCommand(
    name: 'frigate:retention',
    description: 'Permanently delete snapshots older than the configured retention limits (global default plus per-camera overrides).',
)]
final class FrigateRetentionCommand extends Command
{
    public function __construct(
        private readonly RetentionPolicyResolver $policyResolver,
        private readonly RetentionService $retentionService,
        private readonly DetectionRepository $detectionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would be deleted without deleting anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $policy = $this->policyResolver->resolveForCameras(
            $this->detectionRepository->distinctCameras(),
        );

        if (!$policy->isConfigured()) {
            $io->note('No retention policy configured (RETENTION_DEFAULT_DAYS and RETENTION_CAMERA_*_DAYS are all unset); nothing to do.');

            return Command::SUCCESS;
        }

        $io->title('Retention policy');
        $io->definitionList(
            ['Global default' => $policy->defaultDays === null ? 'none (never expires)' : $policy->defaultDays.' days'],
            array_map(
                static fn (int $days): string => $days.' days',
                $policy->cameraDays,
            ) ?: ['(no per-camera overrides)' => '—'],
        );

        if ($input->getOption('dry-run')) {
            $expired = $this->retentionService->findExpiredSnapshots(new \DateTimeImmutable(), $policy);

            if ($expired === []) {
                $io->success('No snapshots are past their retention limit.');

                return Command::SUCCESS;
            }

            $io->warning(sprintf('%d snapshot(s) would be permanently deleted (dry run — nothing deleted):', \count($expired)));

            foreach ($expired as $candidate) {
                $io->writeln(sprintf(
                    '  snapshot #%d (hash %s…, stored %s, limit %d days, cameras: %s)',
                    $candidate->snapshot->getId(),
                    substr($candidate->snapshot->getContentHash(), 0, 12),
                    $candidate->snapshot->getCreatedAt()->format('Y-m-d'),
                    $candidate->limitDays,
                    $candidate->cameras === [] ? '(no camera)' : implode(', ', $candidate->cameras),
                ));
            }

            return Command::SUCCESS;
        }

        $result = $this->retentionService->enforce(new \DateTimeImmutable(), $policy);

        if ($result->deletedSnapshots === 0) {
            $io->success('No snapshots are past their retention limit.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Permanently deleted %d snapshot(s), %d object(s), %d review(s), %d audit entr(ies), %d orphaned event(s).',
            $result->deletedSnapshots,
            $result->deletedDetections,
            $result->deletedReviews,
            $result->deletedAuditEntries,
            $result->deletedEvents,
        ));

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        return Command::SUCCESS;
    }
}
