<?php

declare(strict_types=1);

namespace App\Tests\Review;

use App\Domain\ReviewService;
use App\Entity\Label;
use App\Enum\LabelKind;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Spec: "Kind constrains source classes" — a label may only be applied to
 * detections whose Frigate source class matches the label kind; manual
 * objects accept any kind (design.md D5, enforced in the application layer).
 */
final class LabelKindConstraintTest extends AbstractDatabaseTestCase
{
    private ReviewService $reviewService;

    protected function setUp(): void
    {
        $this->bootDatabase();
        $this->reviewService = new ReviewService($this->entityManager());
    }

    public function testPersonLabelIsRejectedOnDogDetection(): void
    {
        $finola = $this->labelNamed('Finola'); // kind person
        $dogDetection = $this->createFrigateDetection(sourceClass: 'dog');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('person');
        $this->reviewService->setIdentity($dogDetection, $finola);
    }

    public function testDogLabelIsRejectedOnPersonDetection(): void
    {
        $dogLabel = $this->labelNamed('Dog'); // kind dog
        $personDetection = $this->createFrigateDetection(sourceClass: 'person');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('dog');
        $this->reviewService->setIdentity($personDetection, $dogLabel);
    }

    public function testMatchingKindIsAcceptedOnFrigateDetection(): void
    {
        $finola = $this->labelNamed('Finola');
        $personDetection = $this->createFrigateDetection(sourceClass: 'person');

        $review = $this->reviewService->setIdentity($personDetection, $finola);

        self::assertSame($finola->getExportedClassId(), $review->getIdentityLabel()?->getExportedClassId());
    }

    public function testManualDetectionAcceptsAnyKind(): void
    {
        $finola = $this->labelNamed('Finola'); // kind person
        $dogLabel = $this->labelNamed('Dog'); // kind dog

        $manualDetection = $this->createManualDetection();

        $personReview = $this->reviewService->setIdentity($manualDetection, $finola);
        self::assertSame($finola->getExportedClassId(), $personReview->getIdentityLabel()?->getExportedClassId());

        $dogReview = $this->reviewService->setIdentity($manualDetection, $dogLabel);
        self::assertSame($dogLabel->getExportedClassId(), $dogReview->getIdentityLabel()?->getExportedClassId());
    }

    public function testUnmatchedFrigateSourceClassAcceptsNoLabel(): void
    {
        $finola = $this->labelNamed('Finola');
        $carDetection = $this->createFrigateDetection(sourceClass: 'car');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('car');
        $this->reviewService->setIdentity($carDetection, $finola);
    }

    public function testClearingIdentityIsAlwaysAllowed(): void
    {
        $finola = $this->labelNamed('Finola');
        $dogDetection = $this->createFrigateDetection(sourceClass: 'dog');

        $this->reviewService->setIdentity($dogDetection, null);

        self::assertNull($this->reviewService->reviewFor($dogDetection)->getIdentityLabel());
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)->findOneBy(['name' => $name]);
        \assert($label instanceof Label);

        return $label;
    }
}
