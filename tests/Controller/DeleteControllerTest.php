<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\BoundingBox;
use App\Domain\DeleteConfirmationToken;
use App\Domain\ReviewService;
use App\Enum\ReviewValidity;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Review;
use App\Entity\Snapshot;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Confirmed permanent deletion from the UI (design.md D11, task 8.2): the
 * confirmation page hands out the token, the POST requires it, and a
 * confirmed POST deletes files and every dependent row in one transaction.
 */
final class DeleteControllerTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    /** Varies each generated image so content hashes (and files) never collide. */
    private static int $imageSequence = 0;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-delete-controller-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testConfirmationPageShowsWhatWillBeDeleted(): void
    {
        $detection = $this->createDetectionWithFiles('front_door');

        $client = $this->client();
        $crawler = $client->request('GET', '/detection/'.$detection->getId().'/delete');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Permanently delete this image?');
        self::assertSelectorExists('form[action$="/delete"] input[name="confirm_token"]');
        self::assertStringContainsString(
            substr($detection->getSnapshot()->getContentHash(), 0, 12),
            (string) $crawler->filter('section')->first()->text(),
        );
    }

    public function testPostWithoutTokenIsRejectedAndDeletesNothing(): void
    {
        $detection = $this->createDetectionWithFiles('front_door');

        $client = $this->client();
        $client->request('POST', '/detection/'.$detection->getId().'/delete', ['confirm_token' => '']);

        self::assertResponseStatusCodeSame(400);

        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Snapshot::class, $detection->getSnapshot()->getId()));
        self::assertFileExists($detection->getSnapshot()->getOriginalPath());
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        $detection = $this->createDetectionWithFiles('front_door');

        $client = $this->client();
        $client->request('POST', '/detection/'.$detection->getId().'/delete', ['confirm_token' => 'forged']);

        self::assertResponseStatusCodeSame(400);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Snapshot::class, $detection->getSnapshot()->getId()));
    }

    public function testConfirmedPostDeletesEverythingAndRedirectsToOrphanedListing(): void
    {
        $detection = $this->createDetectionWithFiles('front_door');
        $snapshot = $detection->getSnapshot();
        $event = $detection->getEvent();

        $reviewService = new ReviewService($this->entityManager());
        $reviewService->setValidity($detection, ReviewValidity::FalsePositive);

        $snapshotId = $snapshot->getId();
        $detectionId = $detection->getId();
        $eventId = $event->getId();

        $token = DeleteConfirmationToken::forSnapshot($snapshot, $this->secret());

        $client = $this->client();
        $client->request('POST', '/detection/'.$detection->getId().'/delete', ['confirm_token' => $token]);

        self::assertResponseRedirects('/detections');

        $entityManager = $this->entityManager();
        $entityManager->clear();

        self::assertNull($entityManager->find(Snapshot::class, $snapshotId));
        self::assertNull($entityManager->find(Detection::class, $detectionId));
        self::assertNull($entityManager->find(FrigateEvent::class, $eventId), 'Orphaned event is deleted with its last object.');
        self::assertCount(0, $entityManager->getRepository(Review::class)->findAll(), 'Review is deleted with its detection.');
        self::assertCount(0, $entityManager->getRepository(\App\Entity\AuditEntry::class)->findAll(), 'Audit entries are deleted with their detection.');
        self::assertFileDoesNotExist($snapshot->getOriginalPath());
        self::assertFileDoesNotExist((string) $snapshot->getThumbnailPath());
    }

    public function testConfirmedPostKeepsEventThatStillHasOtherDetections(): void
    {
        $event = new FrigateEvent(
            'event-'.bin2hex(random_bytes(8)),
            'front_door',
            new \DateTimeImmutable('2026-11-12 10:00:00'),
        );

        $deletedDetection = $this->createDetectionWithFiles('front_door', $event);
        $survivingDetection = $this->createDetectionWithFiles('front_door', $event);

        $deletedDetectionId = $deletedDetection->getId();

        $token = DeleteConfirmationToken::forSnapshot($deletedDetection->getSnapshot(), $this->secret());

        $client = $this->client();
        $client->request('POST', '/detection/'.$deletedDetection->getId().'/delete', ['confirm_token' => $token]);

        self::assertResponseRedirects('/event/'.$event->getFrigateEventId());

        $entityManager = $this->entityManager();
        $entityManager->clear();

        self::assertNotNull($entityManager->find(FrigateEvent::class, $event->getId()));
        self::assertNotNull($entityManager->find(Detection::class, $survivingDetection->getId()));
        self::assertNull($entityManager->find(Detection::class, $deletedDetectionId));
    }

    public function testUnknownDetectionIsNotFound(): void
    {
        $this->client()->request('GET', '/detection/99999/delete');
        self::assertResponseStatusCodeSame(404);
    }

    private function createDetectionWithFiles(string $camera, ?FrigateEvent $event = null): Detection
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 320, 240, imagecolorallocate($image, 40 + self::$imageSequence++, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        $contentHash = hash('sha256', $bytes);
        $originalPath = $this->storageRoot.'/originals/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';
        $thumbnailPath = $this->storageRoot.'/thumbs/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';

        foreach ([$originalPath, $thumbnailPath] as $path) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0775, true);
            }
            file_put_contents($path, $bytes);
        }

        $snapshot = new Snapshot($contentHash, $originalPath);
        $snapshot->setThumbnailPath($thumbnailPath);

        $event ??= new FrigateEvent(
            'event-'.bin2hex(random_bytes(8)),
            $camera,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
        );

        $detection = Detection::fromFrigate(
            $event,
            $snapshot,
            'person',
            0.87,
            new \DateTimeImmutable('2026-11-12 10:00:00'),
            new \DateTimeImmutable('2026-11-12 10:00:05'),
            new BoundingBox(0.1, 0.2, 0.4, 0.5),
        );

        $this->persist($snapshot, $event, $detection);

        return $detection;
    }

    private function secret(): string
    {
        $secret = self::getContainer()->getParameter('kernel.secret');
        \assert(\is_string($secret));

        return $secret;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file
        ) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
