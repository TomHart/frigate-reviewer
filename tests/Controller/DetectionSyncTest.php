<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Snapshot;
use App\Frigate\HttpFrigateApiClient;
use App\Sync\SnapshotStore;
use App\Sync\SyncService;
use App\Tests\AbstractDatabaseTestCase;
use App\Tests\Sync\FakeFrigateApiClient;

/**
 * Functional tests for the web-triggered sync (design.md D6): the listing
 * page's "Sync Events" button reuses the canonical SyncService directly —
 * the console command stays the canonical entry point. The container's
 * Frigate client is swapped for the in-memory fake, exactly as the sync
 * service tests do, so no network is touched.
 */
final class DetectionSyncTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testSyncStoresEventsAndRedirectsBackWithFlashCounts(): void
    {
        $this->useFakeFrigate(new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload(
                    'e1',
                    'front_door',
                    1762900000,
                    1762900005,
                    'person',
                    0.87,
                    [0.1, 0.2, 0.5, 0.5],
                ),
                FakeFrigateApiClient::eventPayload(
                    'e2',
                    'front_door',
                    1762900100,
                    1762900110,
                    'dog',
                    0.5,
                    [0.05, 0.05, 0.25, 0.25],
                ),
            ],
            snapshotBytesByEventId: [
                'e1' => $this->jpegBytes(),
                'e2' => $this->jpegBytes(80),
            ],
        ));

        $client = $this->client();
        $client->request('POST', '/detections/sync', [
            'return_to' => '/detections?camera=front_door&review_state=all',
        ]);

        self::assertResponseRedirects('/detections?camera=front_door&review_state=all');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            '.flash-success',
            'Stored 2 new event(s), skipped 0 already synced.',
        );

        self::assertSame(2, $this->entityCount(Detection::class));
        self::assertSame(2, $this->entityCount(Snapshot::class));
    }

    public function testSyncSkipsAlreadySyncedEvents(): void
    {
        $this->persist(new FrigateEvent(
            'e1',
            'front_door',
            new \DateTimeImmutable('@1762900000'),
        ));

        $this->useFakeFrigate(new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload(
                    'e1',
                    'front_door',
                    1762900000,
                    1762900005,
                    'person',
                    0.87,
                    [0.1, 0.2, 0.5, 0.5],
                ),
            ],
            snapshotBytesByEventId: ['e1' => $this->jpegBytes()],
        ));

        $client = $this->client();
        $client->request('POST', '/detections/sync');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            '.flash-success',
            'Stored 0 new event(s), skipped 1 already synced.',
        );
        self::assertSame(0, $this->entityCount(Snapshot::class), 'skipped events must not be re-downloaded');
    }

    public function testSyncErrorsSurfaceInFlashErrorBag(): void
    {
        $this->useFakeFrigate(new FakeFrigateApiClient(
            events: [
                FakeFrigateApiClient::eventPayload(
                    'e1',
                    'front_door',
                    1762900000,
                    1762900005,
                    'person',
                    0.87,
                    [0.1, 0.2, 0.5, 0.5],
                ),
            ],
            snapshotFailuresByEventId: ['e1' => 'snapshot download failed'],
        ));

        $client = $this->client();
        $client->request('POST', '/detections/sync');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            '.flash-success',
            'Stored 0 new event(s), skipped 0 already synced.',
        );
        self::assertSelectorTextContains('.flash-error', 'Event e1: snapshot download failed');
    }

    public function testInvalidLimitIsRejectedWith400(): void
    {
        $this->useFakeFrigate(new FakeFrigateApiClient());

        $client = $this->client();

        foreach (['0', '-1', 'banana', '501'] as $invalidLimit) {
            $client->request('POST', '/detections/sync?limit='.$invalidLimit);

            self::assertResponseStatusCodeSame(400, sprintf('limit "%s" must be rejected.', $invalidLimit));
        }
    }

    public function testLimitAtCapIsAccepted(): void
    {
        $this->useFakeFrigate(new FakeFrigateApiClient());

        $client = $this->client();
        $client->request('POST', '/detections/sync?limit=500');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            '.flash-success',
            'Stored 0 new event(s), skipped 0 already synced.',
        );
    }

    public function testGetRequestIsMethodNotAllowed(): void
    {
        $this->client()->request('GET', '/detections/sync');

        self::assertResponseStatusCodeSame(405);
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * Swaps the container's Frigate client (and the snapshot storage root,
     * so tests never write into the real storage volume) before the request
     * builds the SyncService.
     */
    private function useFakeFrigate(FakeFrigateApiClient $fake): void
    {
        $container = self::getContainer();
        $container->set(HttpFrigateApiClient::class, $fake);
        $container->set(SnapshotStore::class, new SnapshotStore(
            $this->entityManager(),
            $this->storageRoot,
        ));
    }

    private function entityCount(string $entityClass): int
    {
        return \count($this->entityManager()->getRepository($entityClass)->findAll());
    }

    private function jpegBytes(int $size = 100): string
    {
        $image = imagecreatetruecolor($size, $size);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $black);

        $stripes = max(1, (int) ($size / 10));

        for ($stripe = 0; $stripe < $stripes; ++$stripe) {
            if ($stripe % 2 === 1) {
                imagefilledrectangle($image, 0, $stripe * 10, $size - 1, $stripe * 10 + 9, $white);
            }
        }

        \ob_start();
        imagejpeg($image, null, 90);

        return (string) \ob_get_clean();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
