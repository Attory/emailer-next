<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Native Ripe forward migration. The Light transition has a separate verified baseline. */
final class Version20261015000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Explicit campaign-owned recipients, edition-bound crypto and case-insensitive suppression.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE campaign ADD audience_model VARCHAR(16) NOT NULL DEFAULT 'list', ADD CONSTRAINT chk_campaign_audience_model CHECK (BINARY audience_model IN ('list','campaign_owned'))");
        $this->addSql("ALTER TABLE backup_record ADD source_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-ripe', ADD CONSTRAINT chk_backup_source_edition CHECK (BINARY source_edition IN ('emailer-light','emailer-ripe'))");
        $this->addSql('ALTER TABLE installation_state ADD administrator_ready_at DATETIME(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE setup_installation_event ADD administrator_id CHAR(36) DEFAULT NULL, ADD CONSTRAINT fk_setup_legacy_administrator FOREIGN KEY (administrator_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('CREATE TABLE ripe_edition_transition (installation_uuid CHAR(36) NOT NULL PRIMARY KEY, source_identity_json JSON NOT NULL, source_migration_json JSON NOT NULL, source_product_id VARCHAR(32) NOT NULL, source_product_version VARCHAR(16) NOT NULL, source_schema_version VARCHAR(14) NOT NULL, source_contract_version VARCHAR(16) NOT NULL, source_created_at DATETIME(6) NOT NULL, source_updated_at DATETIME(6) NOT NULL, source_migration_version VARCHAR(191) NOT NULL, source_migration_executed_at DATETIME DEFAULT NULL, source_migration_execution_time INT DEFAULT NULL, source_schema_sha256 CHAR(64) NOT NULL, preservation_sha256 CHAR(64) NOT NULL, target_version VARCHAR(16) NOT NULL, target_schema VARCHAR(14) NOT NULL, completed_at DATETIME(6) NOT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ENGINE=InnoDB');
        $this->addSql("ALTER TABLE encrypted_secret ADD cipher_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-next', ADD CONSTRAINT chk_secret_edition CHECK (BINARY cipher_edition IN ('emailer-next', 'emailer-light'))");
        $this->addSql('ALTER TABLE suppression ADD email_match_key VARCHAR(254) GENERATED ALWAYS AS (LOWER(email_canonical)) STORED, ADD INDEX idx_suppression_match_key (email_match_key), ADD UNIQUE INDEX uniq_suppression_identity_match (id,email_match_key)');
        $this->addSql('CREATE TABLE suppression_match (email_match_key VARCHAR(254) NOT NULL PRIMARY KEY, suppression_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_suppression_match_owner (suppression_id), CONSTRAINT fk_suppression_match_identity FOREIGN KEY (suppression_id,email_match_key) REFERENCES suppression (id,email_match_key) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ENGINE=InnoDB');
        $this->addSql('INSERT INTO suppression_match (email_match_key,suppression_id) SELECT email_match_key,MIN(id) FROM suppression GROUP BY email_match_key');
        $this->addSql('CREATE TRIGGER suppression_match_after_insert AFTER INSERT ON suppression FOR EACH ROW INSERT IGNORE INTO suppression_match (email_match_key,suppression_id) VALUES (NEW.email_match_key,NEW.id)');
        $this->addSql('CREATE TRIGGER suppression_match_after_delete AFTER DELETE ON suppression FOR EACH ROW INSERT IGNORE INTO suppression_match (email_match_key,suppression_id) SELECT email_match_key,MIN(id) FROM suppression WHERE email_match_key = OLD.email_match_key GROUP BY email_match_key');
        foreach (['campaign_audience_recipient', 'campaign_recipient', 'recipient_token'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD recipient_id CHAR(36) DEFAULT NULL');
            $this->addSql('UPDATE '.$table.' SET recipient_id = contact_id');
        }
        $this->addSql('ALTER TABLE campaign_audience_recipient DROP PRIMARY KEY, MODIFY contact_id CHAR(36) DEFAULT NULL, ADD PRIMARY KEY (campaign_id, recipient_id)');
        foreach (['campaign_recipient', 'recipient_token'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' MODIFY contact_id CHAR(36) DEFAULT NULL');
        }
        $this->addSql('ALTER TABLE campaign_recipient ADD UNIQUE INDEX uniq_campaign_recipient_delivery (campaign_id, recipient_id), ADD UNIQUE INDEX uniq_delivery_scope (id, campaign_id, recipient_id), ADD CONSTRAINT fk_delivery_audience FOREIGN KEY (campaign_id, recipient_id) REFERENCES campaign_audience_recipient (campaign_id, recipient_id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE recipient_token ADD codec_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-next', ADD CONSTRAINT chk_token_edition CHECK (BINARY codec_edition IN ('emailer-next', 'emailer-light')), ADD CONSTRAINT fk_token_scope FOREIGN KEY (campaign_recipient_id, campaign_id, recipient_id) REFERENCES campaign_recipient (id, campaign_id, recipient_id) ON DELETE RESTRICT");
        $this->addSql("CREATE TRIGGER campaign_audience_model_immutable BEFORE UPDATE ON campaign FOR EACH ROW BEGIN IF BINARY NEW.audience_model <> BINARY OLD.audience_model THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campaign audience provenance is immutable'; END IF; END");
        foreach (['campaign_audience_recipient', 'campaign_recipient', 'recipient_token'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.'_identity CHECK (recipient_id IS NOT NULL AND (contact_id IS NULL OR BINARY contact_id = BINARY recipient_id))');
            foreach (['INSERT', 'UPDATE'] as $event) {
                $body = "SET NEW.recipient_id = COALESCE(NULLIF(NEW.recipient_id,''), NEW.contact_id);";
                if ('campaign_audience_recipient' === $table) {
                    $body .= " IF NOT EXISTS (SELECT 1 FROM campaign c WHERE c.id = NEW.campaign_id AND ((c.audience_model = 'campaign_owned' AND NEW.contact_id IS NULL) OR (c.audience_model = 'list' AND NEW.contact_id IS NOT NULL))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Campaign audience model mismatch'; END IF;";
                }
                if ('campaign_audience_recipient' !== $table) {
                    $body .= " IF NOT EXISTS (SELECT 1 FROM campaign_audience_recipient a WHERE a.campaign_id = NEW.campaign_id AND a.recipient_id = NEW.recipient_id AND a.contact_id <=> NEW.contact_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recipient provenance mismatch'; END IF;";
                }
                $this->addSql('CREATE TRIGGER '.$table.'_identity_'.strtolower($event).' BEFORE '.$event.' ON '.$table.' FOR EACH ROW BEGIN '.$body.' END');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore a verified complete pre-upgrade recovery set.');
    }
}
