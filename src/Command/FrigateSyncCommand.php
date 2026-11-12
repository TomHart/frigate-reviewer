<?php

declare(strict_types=1);

namespace App\Command;

use App\Frigate\EventListQuery;
use App\Sync\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The canonical sync entry point (design.md D6). Safe to re-run: events
 * already stored are skipped and their Frigate facts are never modified.
 */
#[AsCommand(
    name: 'frigate:sync',
    description: 'Sync events, detections and snapshots from the Frigate HTTP API (read-only).',
)]
final class FrigateSyncCommand extends Command
{
    public function __construct(
        private readonly SyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('camera', null, InputOption::VALUE_REQUIRED, 'Only sync events from this camera.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of events to sync.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitOption = $input->getOption('limit');

        if (is_string($limitOption) && !ctype_digit($limitOption)) {
            $io->error('--limit must be a non-negative integer.');

            return Command::FAILURE;
        }

        $result = $this->syncService->sync(new EventListQuery(
            camera: self::stringOption($input->getOption('camera')),
            maxResults: is_string($limitOption) ? (int) $limitOption : null,
        ));

        foreach ($result->errors as $error) {
            $io->error($error);
        }

        $io->success(sprintf(
            'Stored %d new event(s), skipped %d already synced.',
            $result->storedEvents,
            $result->skippedEvents,
        ));

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    private static function stringOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
