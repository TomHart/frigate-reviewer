<?php

declare(strict_types=1);

namespace App\Frigate;

/**
 * The width and height of a camera's detection frame, used to normalise
 * Frigate's pixel bounding boxes to 0..1 coordinates.
 */
final readonly class FrameDimensions
{
    public function __construct(
        public float $width,
        public float $height,
    ) {
        if ($this->width <= 0 || $this->height <= 0) {
            throw new \InvalidArgumentException('Frame dimensions must be positive.');
        }
    }
}
