<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A rectangular bounding box with coordinates normalised to the snapshot
 * dimensions (0..1). The original Frigate box lives on Detection; the
 * reviewer-corrected box lives on Review (design.md D3).
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Bounding box width and height must be positive.');
        }
        if ($x < 0 || $y < 0) {
            throw new \InvalidArgumentException('Bounding box x and y must not be negative.');
        }
    }
}
