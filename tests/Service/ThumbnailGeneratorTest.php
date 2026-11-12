<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Snapshot;
use App\Service\ThumbnailGenerator;
use App\Tests\AbstractDatabaseTestCase;

final class ThumbnailGeneratorTest extends AbstractDatabaseTestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir().'/frigate-review-thumb-test-'.bin2hex(random_bytes(8));
        $this->bootDatabase();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function testGeneratesThumbnailOnFirstRequestAndCachesIt(): void
    {
        $snapshot = $this->createSnapshotWithOriginal(640, 480);
        $originalHashBefore = hash_file('sha256', $snapshot->getOriginalPath());

        $thumbnailPath = $this->generator()->ensureThumbnail($snapshot);

        self::assertFileExists($thumbnailPath);
        self::assertStringContainsString('/thumbs/', $thumbnailPath);

        // Thumbnail is a downscaled copy; the original is never re-encoded.
        [$thumbnailWidth, $thumbnailHeight] = getimagesize($thumbnailPath);
        self::assertSame(320, $thumbnailWidth);
        self::assertSame(240, $thumbnailHeight);
        self::assertSame($originalHashBefore, hash_file('sha256', $snapshot->getOriginalPath()));

        // The path is persisted on the snapshot row.
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(Snapshot::class, $snapshot->getId());
        self::assertSame($thumbnailPath, $stored->getThumbnailPath());
    }

    public function testCachedThumbnailIsServedWithoutTouchingTheOriginal(): void
    {
        $snapshot = $this->createSnapshotWithOriginal(640, 480);
        $generator = $this->generator();
        $thumbnailPath = $generator->ensureThumbnail($snapshot);

        // Remove the original entirely: serving the cached thumbnail must
        // not need it (and must certainly never rewrite it).
        unlink($snapshot->getOriginalPath());

        self::assertSame($thumbnailPath, $generator->ensureThumbnail($snapshot));
        self::assertFileExists($thumbnailPath);
    }

    public function testSmallOriginalsAreNeverUpscaled(): void
    {
        $snapshot = $this->createSnapshotWithOriginal(160, 120);

        $thumbnailPath = $this->generator()->ensureThumbnail($snapshot);

        [$thumbnailWidth, $thumbnailHeight] = getimagesize($thumbnailPath);
        self::assertSame(160, $thumbnailWidth);
        self::assertSame(120, $thumbnailHeight);
    }

    public function testUndecodableOriginalFailsLoudly(): void
    {
        $snapshot = $this->createSnapshotWithOriginalBytes('not-an-image');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a decodable image');

        $this->generator()->ensureThumbnail($snapshot);
    }

    public function testMissingOriginalFailsLoudly(): void
    {
        $snapshot = $this->createSnapshotWithOriginalBytes('jpeg-bytes');
        unlink($snapshot->getOriginalPath());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing');

        $this->generator()->ensureThumbnail($snapshot);
    }

    private function generator(): ThumbnailGenerator
    {
        return new ThumbnailGenerator($this->entityManager(), $this->storageRoot);
    }

    private function createSnapshotWithOriginal(int $width, int $height): Snapshot
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 120, 200));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return $this->createSnapshotWithOriginalBytes($bytes);
    }

    private function createSnapshotWithOriginalBytes(string $bytes): Snapshot
    {
        $originalPath = $this->storageRoot.'/originals/'.substr(hash('sha256', $bytes), 0, 2).'/'.hash('sha256', $bytes).'.jpg';

        if (!is_dir(\dirname($originalPath))) {
            mkdir(\dirname($originalPath), 0775, true);
        }
        file_put_contents($originalPath, $bytes);

        $snapshot = new Snapshot(hash('sha256', $bytes), $originalPath);
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
