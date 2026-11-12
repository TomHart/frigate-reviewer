<?php

declare(strict_types=1);

namespace App\Tests\Review;

use App\Domain\AuditTrail;
use App\Domain\BoundingBox;
use App\Domain\ReviewService;
use App\Entity\AuditEntry;
use App\Entity\Detection;
use App\Entity\Label;
use App\Enum\ReviewValidity;
use App\Repository\AuditEntryRepository;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Audit-trail coverage (spec "Annotation audit trail", design.md D9): every
 * review write path records who, when, field, previous value and new value.
 * The full-form save and bulk classification are covered by the controller
 * tests; here the individual field setters are checked too, since they are
 * write paths in their own right.
 */
final class AuditTrailTest extends AbstractDatabaseTestCase
{
    public function testSetValidityWritesAuditEntry(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();

        $this->reviewService()->setValidity($detection, ReviewValidity::FalsePositive);

        [$entry] = $this->auditEntriesFor($detection);
        self::assertSame('validity', $entry->getField());
        self::assertSame('unreviewed', $entry->getPreviousValue());
        self::assertSame('false_positive', $entry->getNewValue());
        self::assertSame(AuditTrail::DEFAULT_REVIEWER, $entry->getWho());
    }

    public function testSetIdentityWritesAuditEntryWithLabelName(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection('person');
        $finola = $this->labelNamed('Finola');

        $this->reviewService()->setIdentity($detection, $finola);

        [$entry] = $this->auditEntriesFor($detection);
        self::assertSame('identity', $entry->getField());
        self::assertNull($entry->getPreviousValue());
        self::assertSame('Finola', $entry->getNewValue());
    }

    public function testSetCorrectedBoxRecordsSetAndClearAsHumanReadableBoxes(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $reviewService = $this->reviewService();

        $reviewService->setCorrectedBox($detection, new BoundingBox(0.1, 0.2, 0.3, 0.4));
        $reviewService->setCorrectedBox($detection, null);

        [$cleared, $set] = $this->auditEntriesFor($detection); // newest first
        self::assertSame('corrected_box', $cleared->getField());
        self::assertSame('0.100000,0.200000,0.300000,0.400000', $cleared->getPreviousValue());
        self::assertNull($cleared->getNewValue());
        self::assertSame('corrected_box', $set->getField());
        self::assertNull($set->getPreviousValue());
        self::assertSame('0.100000,0.200000,0.300000,0.400000', $set->getNewValue());
    }

    public function testUnchangedFieldWritesNoAuditEntry(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $reviewService = $this->reviewService();

        $reviewService->setValidity($detection, ReviewValidity::Unreviewed); // already the default
        $reviewService->setValidity($detection, ReviewValidity::Unreviewed);

        self::assertSame([], $this->auditEntriesFor($detection), 'No change, no audit entry.');
    }

    public function testReviewPageListsAuditEntriesNewestFirst(): void
    {
        $this->bootDatabase();
        $detection = $this->createFrigateDetection();
        $reviewService = $this->reviewService();
        $reviewService->setValidity($detection, ReviewValidity::TruePositive);
        $reviewService->setCorrectedBox($detection, new BoundingBox(0.1, 0.2, 0.3, 0.4));

        $crawler = $this->client()->request('GET', '/review/'.$detection->getId());

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('[data-audit-history] > li')->each(
            static fn ($node) => $node->text(),
        );

        self::assertCount(2, $items);
        self::assertStringContainsString('corrected box', $items[0], 'The newest entry is listed first.');
        self::assertStringContainsString('0.100000,0.200000,0.300000,0.400000', $items[0]);
        self::assertStringContainsString('validity', $items[1]);
        self::assertStringContainsString('unreviewed', $items[1]);
        self::assertStringContainsString('true_positive', $items[1]);
    }

    /**
     * @return list<AuditEntry>
     */
    private function auditEntriesFor(Detection $detection): array
    {
        $repository = new AuditEntryRepository($this->entityManager());

        return $repository->forDetection($detection);
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
