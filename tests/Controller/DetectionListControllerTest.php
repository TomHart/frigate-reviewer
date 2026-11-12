<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\BoundingBox;
use App\Domain\ReviewService;
use App\Entity\Detection;
use App\Entity\FrigateEvent;
use App\Entity\Label;
use App\Entity\Review;
use App\Entity\Snapshot;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Functional tests of the detection listing (spec "Detection listing"):
 * fixtures are created as entities, the listing page is requested through the
 * kernel test client with query-string filters, and the rendered subset is
 * asserted.
 */
final class DetectionListControllerTest extends AbstractDatabaseTestCase
{
    public function testReviewStateAllShowsAllDetections(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $this->createDetection(camera: 'back_garden', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/detections?review_state=all');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-detection-id]'));
    }

    public function testListingDefaultsToUnreviewedWhenReviewStateIsAbsent(): void
    {
        $this->bootDatabase();
        $unreviewed = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $this->createDetection(camera: 'front_door', sourceClass: 'person', validity: ReviewValidity::TruePositive);

        $crawler = $this->client()->request('GET', '/detections');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
        self::assertSame((string) $unreviewed->getId(), $crawler->filter('[data-detection-id]')->attr('data-detection-id'));
    }

    public function testCameraFilterShowsOnlyMatchingCamera(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $this->createDetection(camera: 'back_garden', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/detections?camera=back_garden');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
    }

    public function testDateFilterShowsOnlyEventsOnThatDay(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person', startTime: new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->createDetection(camera: 'front_door', sourceClass: 'person', startTime: new \DateTimeImmutable('2026-11-11 08:00:00'));

        $crawler = $this->client()->request('GET', '/detections?date=2026-11-10');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
    }

    public function testValidityFilterShowsOnlyMatchingValidity(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person', validity: ReviewValidity::FalsePositive);
        $this->createDetection(camera: 'front_door', sourceClass: 'person');

        $crawler = $this->client()->request('GET', '/detections?validity=false_positive&review_state=all');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
    }

    public function testSourceClassFilterShowsOnlyMatchingClass(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $this->createDetection(camera: 'front_door', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/detections?class=dog');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
    }

    public function testReviewStateFilterUnreviewedExcludesReviewedObjects(): void
    {
        $this->bootDatabase();
        $this->createDetection(camera: 'front_door', sourceClass: 'person', validity: ReviewValidity::TruePositive);
        $unreviewed = $this->createDetection(camera: 'front_door', sourceClass: 'person');

        $crawler = $this->client()->request('GET', '/detections?review_state=unreviewed');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
        self::assertSame((string) $unreviewed->getId(), $crawler->filter('[data-detection-id]')->attr('data-detection-id'));
    }

    public function testReviewStateFilterReviewedExcludesUnreviewedObjects(): void
    {
        $this->bootDatabase();
        $reviewed = $this->createDetection(camera: 'front_door', sourceClass: 'person', validity: ReviewValidity::TruePositive);
        $this->createDetection(camera: 'front_door', sourceClass: 'person');

        $crawler = $this->client()->request('GET', '/detections?review_state=reviewed');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
        self::assertSame((string) $reviewed->getId(), $crawler->filter('[data-detection-id]')->attr('data-detection-id'));
    }

    public function testInvalidValidityFilterIsRejected(): void
    {
        $this->bootDatabase();

        $this->client()->request('GET', '/detections?validity=banana');

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidReviewStateFilterIsRejected(): void
    {
        $this->bootDatabase();

        $this->client()->request('GET', '/detections?review_state=banana');

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidDateFilterIsRejected(): void
    {
        $this->bootDatabase();

        $this->client()->request('GET', '/detections?date=12/11/2026');

        self::assertResponseStatusCodeSame(400);
    }

    public function testListingIsPaginated(): void
    {
        $this->bootDatabase();
        for ($i = 0; $i < 21; ++$i) {
            $this->createDetection(camera: 'front_door', sourceClass: 'person');
        }

        $crawler = $this->client()->request('GET', '/detections?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-detection-id]'));
    }

    public function testInvalidPageIsRejected(): void
    {
        $this->bootDatabase();

        $this->client()->request('GET', '/detections?page=zero');

        self::assertResponseStatusCodeSame(400);
    }

    public function testEventDetailPresentsAllDetectionsOfOneEventTogether(): void
    {
        $this->bootDatabase();
        $event = $this->createEvent('front_door', new \DateTimeImmutable('2026-11-12 10:00:00'));
        $snapshot = $this->createSnapshot();
        $first = $this->createDetectionOn($event, $snapshot, 'person');
        $second = $this->createDetectionOn($event, $snapshot, 'person');
        $third = $this->createDetectionOn($event, $snapshot, 'dog');

        $crawler = $this->client()->request('GET', '/event/'.$event->getFrigateEventId());

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-detection-id]'));
        self::assertSame(
            [(string) $first->getId(), (string) $second->getId(), (string) $third->getId()],
            $crawler->filter('[data-detection-id]')->each(
                static fn (Crawler $node) => $node->attr('data-detection-id'),
            ),
        );
    }

    public function testUnknownEventIsNotFound(): void
    {
        $this->bootDatabase();

        $this->client()->request('GET', '/event/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    // -- quick-classify dropdown on the cards -----------------------------------

    public function testCardOffersQuickClassifyOptionsOnlyForLabelsMatchingSourceClass(): void
    {
        $this->bootDatabase();
        $person = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $dog = $this->createDetection(camera: 'front_door', sourceClass: 'dog');

        $crawler = $this->client()->request('GET', '/detections');

        self::assertResponseIsSuccessful();

        $optionsFor = static fn (Detection $detection): array => $crawler
            ->filter(sprintf('[data-detection-id="%d"] [data-quick-classify-option]', $detection->getId()))
            ->each(static fn (Crawler $node) => $node->attr('data-quick-classify-option'));

        // Live person-kind labels in seed order — nothing hardcoded in the template.
        self::assertSame(['Finola', 'Tom', 'Person'], $optionsFor($person));
        self::assertSame(['Dougie', 'Dog'], $optionsFor($dog), 'A dog detection must never be offered person labels.');
    }

    public function testManualObjectCardOffersEveryActiveLabel(): void
    {
        $this->bootDatabase();
        $manual = $this->createManualDetection();

        $crawler = $this->client()->request('GET', '/detections');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Finola', 'Tom', 'Person', 'Dougie', 'Dog'],
            $crawler
                ->filter(sprintf('[data-detection-id="%d"] [data-quick-classify-option]', $manual->getId()))
                ->each(static fn (Crawler $node) => $node->attr('data-quick-classify-option')),
            'Manual objects accept any label kind.',
        );
    }

    public function testQuickClassifyDropsObjectFromDefaultUnreviewedQueue(): void
    {
        $this->bootDatabase();
        $detection = $this->createDetection(camera: 'front_door', sourceClass: 'person');
        $tom = $this->labelNamed('Tom');

        $client = $this->client();
        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
            'label' => (string) $tom->getId(),
        ]);

        self::assertResponseRedirects();
        self::assertSame('/detections', $client->getResponse()->headers->get('Location'));

        $crawler = $client->request('GET', '/detections');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-detection-id]'), 'The classified object must drop out of the unreviewed queue so the next one is on top.');
    }

    // -- fixtures -------------------------------------------------------------

    private function createDetection(
        string $camera,
        string $sourceClass,
        ?ReviewValidity $validity = null,
        ?\DateTimeImmutable $startTime = null,
    ): Detection {
        $event = $this->createEvent($camera, $startTime ?? new \DateTimeImmutable('2026-11-12 10:00:00'));
        $detection = $this->createDetectionOn($event, $this->createSnapshot(), $sourceClass, $startTime);

        if ($validity !== null) {
            $this->reviewService()->setValidity($detection, $validity);
        }

        return $detection;
    }

    private function createDetectionOn(
        FrigateEvent $event,
        Snapshot $snapshot,
        string $sourceClass,
        ?\DateTimeImmutable $startTime = null,
    ): Detection {
        $detection = Detection::fromFrigate(
            $event,
            $snapshot,
            $sourceClass,
            0.87,
            $startTime ?? $event->getStartTime(),
            $event->getStartTime()->modify('+5 seconds'),
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

    private function reviewService(): ReviewService
    {
        return new ReviewService($this->entityManager());
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)->findOneBy(['name' => $name]);
        self::assertNotNull($label, sprintf('Seeded label "%s" must exist.', $name));

        return $label;
    }
}
