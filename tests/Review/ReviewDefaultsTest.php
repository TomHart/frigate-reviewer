<?php

declare(strict_types=1);

namespace App\Tests\Review;

use App\Domain\ReviewService;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Spec: "default validity SHALL be unreviewed" — validity and identity are
 * independent fields (design.md D7).
 */
final class ReviewDefaultsTest extends AbstractDatabaseTestCase
{
    public function testNewReviewDefaultsToUnreviewed(): void
    {
        $this->bootDatabase();
        $reviewService = new ReviewService($this->entityManager());
        $detection = $this->createFrigateDetection();

        $review = $reviewService->reviewFor($detection);

        self::assertSame(ReviewValidity::Unreviewed, $review->getValidity());
        self::assertNull($review->getIdentityLabel(), 'Validity and identity are independent; a fresh review carries no identity.');
    }

    public function testReviewRowPersistsWithUnreviewedValidity(): void
    {
        $this->bootDatabase();
        $reviewService = new ReviewService($this->entityManager());
        $detection = $this->createFrigateDetection();

        $reviewService->reviewFor($detection);
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(\App\Entity\Detection::class, $detection->getId());
        \assert($reloaded instanceof \App\Entity\Detection);
        $review = $reloaded->getReview();
        \assert($review instanceof \App\Entity\Review);

        self::assertSame(ReviewValidity::Unreviewed, $review->getValidity());
    }

    public function testReviewForIsIdempotent(): void
    {
        $this->bootDatabase();
        $reviewService = new ReviewService($this->entityManager());
        $detection = $this->createFrigateDetection();

        $first = $reviewService->reviewFor($detection);
        $second = $reviewService->reviewFor($detection);

        self::assertSame($first, $second, 'reviewFor must not create a second review row for one detection.');
    }
}
