<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\BoundingBox;
use App\Domain\ReviewService;
use App\Domain\ReviewSubmission;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Label;
use App\Entity\Snapshot;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Functional tests for the dataset-quality dashboard (tasks 7.1–7.4, spec
 * "Dataset quality dashboard"): known data is seeded, the page is rendered
 * through the kernel test client, and every metric is asserted from its data
 * attribute on the rendered page.
 *
 * Metric definitions under test:
 * - reviewed = validity anything other than unreviewed (App\Enum\ReviewState);
 * - false-positive rate = false positives ÷ reviewed objects;
 * - identity counts cover true-positive and unsure objects, with an
 *   "Unidentified" bucket for the same population without an identity;
 * - confidence buckets are 0.1 wide, the last closed at 1.0;
 * - days are the Frigate event's start time;
 * - distinct events = Frigate events with at least one detection.
 */
final class DashboardControllerTest extends AbstractDatabaseTestCase
{
    public function testReviewCoveragePerCameraCountsReviewedAndUnreviewed(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        $frontDoorReviewed = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $frontDoorAlsoReviewed = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $reviewService->setValidity($frontDoorReviewed, ReviewValidity::TruePositive);
        $reviewService->setValidity($frontDoorAlsoReviewed, ReviewValidity::FalsePositive);

        // A review row that exists but was never classified still counts as
        // unreviewed.
        $frontDoorTouchedOnly = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $reviewService->reviewFor($frontDoorTouchedOnly);

        $backGardenUnreviewed = $this->createDetection(camera: 'back_garden', sourceClass: 'dog');
        $backGardenAlsoUnreviewed = $this->createDetection(camera: 'back_garden', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $frontDoor = $crawler->filter('[data-camera-coverage="front_door"]');
        self::assertSame('2', $frontDoor->attr('data-reviewed'));
        self::assertSame('1', $frontDoor->attr('data-unreviewed'));
        self::assertSame('1', $frontDoor->attr('data-false-positives'));

        $backGarden = $crawler->filter('[data-camera-coverage="back_garden"]');
        self::assertSame('0', $backGarden->attr('data-reviewed'));
        self::assertSame('2', $backGarden->attr('data-unreviewed'));
        self::assertSame('0', $backGarden->attr('data-false-positives'));
    }

    public function testFalsePositiveRateUsesReviewedObjectsAsDenominator(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        $falsePositives = [
            $this->createDetection(camera: 'front_door', sourceClass: 'person'),
            $this->createDetection(camera: 'front_door', sourceClass: 'person'),
        ];
        $truePositive = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        foreach ($falsePositives as $detection) {
            $reviewService->setValidity($detection, ReviewValidity::FalsePositive);
        }
        $reviewService->setValidity($truePositive, ReviewValidity::TruePositive);
        $this->createDetection(camera: 'front_door', sourceClass: 'person'); // unreviewed, not in denominator

        // Nothing reviewed on this camera yet: no rate can be computed.
        $this->createDetection(camera: 'back_garden', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $frontDoor = $crawler->filter('[data-camera-coverage="front_door"]');
        self::assertSame('3', $frontDoor->attr('data-reviewed'));
        self::assertSame('66.7', $frontDoor->attr('data-false-positive-rate'));

        $backGarden = $crawler->filter('[data-camera-coverage="back_garden"]');
        self::assertSame('n/a', $backGarden->attr('data-false-positive-rate'));
    }

    public function testFalsePositiveReasonBreakdownIsOrderedByFrequency(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        for ($i = 0; $i < 3; ++$i) {
            $this->markFalsePositive($reviewService, 'tree shadow');
        }
        $this->markFalsePositive($reviewService, 'car headlights');
        // A false positive without a reason contributes no reason row.
        $this->markFalsePositive($reviewService, null);

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $reasons = $crawler->filter('[data-reason-count]');
        self::assertCount(2, $reasons);
        self::assertSame('tree shadow', $reasons->eq(0)->attr('data-reason'));
        self::assertSame('3', $reasons->eq(0)->attr('data-count'));
        self::assertSame('car headlights', $reasons->eq(1)->attr('data-reason'));
        self::assertSame('1', $reasons->eq(1)->attr('data-count'));
    }

    public function testFalsePositiveReasonSavedThroughReviewServiceAppearsOnDashboard(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        // Saved exactly the way the review UI saves it: one ReviewService::save
        // call carrying a false-positive validity and a reason together.
        $reviewService->save(
            $this->createDetection(camera: 'kitchen', sourceClass: 'person'),
            new ReviewSubmission(
                validity: ReviewValidity::FalsePositive,
                identityLabel: null,
                correctedBox: null,
                correctedBoxProvided: false,
                falsePositiveReason: 'condiment-bottle',
                reviewerNotes: null,
            ),
        );

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $reasons = $crawler->filter('[data-reason-count]');
        self::assertCount(1, $reasons);
        self::assertSame('condiment-bottle', $reasons->eq(0)->attr('data-reason'));
        self::assertSame('1', $reasons->eq(0)->attr('data-count'));
    }

    public function testReasonStoredOnNonFalsePositiveReviewDoesNotAppearInBreakdown(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        // The data shape found behind the "No false-positive reasons recorded"
        // report: a bulk classification saved validity true_positive while the
        // always-visible reason field was also submitted. The breakdown must
        // not present such a reason as a false-positive reason, while genuine
        // false positives keep appearing.
        $reviewService->save(
            $this->createDetection(camera: 'kitchen', sourceClass: 'person'),
            new ReviewSubmission(
                validity: ReviewValidity::TruePositive,
                identityLabel: null,
                correctedBox: null,
                correctedBoxProvided: false,
                falsePositiveReason: 'condiment-bottle',
                reviewerNotes: null,
            ),
        );
        $this->markFalsePositive($reviewService, 'tree shadow');

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $reasons = $crawler->filter('[data-reason-count]');
        self::assertCount(1, $reasons);
        self::assertSame('tree shadow', $reasons->eq(0)->attr('data-reason'));
    }

    public function testIdentityClassCountsIncludeUnidentifiedBucket(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        $finola = $this->labelNamed('Finola');
        $tom = $this->labelNamed('Tom');
        $person = $this->labelNamed('Person');

        foreach ([$finola, $finola, $tom, $person] as $label) {
            $reviewService->save(
                $this->createDetection(camera: 'front_door', sourceClass: 'person'),
                $this->submission(ReviewValidity::TruePositive, $label),
            );
        }

        // Two positive objects the reviewer could not identify.
        $reviewService->save(
            $this->createDetection(camera: 'front_door', sourceClass: 'person'),
            $this->submission(ReviewValidity::TruePositive, null),
        );
        $reviewService->save(
            $this->createDetection(camera: 'front_door', sourceClass: 'dog'),
            $this->submission(ReviewValidity::Unsure, null),
        );

        // A false positive never carries an identity, so it never appears
        // in the identity breakdown.
        $reviewService->save(
            $this->createDetection(camera: 'front_door', sourceClass: 'person'),
            $this->submission(ReviewValidity::FalsePositive, $tom),
        );

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('2', $crawler->filter('[data-identity="Finola"]')->attr('data-count'));
        self::assertSame('1', $crawler->filter('[data-identity="Tom"]')->attr('data-count'));
        self::assertSame('1', $crawler->filter('[data-identity="Person"]')->attr('data-count'));
        self::assertSame('2', $crawler->filter('[data-identity="Unidentified"]')->attr('data-count'));
    }

    public function testConfidenceDistributionBucketsFrigateConfidences(): void
    {
        $this->bootDatabase();

        $this->createDetection(camera: 'front_door', sourceClass: 'person', confidence: 0.05);
        $this->createDetection(camera: 'front_door', sourceClass: 'person', confidence: 0.15);
        $this->createDetection(camera: 'front_door', sourceClass: 'person', confidence: 0.87);
        $this->createDetection(camera: 'front_door', sourceClass: 'person', confidence: 0.95);
        $this->createDetection(camera: 'front_door', sourceClass: 'person', confidence: 1.0);

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('[data-confidence-bucket="0.0–0.1"]')->attr('data-count'));
        self::assertSame('1', $crawler->filter('[data-confidence-bucket="0.1–0.2"]')->attr('data-count'));
        self::assertSame('0', $crawler->filter('[data-confidence-bucket="0.5–0.6"]')->attr('data-count'));
        self::assertSame('1', $crawler->filter('[data-confidence-bucket="0.8–0.9"]')->attr('data-count'));
        self::assertSame('2', $crawler->filter('[data-confidence-bucket="0.9–1.0"]')->attr('data-count'));
    }

    public function testDetectionCountsOverTimeGroupByEventStartDay(): void
    {
        $this->bootDatabase();

        $first = $this->createEvent('front_door', new \DateTimeImmutable('2026-11-01 08:00:00'));
        $second = $this->createEvent('front_door', new \DateTimeImmutable('2026-11-10 09:00:00'));
        $third = $this->createEvent('back_garden', new \DateTimeImmutable('2026-11-12 10:00:00'));

        $this->createDetectionOn($first, 'person', 0.9, new \DateTimeImmutable('2026-11-01 08:00:00'));
        // Two detections on one event both count on that event's day.
        $this->createDetectionOn($second, 'person', 0.9, new \DateTimeImmutable('2026-11-10 09:00:00'));
        $this->createDetectionOn($second, 'dog', 0.8, new \DateTimeImmutable('2026-11-10 09:01:00'));
        $this->createDetectionOn($third, 'person', 0.9, new \DateTimeImmutable('2026-11-12 10:00:00'));

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $days = $crawler->filter('[data-day-count]');
        self::assertCount(3, $days);
        self::assertSame(['2026-11-01', '2026-11-10', '2026-11-12'], $days->each(
            static fn (Crawler $node) => $node->attr('data-day'),
        ));
        self::assertSame('1', $crawler->filter('[data-day="2026-11-01"]')->attr('data-count'));
        self::assertSame('2', $crawler->filter('[data-day="2026-11-10"]')->attr('data-count'));
        self::assertSame('1', $crawler->filter('[data-day="2026-11-12"]')->attr('data-count'));
    }

    public function testCorrectedBoxAndDistinctEventCounts(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();

        $sharedSnapshotEvent = $this->createEvent('front_door', new \DateTimeImmutable('2026-11-12 10:00:00'));
        $correctedOnSharedEvent = $this->createDetectionOn($sharedSnapshotEvent, 'person', 0.9, null);
        $this->createDetectionOn($sharedSnapshotEvent, 'dog', 0.8, null);
        $correctedOnOwnEvent = $this->createDetection(camera: 'back_garden', sourceClass: 'person');
        $this->createDetection(camera: 'side_gate', sourceClass: 'person');
        $this->createDetection(camera: 'side_gate', sourceClass: 'person');

        foreach ([$correctedOnSharedEvent, $correctedOnOwnEvent] as $detection) {
            $reviewService->save($detection, $this->submissionWithCorrectedBox());
        }

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('5', $crawler->filter('[data-total-detections]')->attr('data-total-detections'));
        self::assertSame('4', $crawler->filter('[data-distinct-events]')->attr('data-distinct-events'));
        self::assertSame('2', $crawler->filter('[data-corrected-boxes]')->attr('data-corrected-boxes'));
    }

    public function testEmptyDashboardRendersZeroesAndLinksFromBaseNav(): void
    {
        $this->bootDatabase();

        $crawler = $this->client()->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('[data-total-detections]')->attr('data-total-detections'));
        self::assertSame('0', $crawler->filter('[data-distinct-events]')->attr('data-distinct-events'));
        self::assertSame('0', $crawler->filter('[data-corrected-boxes]')->attr('data-corrected-boxes'));
        self::assertCount(0, $crawler->filter('[data-camera-coverage]'));

        // The dashboard is reachable from the base layout navigation.
        $navLink = $crawler->filter('nav a[href="/dashboard"]');
        self::assertCount(1, $navLink);
        self::assertSame('Dashboard', trim($navLink->text()));
    }

