<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\BoundingBox;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Parses a bounding box submitted by the SVG box editor (normalized
 * 0..1 coordinates in the box_x/box_y/box_width/box_height fields, with a
 * box_clear flag). Parsed at the boundary so controllers receive a trusted
 * {@see BoundingBox} or a clean "not provided" state.
 *
 * Encoding of the three states, checked in this precedence order:
 * - set: all four coordinates submitted as non-empty numbers, not all zero —
 *   store the box (non-positive dimensions are a 400). A drawn box always
 *   wins over the clear flag, so "clear then redraw" in one submit stores
 *   the new box.
 * - cleared: box_clear set to a truthy value ("1") and no valid coordinates
 *   submitted — remove the stored box.
 * - not provided: coordinates missing, empty, or all-zero (the editor's
 *   untouched state) — leave any stored box untouched.
 *
 * Explicitly malformed values ("abc", half a box) are always a 400, clear
 * flag or not.
 */
final readonly class CorrectedBoxInput
{
    private function __construct(
        public ?BoundingBox $box,
        public bool $provided,
    ) {
    }

    /**
     * Three-state parse: set (all four coordinates — wins over the clear
     * flag), cleared (box_clear set), or not provided (no box fields at all
     * — leave any stored box untouched).
     */
    public static function optional(InputBag $input): self
    {
        $coordinates = [
            self::optionalFloat($input, 'box_x'),
            self::optionalFloat($input, 'box_y'),
            self::optionalFloat($input, 'box_width'),
            self::optionalFloat($input, 'box_height'),
        ];

        if (in_array(null, $coordinates, true)) {
            if (array_filter($coordinates, static fn (?float $value): bool => $value !== null) !== []) {
                throw new BadRequestHttpException('Incomplete bounding box: x, y, width and height must all be provided.');
            }
        } elseif ($coordinates !== [0.0, 0.0, 0.0, 0.0]) {
            // A drawn box wins over the clear flag: the reviewer may clear the
            // stored box and draw a new one in the same form submit.
            return new self(self::buildBox(...array_map(floatval(...), $coordinates)), true);
        }

        if (self::isFlagSet($input->get('box_clear'))) {
            return new self(null, true); // Cleared: remove any stored box.
        }

        // Missing coordinates or the editor's all-zero untouched state: leave
        // any stored box untouched. (A valid box can never be all-zero, so
        // all-zeros means "no box drawn", not a malformed box.)
        return new self(null, false);
    }

    /**
     * A box the user has just drawn; it must be present and valid.
     */
    public static function required(InputBag $input): BoundingBox
    {
        $parsed = self::optional($input);

        if (!$parsed->provided || $parsed->box === null) {
            throw new BadRequestHttpException('A bounding box is required: draw one on the snapshot.');
        }

        return $parsed->box;
    }

    private static function buildBox(float $x, float $y, float $width, float $height): BoundingBox
    {
        try {
            return new BoundingBox($x, $y, $width, $height);
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private static function optionalFloat(InputBag $input, string $field): ?float
    {
        $value = $input->get($field);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new BadRequestHttpException(sprintf('Bounding box field "%s" must be a number, got "%s".', $field, $value));
        }

        return (float) $value;
    }

    private static function isFlagSet(mixed $value): bool
    {
        return is_string($value) && $value !== '' && $value !== '0';
    }
}
