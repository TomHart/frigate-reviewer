<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * 64-bit difference hash (dHash) of a snapshot image (design.md D8). The
 * image is downsampled to a 9x8 grayscale grid; each of the eight rows
 * contributes eight bits, one per horizontal neighbour comparison ("the
 * pixel to the right is brighter than the pixel to the left"). The 64 bits
 * are stored as a 16-character lowercase hex string on the snapshot row and
 * indexed there.
 *
 * Similarity between two snapshots is the Hamming distance between their
 * hashes, computed in PHP over the hex bytes — no SQL bit operations, so the
 * grouping works identically on SQLite and PostgreSQL.
 */
final readonly class PerceptualHash
{
    private const GRID_WIDTH = 9;
    private const GRID_HEIGHT = 8;
    private const BYTE_COUNT = 8;
    public const HEX_LENGTH = 16;

    private function __construct(private string $hex)
    {
    }

    /**
     * @throws \InvalidArgumentException when $hex is not a 16-character
     *         lowercase hex string.
     */
    public static function fromHex(string $hex): self
    {
        if (preg_match('/^[0-9a-f]{16}$/', $hex) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Perceptual hash "%s" is not a %d-character lowercase hex string.',
                $hex,
                self::HEX_LENGTH,
            ));
        }

        return new self($hex);
    }

    /**
     * dHash of raw snapshot bytes, or null when the bytes are not a decodable
     * image. Hashing is best-effort by design: a snapshot whose bytes cannot
     * be decoded simply never joins a similarity group; it is never rejected
     * from storage (the original bytes are preserved regardless, design.md D3).
     */
    public static function fromImageBytes(string $bytes): ?self
    {
        $image = @imagecreatefromstring($bytes); // GD emits its own warning before failing; we fail loudly elsewhere.

        if ($image === false) {
            return null;
        }

        return self::fromGdImage($image);
    }

    public static function fromGdImage(\GdImage $image): self
    {
        $grid = imagecreatetruecolor(self::GRID_WIDTH, self::GRID_HEIGHT);
        imagecopyresampled(
            $grid,
            $image,
            0,
            0,
            0,
            0,
            self::GRID_WIDTH,
            self::GRID_HEIGHT,
            imagesx($image),
            imagesy($image),
        );
        imagefilter($grid, IMG_FILTER_GRAYSCALE);

        $hex = '';

        for ($y = 0; $y < self::GRID_HEIGHT; ++$y) {
            $byte = 0;

            for ($x = 0; $x < self::GRID_HEIGHT; ++$x) {
                $rightIsBrighter = self::grayscaleValue($grid, $x + 1, $y) > self::grayscaleValue($grid, $x, $y);
                $byte = ($byte << 1) | (int) $rightIsBrighter;
            }

            $hex .= sprintf('%02x', $byte);
        }

        return new self($hex);
    }

    public function toHex(): string
    {
        return $this->hex;
    }

    public function hammingDistanceTo(self $other): int
    {
        $ownBytes = self::hashBytes($this->hex);
        $otherBytes = self::hashBytes($other->hex);

        $distance = 0;

        for ($i = 0; $i < self::BYTE_COUNT; ++$i) {
            $distance += substr_count(decbin(ord($ownBytes[$i]) ^ ord($otherBytes[$i])), '1');
        }

        return $distance;
    }

    private static function hashBytes(string $hex): string
    {
        $bytes = hex2bin($hex);

        if ($bytes === false || \strlen($bytes) !== self::BYTE_COUNT) {
            throw new \LogicException(sprintf('Perceptual hash "%s" does not decode to %d bytes.', $hex, self::BYTE_COUNT));
        }

        return $bytes;
    }

    private static function grayscaleValue(\GdImage $image, int $x, int $y): int
    {
        $color = imagecolorat($image, $x, $y);

        return ($color >> 16) & 0xFF; // Grayscale: red, green and blue are equal.
    }
}
