<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 10 purpose-scoped browser and analytics tokens, frozen tracking policy, minimized aggregates, and retention evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 10 requires MariaDB/MySQL.');
        $this->addSql('ALTER TABLE privacy_policy DROP CONSTRAINT chk_tracking_defaults, ADD tracking_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER delivery_retention_days, ADD CONSTRAINT chk_privacy_features CHECK (open_tracking IN (0, 1) AND click_tracking IN (0, 1) AND rsvp_enabled = 0), ADD CONSTRAINT chk_tracking_retention CHECK (tracking_retention_days BETWEEN 1 AND 365)');
        $this->addSql('ALTER TABLE campaign_content_snapshot ADD link_manifest_json JSON DEFAULT NULL AFTER merge_fields_json, ADD open_tracking TINYINT(1) NOT NULL DEFAULT 0 AFTER link_manifest_json, ADD click_tracking TINYINT(1) NOT NULL DEFAULT 0 AFTER open_tracking, ADD CONSTRAINT chk_campaign_tracking CHECK (open_tracking IN (0, 1) AND click_tracking IN (0, 1))');
        $this->addSql('UPDATE campaign_content_snapshot s INNER JOIN template_version v ON v.id = s.template_version_id SET s.link_manifest_json = v.link_manifest_json WHERE s.link_manifest_json IS NULL');
        $this->addSql('ALTER TABLE campaign_content_snapshot MODIFY link_manifest_json JSON NOT NULL');
        $this->addSql("ALTER TABLE recipient_token DROP CONSTRAINT chk_recipient_token_purpose, ADD CONSTRAINT chk_recipient_token_purpose CHECK (purpose IN ('unsubscribe', 'view', 'open', 'click'))");
        $this->addSql('ALTER TABLE recipient_token MODIFY email_canonical VARCHAR(254) DEFAULT NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE analytics_aggregate (
    campaign_recipient_id CHAR(36) NOT NULL,
    event_type VARCHAR(8) NOT NULL,
    link_index SMALLINT UNSIGNED NOT NULL,
    event_count INT UNSIGNED NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY(campaign_recipient_id, event_type, link_index),
    INDEX idx_analytics_retention (last_seen_at),
    CONSTRAINT fk_analytics_recipient FOREIGN KEY (campaign_recipient_id) REFERENCES campaign_recipient (id) ON DELETE CASCADE,
    CONSTRAINT chk_analytics_event CHECK (event_type IN ('open', 'click')),
    CONSTRAINT chk_analytics_link CHECK ((event_type = 'open' AND link_index = 0) OR event_type = 'click')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE housekeeping_run ADD deleted_analytics_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER deleted_health_runs');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 10 recipient privacy and analytics history are forward-only. Restore a verified pre-migration backup instead.');
    }
}
