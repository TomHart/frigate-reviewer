<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the five initial labels required by the spec ("Initial labels exist"):
 * Finola, Tom and Person (kind person); Dougie and Dog (kind dog).
 *
 * Each label receives a permanent exported class ID, allocated monotonically
 * (design.md D5). These IDs are part of the data-preservation contract and
 * must never be rewritten by any later migration.
 */
final class Version20261112030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the five initial labels (Finola, Tom, Person, Dougie, Dog) with permanent exported class IDs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO label (name, kind, sort_order, active, exported_class_id, created_at) VALUES ('Finola', 'person', 1, 1, 1, CURRENT_TIMESTAMP)");
        $this->addSql("INSERT INTO label (name, kind, sort_order, active, exported_class_id, created_at) VALUES ('Tom', 'person', 2, 1, 2, CURRENT_TIMESTAMP)");
        $this->addSql("INSERT INTO label (name, kind, sort_order, active, exported_class_id, created_at) VALUES ('Person', 'person', 3, 1, 3, CURRENT_TIMESTAMP)");
        $this->addSql("INSERT INTO label (name, kind, sort_order, active, exported_class_id, created_at) VALUES ('Dougie', 'dog', 4, 1, 4, CURRENT_TIMESTAMP)");
        $this->addSql("INSERT INTO label (name, kind, sort_order, active, exported_class_id, created_at) VALUES ('Dog', 'dog', 5, 1, 5, CURRENT_TIMESTAMP)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM label WHERE name IN ('Finola', 'Tom', 'Person', 'Dougie', 'Dog')");
    }
}
