<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SnapshotThumbnailController;
use App\Entity\Snapshot;
use App\Service\ThumbnailGenerator;
use App\Tests\AbstractDatabaseTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SnapshotThumbnailControllerTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-controller-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testServesGeneratedThumbnailAsJpeg(): void
    {
        $snapshot = $this->createSnapshotWithOriginal();

        $response = $this->controller()->thumbnail((int) $snapshot->getId(), $this->generator());
        $response->prepare(new \Symfony\Component\HttpFoundation\Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertFileExists($snapshot->getThumbnailPath());
    }

    public function testUnknownSnapshotIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->thumbnail(999999, $this->generator());
    }

    private function controller(): SnapshotThumbnailController
    {
        return new SnapshotThumbnailController($this->entityManager());
    }

    private function generator(): ThumbnailGenerator
    {
        return new ThumbnailGenerator($this->entityManager(), $this->storageRoot);
    }

    private function createSnapshotWithOriginal(): Snapshot
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 0, 0, 320, 240, imagecolorallocate($image, 40, 120, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        $contentHash = hash('sha256', $bytes);
        $originalPath = $this->storageRoot.'/originals/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';

        if (!is_dir(\dirname($originalPath))) {
            mkdir(\dirname($originalPath), 0775, true);
        }
        file_put_contents($originalPath, $bytes);

        $snapshot = new Snapshot($contentHash, $originalPath);
        $this->persist($snapshot);

        return $snapshot;
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
