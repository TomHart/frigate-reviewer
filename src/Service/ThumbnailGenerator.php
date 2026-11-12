<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Snapshot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates web thumbnails lazily, on the first UI request, and caches them
 * on the volume next to the originals (design.md D6). The original snapshot
 * file is only ever read, never re-encoded or modified; GD is used locally,
 * so no image data ever leaves the environment.
 */
final class ThumbnailGenerator
{
    private const MAX_DIMENSION = 320;
    private const JPEG_QUALITY = 85;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $storageRoot,
    ) {
    }

    /**
     * Returns the absolute thumbnail path, generating the thumbnail on the
     * first call and serving the cached file thereafter.
     *
     * @throws \RuntimeException when the original snapshot cannot be read or decoded.
     */
    public function ensureThumbnail(Snapshot $snapshot): string
    {
        $thumbnailPath = $snapshot->getThumbnailPath();

        if ($thumbnailPath !== null && is_file($thumbnailPath)) {
            return $thumbnailPath; // Already generated: never touch the original again.
        }

        return $this->generateThumbnail($snapshot);
    }

    private function generateThumbnail(Snapshot $snapshot): string
    {
        $originalPath = $snapshot->getOriginalPath();

        if (!is_file($originalPath)) {
            throw new \RuntimeException(sprintf('Original snapshot file "%s" is missing.', $originalPath));
        }

        $originalBytes = file_get_contents($originalPath);

        if ($originalBytes === false) {
            throw new \RuntimeException(sprintf('Original snapshot file "%s" could not be read.', $originalPath));
        }

        $original = @imagecreatefromstring($originalBytes); // GD emits its own warning before failing; we fail loudly instead.

        if ($original === false) {
            throw new \RuntimeException(sprintf('Original snapshot file "%s" is not a decodable image.', $originalPath));
        }

        $thumbnail = self::downscaled($original);
        $thumbnailPath = $this->thumbnailFilePath($snapshot->getContentHash());
        self::writeAtomically($thumbnailPath, $thumbnail);

        $snapshot->setThumbnailPath($thumbnailPath);
        $this->entityManager->flush();

        return $thumbnailPath;
    }

    private function thumbnailFilePath(string $contentHash): string
    {
        return $this->storageRoot.'/thumbs/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';
    }

    /**
     * A GD image scaled down so its longest side fits MAX_DIMENSION. Small
     * originals are never upscaled.
     *
     * @param \GdImage $original
     */
    private static function downscaled(\GdImage $original): \GdImage
    {
        $originalWidth = imagesx($original);
        $originalHeight = imagesy($original);
        $scale = min(1.0, self::MAX_DIMENSION / max($originalWidth, $originalHeight));

        $width = max(1, (int) round($originalWidth * $scale));
        $height = max(1, (int) round($originalHeight * $scale));

        $thumbnail = imagecreatetruecolor($width, $height);
        imagecopyresampled($thumbnail, $original, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);

        return $thumbnail;
    }

    private static function writeAtomically(string $path, \GdImage $thumbnail): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new \RuntimeException(sprintf('Unable to create thumbnail directory "%s".', $directory));
        }

        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (!imagejpeg($thumbnail, $temporaryPath, self::JPEG_QUALITY)) {
            throw new \RuntimeException(sprintf('Unable to write thumbnail file "%s".', $temporaryPath));
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Unable to move thumbnail into place at "%s".', $path));
        }
    }
}