    // -- fixtures -------------------------------------------------------------

    private function markFalsePositive(ReviewService $reviewService, ?string $reason): void
    {
        $reviewService->save(
            $this->createDetection(camera: 'front_door', sourceClass: 'person'),
            new ReviewSubmission(
                validity: ReviewValidity::FalsePositive,
                identityLabel: null,
                correctedBox: null,
                correctedBoxProvided: false,
                falsePositiveReason: $reason,
                reviewerNotes: null,
            ),
        );
    }

    private function submission(ReviewValidity $validity, ?Label $identityLabel): ReviewSubmission
    {
        return new ReviewSubmission(
            validity: $validity,
            identityLabel: $identityLabel,
            correctedBox: null,
            correctedBoxProvided: false,
            falsePositiveReason: null,
            reviewerNotes: null,
        );
    }

    private function submissionWithCorrectedBox(): ReviewSubmission
    {
        return new ReviewSubmission(
            validity: ReviewValidity::TruePositive,
            identityLabel: null,
            correctedBox: new BoundingBox(0.2, 0.3, 0.4, 0.5),
            correctedBoxProvided: true,
            falsePositiveReason: null,
            reviewerNotes: null,
        );
    }

    private function createDetection(
        string $camera,
        string $sourceClass,
        float $confidence = 0.87,
    ): Detection {
        $event = $this->createEvent($camera, new \DateTimeImmutable('2026-11-12 10:00:00'));

        return $this->createDetectionOn($event, $sourceClass, $confidence, $event->getStartTime());
    }

