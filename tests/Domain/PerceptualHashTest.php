<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PerceptualHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the dHash perceptual hash (design.md D8, task 5.1):
 * determinism, identical images hashing identically, visually similar images
 * a small Hamming distance apart, dissimilar images far apart. Images are
 * generated with GD — no fixtures, no external services.
 */
final class PerceptualHashTest extends TestCase
{
    public function testHashIsDeterministicForIdenticalBytes(): void
    {
        $bytes = $this->jpegBytes(self::horizontalStripes());
        $first = PerceptualHash::fromImageBytes($bytes);
        $second = PerceptualHash::fromImageBytes($bytes);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->toHex(), $second->toHex());
    }

    public function testTwoIndependentlyCreatedIdenticalImagesHashIdentically(): void
    {
        $first = PerceptualHash::fromGdImage(self::horizontalStripes());
        $second = PerceptualHash::fromGdImage(self::horizontalStripes());

        self::assertSame($first->toHex(), $second->toHex());
    }

    public function testVisuallySimilarImagesHaveSmallHammingDistance(): void
    {
        $base = PerceptualHash::fromGdImage(self::horizontalStripes());
        $variant = PerceptualHash::fromGdImage(self::horizontalStripesWithBrightSquare());

        $distance = $base->hammingDistanceTo($variant);

        self::assertGreaterThan(0, $distance, 'A visibly changed image must not hash identically.');
        self::assertLessThanOrEqual(8, $distance, 'A visually near-identical image must stay within the grouping threshold.');
    }

    public function testDissimilarImagesHaveLargeHammingDistance(): void
    {
        $horizontal = PerceptualHash::fromGdImage(self::horizontalStripes());
        $vertical = PerceptualHash::fromGdImage(self::verticalStripes());

        $distance = $horizontal->hammingDistanceTo($vertical);

        self::assertGreaterThanOrEqual(24, $distance, 'Structurally different images must hash far apart.');
    }

    public function testSimilarImagesAreCloserThanDissimilarOnes(): void
    {
        $base = PerceptualHash::fromGdImage(self::horizontalStripes());
        $similar = PerceptualHash::fromGdImage(self::horizontalStripesWithBrightSquare());
        $dissimilar = PerceptualHash::fromGdImage(self::verticalStripes());

        self::assertLessThan(
            $base->hammingDistanceTo($dissimilar),
            $base->hammingDistanceTo($similar),
        );
    }

    public function testHammingDistanceToSelfIsZeroAndSymmetric(): void
    {
        $left = PerceptualHash::fromHex('0123456789abcdef');
        $right = PerceptualHash::fromHex('fedcba9876543210');

        self::assertSame(0, $left->hammingDistanceTo($left));
        self::assertSame($left->hammingDistanceTo($right), $right->hammingDistanceTo($left));
    }

    public function testHammingDistanceCountsDifferingBits(): void
    {
        // '0' differs from '3' in exactly the two lowest bits of the last byte.
        self::assertSame(2, PerceptualHash::fromHex('0000000000000000')->hammingDistanceTo(PerceptualHash::fromHex('0000000000000003')));
        // 'ff' differs from '00' in all eight bits of the last byte.
        self::assertSame(8, PerceptualHash::fromHex('00000000000000ff')->hammingDistanceTo(PerceptualHash::fromHex('0000000000000000')));
    }

    public function testFromHexRoundTrips(): void
    {
        self::assertSame('0123456789abcdef', PerceptualHash::fromHex('0123456789abcdef')->toHex());
    }

    #[DataProvider('malformedHexProvider')]
    public function testFromHexRejectsMalformedInput(string $malformed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PerceptualHash::fromHex($malformed);
    }

    /** @return iterable<string, list{string}> */
    public static function malformedHexProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['0123456789abcde'];
        yield 'too long' => ['0123456789abcdef0'];
        yield 'non hex' => ['0123456789abcdeg'];
        yield 'uppercase' => ['0123456789ABCDEF'];
        yield 'whitespace' => [' 123456789abcdef '];
    }

    public function testUndecodableBytesYieldNullInsteadOfFailing(): void
    {
        // Hashing is best-effort at ingest: undecodable snapshot bytes are
        // still stored, they simply never join a similarity group.
        self::assertNull(PerceptualHash::fromImageBytes('definitely not an image'));
    }

    /**
     * 100x100 horizontal stripes (10px, black/white): every row is uniform,
     * so the dHash is all zeros — a clean baseline for similarity tests.
     */
    private static function horizontalStripes(): \GdImage
    {
        return self::stripedImage(vertical: false);
    }

    private static function verticalStripes(): \GdImage
    {
        return self::stripedImage(vertical: true);
    }

    private static function horizontalStripesWithBrightSquare(): \GdImage
    {
        $image = self::horizontalStripes();
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 50, 50, 60, 60, $white);

        return $image;
    }

    private static function stripedImage(bool $vertical): \GdImage
    {
        $image = imagecreatetruecolor(100, 100);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 99, 99, $black);

        for ($stripe = 0; $stripe < 10; ++$stripe) {
            if ($stripe % 2 === 1) {
                if ($vertical) {
                    imagefilledrectangle($image, $stripe * 10, 0, $stripe * 10 + 9, 99, $white);
                } else {
                    imagefilledrectangle($image, 0, $stripe * 10, 99, $stripe * 10 + 9, $white);
                }
            }
        }

        return $image;
    }

    private static function jpegBytes(\GdImage $image): string
    {
        \ob_start();
        imagejpeg($image, null, 90);

        return (string) \ob_get_clean();
    }
}
