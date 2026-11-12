<?php

declare(strict_types=1);

namespace App\Tests\Review;

use App\Domain\ReviewService;
use App\Entity\Label;
use App\Entity\Review;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Spec: "False positive SHALL never carry positive identity classification:
 * setting validity false positive SHALL clear identity" — a domain rule,
 * enforced so every write path honours it (design.md D7).
 */
final class FalsePositiveClearsIdentityTest extends AbstractDatabaseTestCase
{
    private ReviewService $reviewService;

    protected function setUp(): void
    {
        $this->bootDatabase();
        $this->reviewService = new ReviewService($this->entityManager());
    }

    public function testSettingValidityToFalsePositiveClearsIdentity(): void
    {
        $finola = $this->labelNamed('Finola');
        $detection = $this->createFrigateDetection(sourceClass: 'person');

        $this->reviewService->setIdentity($detection, $finola);
        $this->reviewService->setValidity($detection, ReviewValidity::FalsePositive);

        $review = $this->reloadReview($detection);
        self::assertSame(ReviewValidity::FalsePositive, $review->getValidity());
        self::assertNull($review->getIdentityLabel(), 'A false positive must never carry a positive identity classification.');
    }

    public function testIdentityCannotBeAssignedWhileFalsePositive(): void
    {
        $finola = $this->labelNamed('Finola');
        $detection = $this->createFrigateDetection(sourceClass: 'person');

        $this->reviewService->setValidity($detection, ReviewValidity::FalsePositive);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('false positive');
        $this->reviewService->setIdentity($detection, $finola);
    }

    public function testClearingIdentitySurvivesFlushAndReload(): void
    {
        $finola = $this->labelNamed('Finola');
        $detection = $this->createFrigateDetection(sourceClass: 'person');

        $this->reviewService->setIdentity($detection, $finola);
        $this->reviewService->setValidity($detection, ReviewValidity::FalsePositive);
        $this->entityManager()->clear();

        $review = $this->reloadReview($detection);
        self::assertNull($review->getIdentityLabel());
    }

    public function testTruePositiveKeepsIdentity(): void
    {
        $finola = $this->labelNamed('Finola');
        $detection = $this->createFrigateDetection(sourceClass: 'person');

        $this->reviewService->setIdentity($detection, $finola);
        $this->reviewService->setValidity($detection, ReviewValidity::TruePositive);

        self::assertSame($finola->getExportedClassId(), $this->reloadReview($detection)->getIdentityLabel()?->getExportedClassId());
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)->findOneBy(['name' => $name]);
        \assert($label instanceof Label);

        return $label;
    }

    private function reloadReview(\App\Entity\Detection $detection): Review
    {
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(\App\Entity\Detection::class, $detection->getId());
        \assert($reloaded instanceof \App\Entity\Detection);
        $review = $reloaded->getReview();
        \assert($review instanceof Review);

        return $review;
    }
}
