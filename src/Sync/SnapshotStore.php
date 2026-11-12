<?php

declare(strict_types=1);

namespace App\Sync;

use App\Domain\PerceptualHash;
use App\Entity\Snapshot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores original snapshot bytes exactly once, keyed by their SHA-256
 * content hash (design.md D3). The original file is written content-
 * addressed and never overwritten; the database row is deduplicated, so
 * identical snapshot bytes across events share one row.
 *
 * The row is persisted but NOT flushed: the caller owns the transaction,
 * so snapshot file + event + detection commit together.
 */
final class SnapshotStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $storageRoot,
    ) {
    }

    /**
     * @throws \RuntimeException when the original file cannot be written.
     */
    public function storeOriginal(string $bytes): Snapshot
    {
        $contentHash = hash('sha256', $bytes);

        $existing = $this->entityManager
            ->getRepository(Snapshot::class)
            ->findOneBy(['contentHash' => $contentHash]);

        if ($existing !== null) {
            return $existing;
        }

        $originalPath = $this->originalFilePath($contentHash);
        $this->writeAtomically($originalPath, $bytes);

        $snapshot = new Snapshot($contentHash, $originalPath);
        // dHash of the original bytes at ingest (design.md D8). Best-effort:
        // undecodable bytes leave the hash null and the snapshot simply never
        // joins a similarity group — it is still stored in full.
        $perceptualHash = PerceptualHash::fromImageBytes($bytes);

        if ($perceptualHash !== null) {
            $snapshot->setPerceptualHash($perceptualHash->toHex());
        }

        $this->entityManager->persist($snapshot);

        return $snapshot;
    }

    private function originalFilePath(string $contentHash): string
    {
        return $this->storageRoot.'/originals/'.substr($contentHash, 0, 2).'/'.$contentHash.'.jpg';
    }

    private function writeAtomically(string $path, string $bytes): void
    {
        if (is_file($path)) {
            return; // Content-addressed: an existing file is already the same bytes.
        }

        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new \RuntimeException(sprintf('Unable to create snapshot directory "%s".', $directory));
        }

        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (file_put_contents($temporaryPath, $bytes) === false) {
            throw new \RuntimeException(sprintf('Unable to write snapshot file "%s".', $temporaryPath));
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Unable to move snapshot file into place at "%s".', $path));
        }
    }
}
