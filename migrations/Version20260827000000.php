<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 11 optional RSVP policy, frozen campaign form, purpose-scoped tokens, private responses, and retention evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 11 requires MariaDB/MySQL.');
        $this->addSql("ALTER TABLE privacy_policy DROP CONSTRAINT chk_privacy_features, ADD rsvp_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER tracking_retention_days, ADD rsvp_repeat_policy VARCHAR(8) NOT NULL DEFAULT 'replace' AFTER rsvp_retention_days, ADD CONSTRAINT chk_privacy_features CHECK (open_tracking IN (0, 1) AND click_tracking IN (0, 1) AND rsvp_enabled IN (0, 1)), ADD CONSTRAINT chk_rsvp_retention CHECK (rsvp_retention_days BETWEEN 1 AND 365), ADD CONSTRAINT chk_rsvp_repeat CHECK (rsvp_repeat_policy IN ('first', 'replace'))");
        $this->addSql('ALTER TABLE campaign ADD rsvp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_waived_test, ADD CONSTRAINT chk_campaign_rsvp CHECK (rsvp_enabled IN (0, 1))');
        $this->addSql("ALTER TABLE campaign_content_snapshot ADD rsvp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER click_tracking, ADD rsvp_repeat_policy VARCHAR(8) NOT NULL DEFAULT 'replace' AFTER rsvp_enabled, ADD rsvp_form_schema_json JSON DEFAULT NULL AFTER rsvp_repeat_policy, ADD CONSTRAINT chk_campaign_snapshot_rsvp CHECK (rsvp_enabled IN (0, 1) AND rsvp_repeat_policy IN ('first', 'replace'))");
        $this->addSql(<<<'SQL'
UPDATE campaign_content_snapshot
SET rsvp_form_schema_json = '{"fields":["response","guests","note"],"choices":["yes","no","maybe"],"max_guests":20,"max_note_length":500}'
WHERE rsvp_form_schema_json IS NULL
SQL);
        $this->addSql('ALTER TABLE campaign_content_snapshot MODIFY rsvp_form_schema_json JSON NOT NULL');
        $this->addSql("ALTER TABLE recipient_token DROP CONSTRAINT chk_recipient_token_purpose, ADD CONSTRAINT chk_recipient_token_purpose CHECK (purpose IN ('unsubscribe', 'view', 'open', 'click', 'rsvp'))");
        $this->addSql(<<<'SQL'
CREATE TABLE rsvp_response (
    campaign_recipient_id CHAR(36) NOT NULL,
    response_choice VARCHAR(5) NOT NULL,
    guest_count TINYINT UNSIGNED NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    submission_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    first_submitted_at DATETIME(6) NOT NULL,
    last_submitted_at DATETIME(6) NOT NULL,
    PRIMARY KEY(campaign_recipient_id),
    INDEX idx_rsvp_retention (last_submitted_at),
    CONSTRAINT fk_rsvp_recipient FOREIGN KEY (campaign_recipient_id) REFERENCES campaign_recipient (id) ON DELETE CASCADE,
    CONSTRAINT chk_rsvp_choice CHECK (response_choice IN ('yes', 'no', 'maybe')),
    CONSTRAINT chk_rsvp_guests CHECK (guest_count BETWEEN 0 AND 20),
    CONSTRAINT chk_rsvp_submissions CHECK (submission_count >= 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE housekeeping_run ADD deleted_rsvp_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER deleted_analytics_rows');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 11 RSVP policy and private response history are forward-only. Restore a verified pre-migration backup instead.');
    }
}
