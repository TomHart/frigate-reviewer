<?php

declare(strict_types=1);

namespace App\Tests\Label;

use App\Domain\LabelManager;
use App\Entity\Label;
use App\Enum\LabelKind;
use App\Tests\AbstractDatabaseTestCase;

/**
 * Spec: "Exported class IDs permanent" — allocated monotonically at creation
 * inside a transaction, never changed by deactivate/rename, never reassigned
 * (design.md D5).
 */
final class LabelClassIdTest extends AbstractDatabaseTestCase
{
    private LabelManager $labelManager;

    protected function setUp(): void
    {
        $this->bootDatabase();
        $this->labelManager = new LabelManager($this->entityManager());
    }

    public function testSeededLabelsHavePermanentUniqueClassIds(): void
    {
        $expected = [
            'Finola' => [LabelKind::Person, 1],
            'Tom' => [LabelKind::Person, 2],
            'Person' => [LabelKind::Person, 3],
            'Dougie' => [LabelKind::Dog, 4],
            'Dog' => [LabelKind::Dog, 5],
        ];

        foreach ($expected as $name => [$kind, $exportedClassId]) {
            $label = $this->labelNamed($name);
            self::assertSame($kind, $label->getKind(), "Label $name has the wrong kind.");
            self::assertSame($exportedClassId, $label->getExportedClassId(), "Label $name has the wrong exported class ID.");
            self::assertTrue($label->isActive());
        }
    }

    public function testNewLabelsReceiveTheNextClassIdMonotonically(): void
    {
        $postman = $this->labelManager->create('Postman', LabelKind::Person, 6);

        self::assertSame(6, $postman->getExportedClassId());

        $neighbour = $this->labelManager->create('Neighbour', LabelKind::Person, 7);

        self::assertSame(7, $neighbour->getExportedClassId());
    }

    public function testDeactivationKeepsClassIdAndNeverReassignsIt(): void
    {
        $finola = $this->labelNamed('Finola');
        $finolaClassId = $finola->getExportedClassId();

        $this->labelManager->deactivate($finola);

        $reloaded = $this->labelNamed('Finola');
        self::assertFalse($reloaded->isActive());
        self::assertSame($finolaClassId, $reloaded->getExportedClassId(), 'Deactivation must not change the exported class ID.');

        $newLabel = $this->labelManager->create('Postman', LabelKind::Person, 6);
        self::assertNotSame($finolaClassId, $newLabel->getExportedClassId(), 'A deactivated label\'s class ID must never be reassigned.');
        self::assertSame(6, $newLabel->getExportedClassId());
    }

    public function testRenameKeepsClassId(): void
    {
        $dougie = $this->labelNamed('Dougie');
        $dougieClassId = $dougie->getExportedClassId();

        $this->labelManager->rename($dougie, 'Dougie (dog)');

        $reloaded = $this->labelNamed('Dougie (dog)');
        self::assertSame($dougieClassId, $reloaded->getExportedClassId(), 'Rename must not change the exported class ID.');
        self::assertNull($this->entityManager()->getRepository(Label::class)->findOneBy(['name' => 'Dougie']));
    }

    public function testDuplicateLabelNameIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already exists');
        $this->labelManager->create('Finola', LabelKind::Person, 99);
    }

    private function labelNamed(string $name): Label
    {
        $label = $this->entityManager()->getRepository(Label::class)->findOneBy(['name' => $name]);
        \assert($label instanceof Label);

        return $label;
    }
}
