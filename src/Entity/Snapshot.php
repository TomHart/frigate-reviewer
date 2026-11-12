<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An image stored exactly once, keyed by content hash; never overwritten
 * (design.md D3, spec "Immutable original snapshots"). Thumbnails are
 * generated lazily alongside the original.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['perceptual_hash'], name: 'idx_snapshot_perceptual_hash')]
class Snapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SHA-256 of the original file content; two snapshots never share a hash. */
    #[ORM\Column(unique: true)]
    private string $contentHash;

    #[ORM\Column]
    private string $originalPath;

    #[ORM\Column(nullable: true)]
    private ?string $thumbnailPath = null;

    /** Perceptual hash (dHash/pHash) computed at ingest, indexed for similarity grouping (design.md D8). */
    #[ORM\Column(nullable: true)]
    private ?string $perceptualHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $contentHash, string $originalPath)
    {
        $this->contentHash = $contentHash;
        $this->originalPath = $originalPath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getOriginalPath(): string
    {
        return $this->originalPath;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(string $thumbnailPath): void
    {
        $this->thumbnailPath = $thumbnailPath;
    }

    public function getPerceptualHash(): ?string
    {
        return $this->perceptualHash;
    }

    public function setPerceptualHash(string $perceptualHash): void
    {
        $this->perceptualHash = $perceptualHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
