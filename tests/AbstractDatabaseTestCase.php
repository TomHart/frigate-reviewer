<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\BoundingBox;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestAssertionsTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Boots a kernel against a fresh in-memory SQLite database and runs the
 * Doctrine migrations, so tests exercise the real schema including seeds.
 */
abstract class AbstractDatabaseTestCase extends KernelTestCase
{
    use WebTestAssertionsTrait;

    protected function bootDatabase(): void
    {
        self::bootKernel();
        $this->runMigrations();
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    protected function createFrigateDetection(string $sourceClass = 'person'): Detection
    {
        $detection = Detection::fromFrigate(
            $this->createFrigateEvent(),
            $this->createSnapshot(),
            $sourceClass,
            0.87,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
            new \DateTimeImmutable('2026-11-12 10:00:05'),
            new BoundingBox(0.1, 0.2, 0.4, 0.5),
        );

        $this->persist($detection);

        return $detection;
    }

    protected function createManualDetection(): Detection
    {
        $detection = Detection::manual(
            $this->createSnapshot(),
            new BoundingBox(0.3, 0.3, 0.2, 0.2),
        );

        $this->persist($detection);

        return $detection;
    }

    protected function persist(object ...$entities): void
    {
        $entityManager = $this->entityManager();
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    /**
     * A kernel test client for functional tests. Kernel reboot between
     * requests is disabled so the in-memory SQLite database (and the booted
     * entity manager with its fixtures) survive across requests.
     */
    protected function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        \assert($client instanceof KernelBrowser);
        $client->disableReboot();
        self::getClient($client); // Register with the response-assertion trait.

        return $client;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    private function createFrigateEvent(): FrigateEvent
    {
        return new FrigateEvent(
            'event-'.bin2hex(random_bytes(8)),
            'front_door',
            new \DateTimeImmutable('2026-11-12 10:00:00'),
        );
    }

    private function createSnapshot(): Snapshot
    {
        return new Snapshot(
            hash('sha256', bin2hex(random_bytes(16))),
            '/snapshots/'.bin2hex(random_bytes(8)).'.jpg',
        );
    }

    private function runMigrations(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
                '--quiet' => true,
            ]),
            new NullOutput(),
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException('Doctrine migrations failed while preparing the test database.');
        }
    }
}
