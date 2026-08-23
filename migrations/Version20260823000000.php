<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7 recipient-token key ring, bounded unsubscribe tokens, and immutable unsubscribe delivery settings.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 7 requires MariaDB/MySQL.');

        $this->addSql('ALTER TABLE sender_identity ADD one_click_dkim_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER tls_peer_verification, ADD CONSTRAINT chk_sender_one_click_dkim CHECK (one_click_dkim_confirmed IN (0, 1))');
        $this->addSql('ALTER TABLE campaign_content_snapshot ADD unsubscribe_footer VARCHAR(500) DEFAULT NULL AFTER text_body, ADD one_click_dkim_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER unsubscribe_footer, ADD CONSTRAINT chk_campaign_one_click_dkim CHECK (one_click_dkim_confirmed IN (0, 1))');
        $this->addSql("UPDATE campaign_content_snapshot s CROSS JOIN privacy_policy p SET s.unsubscribe_footer = p.unsubscribe_footer WHERE p.singleton_key = '1' AND s.unsubscribe_footer IS NULL");
        $this->addSql('ALTER TABLE campaign_content_snapshot MODIFY unsubscribe_footer VARCHAR(500) NOT NULL');

        $this->addSql(<<<'SQL'
CREATE TABLE recipient_token_key_state (
    singleton_key CHAR(1) NOT NULL,
    current_version SMALLINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(singleton_key),
    CONSTRAINT chk_recipient_key_state_singleton CHECK (singleton_key = '1')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("INSERT INTO recipient_token_key_state VALUES ('1', 0, UTC_TIMESTAMP(6))");
        $this->addSql(<<<'SQL'
CREATE TABLE recipient_token_key (
    key_version SMALLINT UNSIGNED NOT NULL,
    secret_id CHAR(36) NOT NULL,
    active TINYINT(1) NOT NULL,
    verify_until DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(key_version),
    UNIQUE INDEX uniq_recipient_token_secret (secret_id),
    INDEX idx_recipient_token_key_retention (active, verify_until),
    CONSTRAINT fk_recipient_token_key_secret FOREIGN KEY (secret_id) REFERENCES encrypted_secret (id) ON DELETE RESTRICT,
    CONSTRAINT chk_recipient_token_key_active CHECK (active IN (0, 1))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE recipient_token (
    id CHAR(36) NOT NULL,
    handle CHAR(43) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    campaign_recipient_id CHAR(36) NOT NULL,
    contact_id CHAR(36) NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    key_version SMALLINT UNSIGNED NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) DEFAULT NULL,
    last_used_at DATETIME(6) DEFAULT NULL,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_recipient_token_handle (handle),
    UNIQUE INDEX uniq_recipient_token_scope (campaign_recipient_id, purpose),
    INDEX idx_recipient_token_retention (expires_at, revoked_at),
    CONSTRAINT fk_recipient_token_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recipient_token_delivery FOREIGN KEY (campaign_recipient_id) REFERENCES campaign_recipient (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recipient_token_contact FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recipient_token_key FOREIGN KEY (key_version) REFERENCES recipient_token_key (key_version) ON DELETE RESTRICT,
    CONSTRAINT chk_recipient_token_purpose CHECK (purpose = 'unsubscribe')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 7 suppression authority and recipient-token history are forward-only. Restore a verified pre-migration backup instead.');
    }
}
