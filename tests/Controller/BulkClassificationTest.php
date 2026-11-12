<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\ReviewService;
use App\Domain\ReviewSubmission;
use App\Entity\AuditEntry;
use App\Entity\Detection;
use App\Entity\Label;
use App\Entity\Review;
use App\Enum\ReviewValidity;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Functional tests for bulk classification over a similar-snapshot group
 * selection (task 5.3, design.md D8, spec "Bulk classification applies to
 * group"): every selected object gets validity + reason, each object records
 * its own audit entries, unchanged fields are untouched, and nothing is ever
 * merged or discarded. Also covers the "similar snapshots" grouped view.
 */
final class BulkClassificationTest extends AbstractDatabaseTestCase
{
    public function testBulkClassificationAppliesToEverySelectedObjectWithIndividualAuditEntries(): void
    {
        $this->bootDatabase();

        $finola = $this->labelNamed('Finola');
        $reviewService = $this->reviewService();

        $withIdentity = $this->createFrigateDetection();
        $reviewService->save($withIdentity, new ReviewSubmission(
            validity: ReviewValidity::TruePositive,
            identityLabel: $finola,
            correctedBox: null,
            correctedBoxProvided: false,
            falsePositiveReason: null,
            reviewerNotes: 'keep me',
        ));

        $withPreviousReason = $this->createFrigateDetection();
        $reviewService->save($withPreviousReason, new ReviewSubmission(
            validity: ReviewValidity::TruePositive,
            identityLabel: null,
            correctedBox: null,
            correctedBoxProvided: false,
            falsePositiveReason: 'old reason',
            reviewerNotes: null,
        ));

        $neverReviewed = $this->createFrigateDetection();
        $notSelected = $this->createFrigateDetection();
        $reviewService->save($notSelected, new ReviewSubmission(
            validity: ReviewValidity::TruePositive,
            identityLabel: $finola,
            correctedBox: null,
            correctedBoxProvided: false,
            falsePositiveReason: null,
            reviewerNotes: null,
        ));

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [
                (string) $withIdentity->getId(),
                (string) $withPreviousReason->getId(),
                (string) $neverReviewed->getId(),
            ],
            'validity' => 'false_positive',
            'false_positive_reason' => 'tree shadow',
        ]);

        self::assertResponseRedirects();

        $this->entityManager()->clear();

        $withIdentity = $this->reloadDetection($withIdentity);
        $withPreviousReason = $this->reloadDetection($withPreviousReason);
        $neverReviewed = $this->reloadDetection($neverReviewed);
        $notSelected = $this->reloadDetection($notSelected);

        // Every selected object: validity + reason applied, identity cleared
        // by the false positive (design.md D7), untouched fields untouched.
        foreach ([$withIdentity, $withPreviousReason, $neverReviewed] as $detection) {
            $review = $detection->getReview();
            self::assertNotNull($review, 'Bulk classification must give every selected object a review.');
            self::assertSame(ReviewValidity::FalsePositive, $review->getValidity());
            self::assertSame('tree shadow', $review->getFalsePositiveReason());
        }

        self::assertNull($withIdentity->getReview()?->getIdentityLabel(), 'A false positive must clear identity.');
        self::assertSame('keep me', $withIdentity->getReview()?->getReviewerNotes(), 'Fields the bulk form does not submit must stay untouched.');
        self::assertNull($withPreviousReason->getReview()?->getReviewerNotes());
        self::assertNull($neverReviewed->getReview()?->getCorrectedBox());

        // Unselected objects are not touched.
        $notSelectedReview = $notSelected->getReview();
        self::assertNotNull($notSelectedReview);
        self::assertSame(ReviewValidity::TruePositive, $notSelectedReview->getValidity());
        self::assertSame($finola->getId(), $notSelectedReview->getIdentityLabel()?->getId());
        self::assertNull($notSelectedReview->getFalsePositiveReason());

        // Each object records its own audit entries, in order, exactly for
        // the fields that changed on it — including the history from the
        // earlier per-object saves.
        self::assertSame(
            [
                ['validity', 'unreviewed', 'true_positive'],
                ['identity', null, 'Finola'],
                ['reviewer_notes', null, 'keep me'],
                ['validity', 'true_positive', 'false_positive'],
                ['identity', 'Finola', null],
                ['false_positive_reason', null, 'tree shadow'],
            ],
            $this->auditChangesFor($withIdentity),
        );
        self::assertSame(
            [
                ['validity', 'unreviewed', 'true_positive'],
                ['false_positive_reason', null, 'old reason'],
                ['validity', 'true_positive', 'false_positive'],
                ['false_positive_reason', 'old reason', 'tree shadow'],
            ],
            $this->auditChangesFor($withPreviousReason),
        );
        self::assertSame(
            [
                ['validity', 'unreviewed', 'false_positive'],
                ['false_positive_reason', null, 'tree shadow'],
            ],
            $this->auditChangesFor($neverReviewed),
        );
        self::assertSame(
            [
                ['validity', 'unreviewed', 'true_positive'],
                ['identity', null, 'Finola'],
            ],
            $this->auditChangesFor($notSelected),
            'No audit entries beyond the earlier save for objects outside the selection.',
        );
    }

    public function testBulkClassificationRedirectsToDetectionsListPreservingFilters(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [(string) $detection->getId()],
            'validity' => 'false_positive',
            'false_positive_reason' => 'tree shadow',
            'return_to' => '/detections?similar=1&camera=front_door&page=2',
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            '/detections?similar=1&camera=front_door&page=2',
            $client->getResponse()->headers->get('Location'),
            'Bulk classification must return the reviewer to the filtered list they came from.',
        );
    }

    public function testBulkClassificationWithTamperedReturnToFallsBackToPlainDetectionsList(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [(string) $detection->getId()],
            'validity' => 'false_positive',
            'false_positive_reason' => '',
            'return_to' => 'https://evil.example/detections',
        ]);

        self::assertResponseRedirects();
        self::assertSame(
            '/detections',
            $client->getResponse()->headers->get('Location'),
            'A tampered return_to must never be followed (open-redirect guard).',
        );
    }

    public function testBulkClassificationTruePositiveLeavesIdentityUnset(): void
    {
        $this->bootDatabase();

        $finola = $this->labelNamed('Finola');
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [(string) $detection->getId()],
            'validity' => 'true_positive',
            'false_positive_reason' => '',
        ]);

        self::assertResponseRedirects();
        $this->entityManager()->clear();

        $detection = $this->reloadDetection($detection);
        $review = $detection->getReview();
        self::assertNotNull($review);
        self::assertSame(ReviewValidity::TruePositive, $review->getValidity());
        self::assertNull($review->getFalsePositiveReason());
        self::assertNull($review->getIdentityLabel(), 'Bulk classification never sets an identity; an unset one stays unset.');

        self::assertSame(
            [['validity', 'unreviewed', 'true_positive']],
            $this->auditChangesFor($detection),
        );
    }

    public function testBulkClassificationRequiresAtLeastOneSelectedObject(): void
    {
        $this->bootDatabase();
        $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [],
            'validity' => 'false_positive',
            'false_positive_reason' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testBulkClassificationRejectsUnknownDetectionId(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [(string) $detection->getId(), '999999'],
            'validity' => 'false_positive',
            'false_positive_reason' => '',
        ]);

        self::assertResponseStatusCodeSame(400, 'A partial selection must never be silently classified.');
    }

    public function testBulkClassificationRejectsUnknownValidity(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $client = $this->client();
        $client->request('POST', '/review/bulk', [
            'detection_ids' => [(string) $detection->getId()],
            'validity' => 'bogus',
            'false_positive_reason' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSimilarViewGroupsDetectionsAndOffersBulkForm(): void
    {
        $this->bootDatabase();

        $grouped = $this->createDetectionWithHash('0000000000000000');
        $groupedAlso = $this->createDetectionWithHash('0000000000000003');
        $separate = $this->createDetectionWithHash('f0f0f0f0f0f0f0f0');

        $crawler = $this->client()->request('GET', '/detections?similar=1');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('input[name="detection_ids[]"]'), 'Every object in the view gets a selection checkbox.');
        self::assertCount(1, $crawler->filter('form[action="/review/bulk"]'));
        self::assertStringContainsString('Classify selected', $crawler->filter('form[action="/review/bulk"] button[type="submit"]')->text());
        self::assertCount(2, $crawler->filter('section h2'), 'Two groups: the similar pair and the unrelated singleton.');

        // Checkbox values cover exactly the listed detections.
        $checkboxValues = array_map(
            static fn (\DOMElement $checkbox): string => (string) $checkbox->getAttribute('value'),
            iterator_to_array($crawler->filter('input[name="detection_ids[]"]')),
        );
        self::assertEqualsCanonicalizing(
            [(string) $grouped->getId(), (string) $groupedAlso->getId(), (string) $separate->getId()],
            $checkboxValues,
        );
    }

    public function testDefaultListingHasNoBulkSelection(): void
    {
        $this->bootDatabase();
        $this->createFrigateDetection();

        $crawler = $this->client()->request('GET', '/detections');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="detection_ids[]"]'));
        self::assertCount(0, $crawler->filter('form[action="/review/bulk"]'));
    }

    private function createDetectionWithHash(string $perceptualHash): Detection
    {
        $detection = $this->createFrigateDetection();
        $snapshot = $detection->getSnapshot();
        self::assertNotNull($snapshot);
        $snapshot->setPerceptualHash($perceptualHash);
        $this->persist($snapshot);

        return $detection;
    }

    /**
     * The detection's full audit history as [field, previous value, new
     * value] tuples in entry order.
     *
     * @return list<list<?string>>
     */
    private function auditChangesFor(Detection $detection): array
    {
        $entries = $this->entityManager()->createQueryBuilder()
            ->select('a')
            ->from(AuditEntry::class, 'a')
            ->where('a.detection = :detectionId')
            ->setParameter('detectionId', $detection->getId())
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (AuditEntry $entry): array => [$entry->getField(), $entry->getPreviousValue(), $entry->getNewValue()],
            $entries,
        );
    }

    private function reloadDetection(Detection $detection): Detection
    {
        $reloaded = $this->entityManager()->find(Detection::class, $detection->getId());
        \assert($reloaded instanceof Detection);

        return $reloaded;
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
