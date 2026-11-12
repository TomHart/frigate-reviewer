<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Detection;

/**
 * Groups detections whose snapshots look alike, by Hamming distance between
 * their perceptual hashes (design.md D8, spec "Grouping and bulk
 * classification"). Grouping is presentation and bulk-selection only: the
 * grouper returns a different arrangement of the detections it was given and
 * never touches the database — no record is merged, discarded or deleted.
 *
 * Comparison window: the caller decides which detections are candidates
 * (typically one page of the filtered listing). That bounds the work to
 * O(k²) Hamming-distance computations over the page in PHP, which is more
 * than fast enough for SQLite-backed single-user listings; visually similar
 * snapshots are temporally adjacent, so they usually land on the same page.
 */
final class SimilarSnapshotGrouper
{
    public const DEFAULT_MAX_HAMMING_DISTANCE = 8;

    public function __construct(
        private readonly int $maxHammingDistance = self::DEFAULT_MAX_HAMMING_DISTANCE,
    ) {
    }

    /**
     * Every input detection appears in exactly one output group, in
     * first-appearance order. Detections without a snapshot or without a
     * perceptual hash always form their own group.
     *
     * @param list<Detection> $detections
     *
     * @return list<list<Detection>>
     */
    public function group(array $detections): array
    {
        if ($detections === []) {
            return [];
        }

        $hashes = array_map(
            fn (Detection $detection): ?PerceptualHash => self::hashOf($detection),
            $detections,
        );

        $parent = range(0, \count($detections) - 1); // Union-find over page indexes.

        foreach ($detections as $leftIndex => $leftDetection) {
            foreach ($detections as $rightIndex => $rightDetection) {
                if ($rightIndex <= $leftIndex) {
                    continue;
                }

                if ($this->withinThreshold($hashes[$leftIndex], $hashes[$rightIndex])) {
                    $parent[$this->rootOf($parent, $rightIndex)] = $this->rootOf($parent, $leftIndex);
                }
            }
        }

        $groupsInFirstAppearanceOrder = [];

        foreach ($detections as $index => $detection) {
            $groupsInFirstAppearanceOrder[$this->rootOf($parent, $index)][] = $detection;
        }

        return array_values($groupsInFirstAppearanceOrder);
    }

    private function withinThreshold(?PerceptualHash $left, ?PerceptualHash $right): bool
    {
        if ($left === null || $right === null) {
            return false; // Unhashable snapshots never join a group.
        }

        return $left->hammingDistanceTo($right) <= $this->maxHammingDistance;
    }

    private static function hashOf(Detection $detection): ?PerceptualHash
    {
        $hex = $detection->getSnapshot()?->getPerceptualHash();

        return $hex === null ? null : PerceptualHash::fromHex($hex);
    }

    /**
     * @param list<int> $parent
     */
    private static function rootOf(array &$parent, int $index): int
    {
        while ($parent[$index] !== $index) {
            $parent[$index] = $parent[$parent[$index]]; // Path halving.
            $index = $parent[$index];
        }

        return $index;
    }
}
