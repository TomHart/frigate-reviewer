<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the review-system schema: snapshot, frigate_event, detection,
 * review, label and audit_entry per design.md D3/D4. Frigate facts live on
 * frigate_event/detection; human annotations live on review; the corrected
 * bounding box lives on review, the original Frigate box on detection.
 */
final class Version20261112002321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create review-system schema (snapshot, frigate_event, detection, review, label, audit_entry)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, who VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, field VARCHAR(255) NOT NULL, previous_value CLOB DEFAULT NULL, new_value CLOB DEFAULT NULL, detection_id INTEGER NOT NULL, CONSTRAINT FK_2C6AF987A8773B17 FOREIGN KEY (detection_id) REFERENCES detection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_audit_entry_detection ON audit_entry (detection_id)');
        $this->addSql('CREATE TABLE detection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, origin VARCHAR(255) NOT NULL, source_class VARCHAR(255) DEFAULT NULL, confidence DOUBLE PRECISION DEFAULT NULL, start_time DATETIME DEFAULT NULL, end_time DATETIME DEFAULT NULL, box_x DOUBLE PRECISION DEFAULT NULL, box_y DOUBLE PRECISION DEFAULT NULL, box_width DOUBLE PRECISION DEFAULT NULL, box_height DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, frigate_event_id INTEGER DEFAULT NULL, snapshot_id INTEGER DEFAULT NULL, CONSTRAINT FK_A35F1C6A461F03 FOREIGN KEY (frigate_event_id) REFERENCES frigate_event (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A35F1C67B39395E FOREIGN KEY (snapshot_id) REFERENCES snapshot (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A35F1C6A461F03 ON detection (frigate_event_id)');
        $this->addSql('CREATE INDEX IDX_A35F1C67B39395E ON detection (snapshot_id)');
        $this->addSql('CREATE TABLE frigate_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, frigate_event_id VARCHAR(255) NOT NULL, camera VARCHAR(255) NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, clip_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CB69C589A461F03 ON frigate_event (frigate_event_id)');
        $this->addSql('CREATE TABLE label (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(255) NOT NULL, sort_order INTEGER NOT NULL, active BOOLEAN NOT NULL, exported_class_id INTEGER NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EA750E85E237E06 ON label (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EA750E8186630F0 ON label (exported_class_id)');
        $this->addSql('CREATE TABLE review (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, validity VARCHAR(255) NOT NULL, corrected_x DOUBLE PRECISION DEFAULT NULL, corrected_y DOUBLE PRECISION DEFAULT NULL, corrected_width DOUBLE PRECISION DEFAULT NULL, corrected_height DOUBLE PRECISION DEFAULT NULL, false_positive_reason CLOB DEFAULT NULL, reviewer_notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, detection_id INTEGER NOT NULL, identity_label_id INTEGER DEFAULT NULL, CONSTRAINT FK_794381C6A8773B17 FOREIGN KEY (detection_id) REFERENCES detection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_794381C6705273F0 FOREIGN KEY (identity_label_id) REFERENCES label (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_794381C6A8773B17 ON review (detection_id)');
        $this->addSql('CREATE INDEX idx_review_validity ON review (validity)');
        $this->addSql('CREATE INDEX IDX_794381C6705273F0 ON review (identity_label_id)');
        $this->addSql('CREATE TABLE snapshot (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, content_hash VARCHAR(255) NOT NULL, original_path VARCHAR(255) NOT NULL, thumbnail_path VARCHAR(255) DEFAULT NULL, perceptual_hash VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2C4D15351CDA8F7D ON snapshot (content_hash)');
        $this->addSql('CREATE INDEX idx_snapshot_perceptual_hash ON snapshot (perceptual_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_entry');
        $this->addSql('DROP TABLE detection');
        $this->addSql('DROP TABLE frigate_event');
        $this->addSql('DROP TABLE label');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE snapshot');
    }
}
