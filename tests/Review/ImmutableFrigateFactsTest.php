<?php

declare(strict_types=1);

namespace App\Tests\Review;

use App\Domain\BoundingBox;
use App\Domain\ReviewService;
use App\Domain\ReviewSubmission;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Label;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;

/**
 * The data-preservation hard requirement (spec "Annotation audit trail",
 * design.md D3): every review action may change review data, but the Frigate
 * facts — event ID, camera, source class, confidence, timestamps, original
 * bounding box — must remain byte-identical. Immutability is structural too:
 * the entities carrying those facts expose no setters at all.
 */
final class ImmutableFrigateFactsTest extends AbstractDatabaseTestCase
{
    public function testEveryReviewActionLeavesFrigateFactsByteIdentical(): void
    {
        $this->bootDatabase();
        $entityManager = $this->entityManager();
        $detection = $this->createFrigateDetection('person');
        $detectionId = (int) $detection->getId();
        $entityManager->clear();

        $before = $this->frigateFingerprintAfterReload($detectionId);

        $managed = $entityManager->find(Detection::class, $detectionId);
        \assert($managed instanceof Detection);
        $finola = $entityManager->getRepository(Label::class)->findOneBy(['name' => 'Finola']);
        \assert($finola instanceof Label);

        $reviewService = $this->reviewService();
        $reviewService->setValidity($managed, ReviewValidity::TruePositive);
        $reviewService->setIdentity($managed, $finola);
        $reviewService->setCorrectedBox($managed, new BoundingBox(0.15, 0.25, 0.35, 0.45));
        $reviewService->setCorrectedBox($managed, null); // box cleared
        $reviewService->save($managed, new ReviewSubmission(
            validity: ReviewValidity::FalsePositive,
            identityLabel: $finola, // ignored: a false positive never carries an identity
            correctedBox: new BoundingBox(0.2, 0.2, 0.3, 0.3),
            correctedBoxProvided: true,
            falsePositiveReason: 'tree shadow',
            reviewerNotes: 'blown out by headlights',
        ));
        $reviewService->bulkClassify([$managed], ReviewValidity::Unreviewed, null);

        $entityManager->clear();

        self::assertSame(
            $before,
            $this->frigateFingerprintAfterReload($detectionId),
            'No review action may alter event ID, camera, source class, confidence, timestamps or the original bounding box.',
        );
    }

    public function testManualObjectCreationLeavesEventAndExistingDetectionsUntouched(): void
    {
        $this->bootDatabase();
        $entityManager = $this->entityManager();
        $detection = $this->createFrigateDetection('person');
        $detectionId = (int) $detection->getId();
        $entityManager->clear();

        $before = $this->frigateFingerprintAfterReload($detectionId);

        $managed = $entityManager->find(Detection::class, $detectionId);
        \assert($managed instanceof Detection);
        $reviewService = $this->reviewService();
        $manual = $reviewService->createManualObject(
            $managed->getEvent() ?? throw new \LogicException('Fixture detection must belong to an event.'),
            $managed->getSnapshot() ?? throw new \LogicException('Fixture detection must have a snapshot.'),
            new BoundingBox(0.5, 0.5, 0.2, 0.2),
        );

        self::assertSame(\App\Enum\DetectionOrigin::Manual, $manual->getOrigin());

        $entityManager->clear();

        self::assertSame(
            $before,
            $this->frigateFingerprintAfterReload($detectionId),
            'Manual object creation must not alter the Frigate event or existing detections.',
        );
    }

    public function testFrigateEventExposesNoSetters(): void
    {
        self::assertSame(
            [],
            $this->publicSetterNames(FrigateEvent::class),
            'FrigateEvent metadata is immutable: it must expose no public setters.',
        );
    }

    public function testDetectionExposesNoSettersForFrigateFacts(): void
    {
        self::assertSame(
            ['setReview'],
            $this->publicSetterNames(Detection::class),
            'Detection Frigate facts are immutable: the original box is written only by the named constructors. The only permitted setter is setReview, which wires the inverse side of the Review association and can never alter a Frigate fact.',
        );
    }

    /**
     * Frigate facts of one detection, reloaded from the database and flattened
     * to a scalar map. Compared with assertSame before and after review
     * actions, this is a byte-identical check of every stored fact.
     *
     * @return array<string, ?string>
     */
    private function frigateFingerprintAfterReload(int $detectionId): array
    {
        $detection = $this->entityManager()->find(Detection::class, $detectionId);
        \assert($detection instanceof Detection);
        $event = $detection->getEvent();
        $originalBox = $detection->getOriginalBox();

        return [
            'event.frigateEventId' => $event?->getFrigateEventId(),
            'event.camera' => $event?->getCamera(),
            'event.startTime' => $event?->getStartTime()->format(\DateTimeInterface::ATOM),
            'event.endTime' => $event?->getEndTime()?->format(\DateTimeInterface::ATOM),
            'event.clipPath' => $event?->getClipPath(),
            'event.createdAt' => $event?->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'detection.sourceClass' => $detection->getSourceClass(),
            'detection.confidence' => $detection->getConfidence() === null
                ? null
                : sprintf('%.17g', $detection->getConfidence()),
            'detection.startTime' => $detection->getStartTime()?->format(\DateTimeInterface::ATOM),
            'detection.endTime' => $detection->getEndTime()?->format(\DateTimeInterface::ATOM),
            'detection.originalBox' => $originalBox === null
                ? null
                : sprintf('%.17g,%.17g,%.17g,%.17g', $originalBox->x, $originalBox->y, $originalBox->width, $originalBox->height),
        ];
    }

    /**
     * @return list<string>
     */
    private function publicSetterNames(string $class): array
    {
        return array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_values(array_filter(
                (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => str_starts_with($method->getName(), 'set'),
            )),
        );
    }

    private function reviewService(): ReviewService
    {
        return new ReviewService($this->entityManager());
    }
}