    private function createDetectionOn(
        FrigateEvent $event,
        string $sourceClass,
        float $confidence,
        ?\DateTimeImmutable $startTime,
    ): Detection {
        $detection = Detection::fromFrigate(
            $event,
            $this->createSnapshot(),
            $sourceClass,
            $confidence,
            $startTime ?? $event->getStartTime(),
            ($startTime ?? $event->getStartTime())->modify('+5 seconds'),
            new BoundingBox(0.1, 0.2, 0.4, 0.5),
        );
        $this->persist($detection);

        return $detection;
    }

    private function createEvent(string $camera, \DateTimeImmutable $startTime): FrigateEvent
    {
        $event = new FrigateEvent(
            'ev-'.bin2hex(random_bytes(8)),
            $camera,
            $startTime,
        );
        $this->persist($event);

        return $event;
    }

    private function createSnapshot(): Snapshot
    {
        $snapshot = new Snapshot(
            hash('sha256', bin2hex(random_bytes(16))),
            '/snapshots/'.bin2hex(random_bytes(8)).'.jpg',
        );
        $this->persist($snapshot);

        return $snapshot;
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)->findOneBy(['name' => $name]);
        self::assertNotNull($label, sprintf('Seeded label "%s" must exist.', $name));

        return $label;
    }

    private function reviewService(): ReviewService
    {
        return new ReviewService($this->entityManager());
    }
}
