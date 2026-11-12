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
use App\Enum\DetectionOrigin;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Functional tests of the per-object review flow (spec "Per-object review",
 * "Manual objects added by reviewer", "False-positive reasons autocomplete",
 * "Original corrected bounding boxes distinguished"): requests go through the
 * kernel test client so the full controller -> domain -> persistence path is
 * exercised.
 */
final class ReviewControllerTest extends AbstractDatabaseTestCase
{
    public function testReviewPageRendersOriginalAndCorrectedBoxesDistinctly(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $crawler = $this->client()->request('GET', '/review/'.$detection->getId());

        self::assertResponseIsSuccessful();
        // Original Frigate box and corrected box are both visible, as distinct
        // values ("0.1,0.2,0.4,0.5" vs "0.2,0.3,0.3,0.4").
        self::assertSame('0.1,0.2,0.4,0.5', $crawler->filter('[data-bbox-editor-original-box-value]')->attr('data-bbox-editor-original-box-value'));
        self::assertSame('0.2,0.3,0.3,0.4', $crawler->filter('[data-bbox-editor-corrected-box-value]')->attr('data-bbox-editor-corrected-box-value'));
    }

    public function testSaveStoresValidityIdentityReasonAndNotes(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $finola = $this->labelNamed('Finola');

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => (string) $finola->getId(),
            'false_positive_reason' => '',
            'reviewer_notes' => 'Clearly Finola at the door.',
        ]);

        self::assertResponseRedirects();

        $review = $this->reloadReview($detection);
        self::assertSame(ReviewValidity::TruePositive, $review->getValidity());
        self::assertSame('Finola', $review->getIdentityLabel()?->getName());
        self::assertNull($review->getFalsePositiveReason());
        self::assertSame('Clearly Finola at the door.', $review->getReviewerNotes());
    }

    public function testIdentityCanBeClearedAgain(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $finola = $this->labelNamed('Finola');
        $this->reviewService()->setIdentity($detection, $finola);

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->reloadReview($detection)->getIdentityLabel());
    }

    public function testFalsePositiveClearsIdentityEndToEnd(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $finola = $this->labelNamed('Finola');

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => (string) $finola->getId(),
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'false_positive',
            'identity_label_id' => (string) $finola->getId(), // UI would not send this; server clears it regardless.
            'false_positive_reason' => 'tree shadow',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();
        $review = $this->reloadReview($detection);
        self::assertSame(ReviewValidity::FalsePositive, $review->getValidity());
        self::assertNull($review->getIdentityLabel(), 'A false positive must never carry an identity.');
        self::assertSame('tree shadow', $review->getFalsePositiveReason());
    }

    public function testCorrectedBoxSavedSeparatelyOriginalUnchanged(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'box_x' => '0.25',
            'box_y' => '0.35',
            'box_width' => '0.2',
            'box_height' => '0.3',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $detection = $entityManager->find(Detection::class, $detection->getId());
        \assert($detection instanceof Detection);

        $original = $detection->getOriginalBox();
        self::assertNotNull($original);
        self::assertSame(0.1, $original->x, 'The original Frigate box must never be overwritten.');
        self::assertSame(0.2, $original->y);
        self::assertSame(0.4, $original->width);
        self::assertSame(0.5, $original->height);

        $corrected = $detection->getReview()?->getCorrectedBox();
        self::assertNotNull($corrected, 'The corrected box must be stored on the review row.');
        self::assertSame(0.25, $corrected->x);
        self::assertSame(0.35, $corrected->y);
        self::assertSame(0.2, $corrected->width);
        self::assertSame(0.3, $corrected->height);
    }

    public function testClearingCorrectedBoxLeavesOriginalIntact(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'unreviewed',
            'identity_label_id' => '',
            'box_clear' => '1',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $detection = $entityManager->find(Detection::class, $detection->getId());
        \assert($detection instanceof Detection);

        self::assertNull($detection->getReview()?->getCorrectedBox());
        self::assertNotNull($detection->getOriginalBox(), 'Clearing the corrected box must not touch the original.');
    }

    public function testClearFlagWithValidCoordinatesStoresNewBox(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'unreviewed',
            'identity_label_id' => '',
            // Clear then redraw in one submit: the drawn box must win.
            'box_clear' => '1',
            'box_x' => '0.5',
            'box_y' => '0.1',
            'box_width' => '0.3',
            'box_height' => '0.2',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();

        $corrected = $this->reloadReview($detection)->getCorrectedBox();
        self::assertNotNull($corrected, 'A drawn box must win over the clear flag.');
        self::assertSame(0.5, $corrected->x);
        self::assertSame(0.1, $corrected->y);
        self::assertSame(0.3, $corrected->width);
        self::assertSame(0.2, $corrected->height);
    }

    public function testReviewWithoutBoxFieldsLeavesStoredCorrectedBoxUntouched(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'false_positive',
            'identity_label_id' => '',
            'false_positive_reason' => 'e2e repro',
            'reviewer_notes' => 'no box drawn, none needed',
        ]);

        self::assertResponseRedirects();

        $review = $this->reloadReview($detection);
        self::assertSame(ReviewValidity::FalsePositive, $review->getValidity());
        self::assertSame('e2e repro', $review->getFalsePositiveReason());
        self::assertSame('no box drawn, none needed', $review->getReviewerNotes());

        $corrected = $review->getCorrectedBox();
        self::assertNotNull($corrected, 'A review submitted without box fields must leave the stored corrected box untouched.');
        self::assertSame(0.2, $corrected->x);
        self::assertSame(0.3, $corrected->y);
        self::assertSame(0.3, $corrected->width);
        self::assertSame(0.4, $corrected->height);
    }

    public function testReviewWithEmptyBoxFieldsLeavesStoredCorrectedBoxUntouched(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            // The box editor mirrors "no box drawn" as empty hidden inputs.
            'box_x' => '',
            'box_y' => '',
            'box_width' => '',
            'box_height' => '',
            'box_clear' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();

        $corrected = $this->reloadReview($detection)->getCorrectedBox();
        self::assertNotNull($corrected, 'Empty box fields mean "not provided", never "cleared".');
        self::assertSame(0.2, $corrected->x);
        self::assertSame(0.3, $corrected->y);
        self::assertSame(0.3, $corrected->width);
        self::assertSame(0.4, $corrected->height);
    }

    public function testReviewWithAllZeroBoxFieldsLeavesStoredCorrectedBoxUntouched(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'false_positive',
            'identity_label_id' => '',
            // The editor's untouched state used to leak all-zero coordinates;
            // a valid box can never be all-zero, so this is "not provided".
            'box_x' => '0',
            'box_y' => '0',
            'box_width' => '0.0000',
            'box_height' => '0.0000',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();

        $corrected = $this->reloadReview($detection)->getCorrectedBox();
        self::assertNotNull($corrected, 'All-zero box fields mean "no box drawn", not a malformed box.');
        self::assertSame(0.2, $corrected->x);
        self::assertSame(0.3, $corrected->y);
        self::assertSame(0.3, $corrected->width);
        self::assertSame(0.4, $corrected->height);
    }

    public function testExplicitBoxWithZeroWidthIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $this->reviewService()->setCorrectedBox($detection, new BoundingBox(0.2, 0.3, 0.3, 0.4));

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'box_x' => '0.1',
            'box_y' => '0.1',
            'box_width' => '0',
            'box_height' => '0.3',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseStatusCodeSame(400);

        $corrected = $this->reloadReview($detection)->getCorrectedBox();
        self::assertNotNull($corrected, 'A rejected submission must not clear the stored corrected box.');
        self::assertSame(0.3, $corrected->width);
    }

    public function testMalformedBoxValueIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'box_x' => 'abc',
            'box_y' => '0.1',
            'box_width' => '0.2',
            'box_height' => '0.3',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidValidityIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'banana',
            'identity_label_id' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownIdentityLabelIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '999999',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testReviewPageFiltersIdentityLabelsBySourceClassKind(): void
    {
        $this->bootDatabase();
        $personDetection = $this->createFrigateDetection(sourceClass: 'person');

        $crawler = $this->client()->request('GET', '/review/'.$personDetection->getId());

        self::assertResponseIsSuccessful();
        $optionText = $crawler->filter('select[name="identity_label_id"]')->text();
        self::assertStringContainsString('Finola', $optionText);
        self::assertStringContainsString('Tom', $optionText);
        self::assertStringNotContainsString('Dougie', $optionText, 'A person detection must only offer person-kind labels.');
    }

    public function testReviewPageOffersAllKindsForManualObjects(): void
    {
        $this->bootDatabase();
        $manualDetection = $this->createManualDetection();

        $crawler = $this->client()->request('GET', '/review/'.$manualDetection->getId());

        self::assertResponseIsSuccessful();
        $optionText = $crawler->filter('select[name="identity_label_id"]')->text();
        self::assertStringContainsString('Finola', $optionText);
        self::assertStringContainsString('Dougie', $optionText, 'Manual objects accept any label kind.');
    }

    public function testManualObjectCreationStoresOriginManual(): void
    {
        $this->bootDatabase();
        $event = new FrigateEvent('ev-'.bin2hex(random_bytes(8)), 'front_door', new \DateTimeImmutable('2026-11-12 10:00:00'));
        $snapshot = new Snapshot(hash('sha256', bin2hex(random_bytes(16))), '/snapshots/'.bin2hex(random_bytes(8)).'.jpg');
        $frigateDetection = Detection::fromFrigate($event, $snapshot, 'person', 0.87, $event->getStartTime(), null, new BoundingBox(0.1, 0.2, 0.4, 0.5));
        $this->persist($event, $snapshot, $frigateDetection);

        $client = $this->client();
        $client->request('POST', '/event/'.$event->getFrigateEventId().'/manual-object', [
            'box_x' => '0.6',
            'box_y' => '0.5',
            'box_width' => '0.2',
            'box_height' => '0.25',
        ]);

        self::assertResponseRedirects();
        $redirectTarget = $client->getResponse()->headers->get('Location') ?? '';
        self::assertMatchesRegularExpression('#/review/(\d+)$#', $redirectTarget);
        $manualId = (int) (preg_match('#/review/(\d+)$#', $redirectTarget, $matches) ? $matches[1] : 0);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $manual = $entityManager->find(Detection::class, $manualId);
        \assert($manual instanceof Detection);

        self::assertSame(DetectionOrigin::Manual, $manual->getOrigin());
        self::assertNull($manual->getSourceClass(), 'Manual objects have null Frigate facts.');
        self::assertNull($manual->getConfidence());
        self::assertSame($event->getId(), $manual->getEvent()?->getId(), 'Manual objects link back to their event.');
        self::assertSame($snapshot->getId(), $manual->getSnapshot()?->getId());

        $review = (new ReviewService($entityManager))->reviewFor($manual);
        self::assertSame(ReviewValidity::Unreviewed, $review->getValidity(), 'New manual objects default to unreviewed.');
    }

    public function testManualObjectWithoutBoxIsRejected(): void
    {
        $this->bootDatabase();
        $event = new FrigateEvent('ev-'.bin2hex(random_bytes(8)), 'front_door', new \DateTimeImmutable('2026-11-12 10:00:00'));
        $snapshot = new Snapshot(hash('sha256', bin2hex(random_bytes(16))), '/snapshots/'.bin2hex(random_bytes(8)).'.jpg');
        $this->persist($event, $snapshot);

        $client = $this->client();
        $client->request('POST', '/event/'.$event->getFrigateEventId().'/manual-object', []);

        self::assertResponseStatusCodeSame(400);
    }

    public function testFalsePositiveReasonSuggestionsOrderedByUsage(): void
    {
        $this->bootDatabase();
        $first = $this->createFrigateDetection();
        $second = $this->createFrigateDetection();
        $third = $this->createFrigateDetection();

        $reviewService = $this->reviewService();
        $reviewService->save($first, $this->submission('tree shadow'));
        $reviewService->save($second, $this->submission('tree shadow'));
        $reviewService->save($third, $this->submission('car headlights'));

        $client = $this->client();
        $client->request('GET', '/review/false-positive-reasons');
        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertJson($response->getContent());
        $payload = json_decode($response->getContent() ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                ['reason' => 'tree shadow', 'usage' => 2],
                ['reason' => 'car headlights', 'usage' => 1],
            ],
            $payload['suggestions'],
            'Suggestions are distinct reasons ordered by usage.',
        );
    }

    public function testSuggestionsAcceptPreviouslyUnusedValues(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'false_positive',
            'identity_label_id' => '',
            'false_positive_reason' => 'totally new reason',
            'reviewer_notes' => '',
        ]);

        $client->request('GET', '/review/false-positive-reasons?q=totally');
        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        $payload = json_decode($response->getContent() ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [['reason' => 'totally new reason', 'usage' => 1]],
            $payload['suggestions'],
            'A newly typed reason must be accepted, stored and offered as a suggestion.',
        );
    }

    public function testSuggestionsFilterByQuery(): void
    {
        $this->bootDatabase();
        $reviewService = $this->reviewService();
        $reviewService->save($this->createFrigateDetection(), $this->submission('tree shadow'));
        $reviewService->save($this->createFrigateDetection(), $this->submission('car headlights'));

        $client = $this->client();
        $client->request('GET', '/review/false-positive-reasons?q=tree');
        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        $payload = json_decode($response->getContent() ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['reason' => 'tree shadow', 'usage' => 1]], $payload['suggestions']);
    }

    public function testSaveWritesAuditEntriesForChangedFields(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $finola = $this->labelNamed('Finola');

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => (string) $finola->getId(),
            'box_x' => '0.3',
            'box_y' => '0.3',
            'box_width' => '0.2',
            'box_height' => '0.2',
            'false_positive_reason' => '',
            'reviewer_notes' => 'noted',
        ]);

        self::assertResponseRedirects();

        $entityManager = $this->entityManager();
        $entries = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(\App\Entity\AuditEntry::class, 'a')
            ->where('a.detection = :detection')
            ->setParameter('detection', $detection)
            ->orderBy('a.field', 'ASC')
            ->getQuery()
            ->getResult();

        $fields = array_map(static fn ($entry) => $entry->getField(), $entries);
        self::assertContains('validity', $fields);
        self::assertContains('identity', $fields);
        self::assertContains('corrected_box', $fields);
        self::assertContains('reviewer_notes', $fields);

        $validityEntry = $entries[array_search('validity', $fields, true)];
        self::assertSame('unreviewed', $validityEntry->getPreviousValue());
        self::assertSame('true_positive', $validityEntry->getNewValue());
    }

    public function testSaveRedirectsToDetectionsListPreservingFilters(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
            'return_to' => '/detections?camera=front_door&review_state=unreviewed&page=2',
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            '/detections?camera=front_door&review_state=unreviewed&page=2',
            $client->getResponse()->headers->get('Location'),
            'A save must return the reviewer to the filtered list they came from.',
        );
    }

    public function testSaveWithoutReturnToRedirectsToPlainDetectionsList(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => '',
        ]);

        self::assertResponseRedirects();
        self::assertSame('/detections', $client->getResponse()->headers->get('Location'));
    }

    public function testSaveWithTamperedReturnToFallsBackToPlainDetectionsList(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();

        foreach (['https://evil.example/detections', '//evil.example/detections', '/events'] as $tampered) {
            $client->request('POST', '/review/'.$detection->getId(), [
                'validity' => 'true_positive',
                'identity_label_id' => '',
                'false_positive_reason' => '',
                'reviewer_notes' => '',
                'return_to' => $tampered,
            ]);

            self::assertResponseRedirects();
            self::assertSame(
                '/detections',
                $client->getResponse()->headers->get('Location'),
                sprintf('A tampered return_to "%s" must never be followed (open-redirect guard).', $tampered),
            );
        }
    }

    public function testReviewPageEchoesValidatedReturnToIntoForm(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $crawler = $this->client()->request(
            'GET',
            '/review/'.$detection->getId().'?return_to='.rawurlencode('/detections?camera=front_door'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/detections?camera=front_door',
            $crawler->filter('input[name="return_to"]')->attr('value'),
        );
    }

    public function testReviewPageDropsTamperedReturnToInsteadOfEchoingIt(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $crawler = $this->client()->request(
            'GET',
            '/review/'.$detection->getId().'?return_to='.rawurlencode('https://evil.example/detections'),
        );

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('input[name="return_to"]'),
            'A tampered return_to must not be echoed into the form.',
        );
    }

    // -- quick-classify (detection-list dropdown speed flow) --------------------

    public function testQuickClassifySetsTruePositiveWithIdentityAndAuditEntries(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $tom = $this->labelNamed('Tom');

        $client = $this->client();
        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
            'label' => (string) $tom->getId(),
        ]);

        self::assertResponseRedirects();

        $review = $this->reloadReview($detection);
        self::assertSame(ReviewValidity::TruePositive, $review->getValidity());
        self::assertNotNull($review->getIdentityLabel());
        self::assertSame('Tom', $review->getIdentityLabel()->getName());

        $entityManager = $this->entityManager();
        $entries = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(\App\Entity\AuditEntry::class, 'a')
            ->where('a.detection = :detection')
            ->setParameter('detection', $detection)
            ->orderBy('a.field', 'ASC')
            ->getQuery()
            ->getResult();

        $fields = array_map(static fn ($entry) => $entry->getField(), $entries);
        self::assertSame(['identity', 'validity'], $fields, 'Exactly the two changed fields must be audited.');

        $validityEntry = $entries[array_search('validity', $fields, true)];
        self::assertSame('unreviewed', $validityEntry->getPreviousValue());
        self::assertSame('true_positive', $validityEntry->getNewValue());

        $identityEntry = $entries[array_search('identity', $fields, true)];
        self::assertNull($identityEntry->getPreviousValue());
        self::assertSame('Tom', $identityEntry->getNewValue());
    }

    public function testQuickClassifyLeavesExistingReviewerNotesUntouched(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/'.$detection->getId(), [
            'validity' => 'true_positive',
            'identity_label_id' => '',
            'false_positive_reason' => '',
            'reviewer_notes' => 'handwritten note',
        ]);

        self::assertResponseRedirects();

        $tom = $this->labelNamed('Tom');
        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
            'label' => (string) $tom->getId(),
        ]);

        self::assertResponseRedirects();

        $review = $this->reloadReview($detection);
        self::assertSame('handwritten note', $review->getReviewerNotes(), 'Quick classification is narrower than a full form submission.');
        self::assertSame('Tom', $review->getIdentityLabel()?->getName());
    }

    public function testQuickClassifyReturnsToValidatedReturnTo(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $tom = $this->labelNamed('Tom');

        $client = $this->client();
        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
            'label' => (string) $tom->getId(),
            'return_to' => '/detections?camera=front_door&review_state=unreviewed&page=2',
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            '/detections?camera=front_door&review_state=unreviewed&page=2',
            $client->getResponse()->headers->get('Location'),
        );
    }

    public function testQuickClassifyWithTamperedReturnToFallsBackToPlainDetectionsList(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $tom = $this->labelNamed('Tom');

        $client = $this->client();

        foreach (['https://evil.example/detections', '//evil.example/detections', '/events'] as $tampered) {
            $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
                'label' => (string) $tom->getId(),
                'return_to' => $tampered,
            ]);

            self::assertResponseRedirects();
            self::assertSame(
                '/detections',
                $client->getResponse()->headers->get('Location'),
                sprintf('A tampered return_to "%s" must never be followed (open-redirect guard).', $tampered),
            );
        }
    }

    public function testQuickClassifyWithUnknownLabelIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();

        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', ['label' => '99999']);
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', ['label' => 'Tom']);
        self::assertResponseStatusCodeSame(400, 'Only label ids are accepted, never names.');

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $reloaded = $entityManager->find(Detection::class, $detection->getId());
        \assert($reloaded instanceof Detection);
        self::assertNull($reloaded->getReview(), 'A rejected request must not classify anything.');
    }

    public function testQuickClassifyWithMismatchedKindLabelIsRejected(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection(sourceClass: 'dog');
        $tom = $this->labelNamed('Tom'); // person-kind label, dog detection

        $client = $this->client();
        $client->request('POST', '/detections/'.$detection->getId().'/quick-classify', [
            'label' => (string) $tom->getId(),
        ]);

        self::assertResponseStatusCodeSame(400);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $reloaded = $entityManager->find(Detection::class, $detection->getId());
        \assert($reloaded instanceof Detection);
        self::assertNull($reloaded->getReview(), 'A rejected classification must not leave a half-written review behind.');
    }

    public function testQuickClassifyWithUnknownDetectionIsNotFound(): void
    {
        $this->bootDatabase();

        $client = $this->client();
        $client->request('POST', '/detections/99999/quick-classify', ['label' => '1']);

        self::assertResponseStatusCodeSame(404);
    }

    // -- helpers ----------------------------------------------------------------

    private function submission(string $reason): \App\Domain\ReviewSubmission
    {
        return new \App\Domain\ReviewSubmission(
            validity: ReviewValidity::FalsePositive,
            identityLabel: null,
            correctedBox: null,
            correctedBoxProvided: false,
            falsePositiveReason: $reason,
            reviewerNotes: null,
        );
    }

    private function reloadReview(Detection $detection): Review
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $reloaded = $entityManager->find(Detection::class, $detection->getId());
        \assert($reloaded instanceof Detection);
        $review = $reloaded->getReview();
        self::assertNotNull($review, 'The detection must have a review row after being saved.');

        return $review;
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
