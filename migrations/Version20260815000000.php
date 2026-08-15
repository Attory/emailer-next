<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 secure setup schema for installation state, organisation, Owner, MFA, encrypted secrets, sender identity, policies, and completion evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 3 requires MariaDB/MySQL.');

        $this->addSql(<<<'SQL'
CREATE TABLE installation_state (
    singleton_key CHAR(1) NOT NULL,
    installation_uuid CHAR(36) DEFAULT NULL,
    database_ready_at DATETIME(6) DEFAULT NULL,
    key_ready_at DATETIME(6) DEFAULT NULL,
    organisation_ready_at DATETIME(6) DEFAULT NULL,
    owner_ready_at DATETIME(6) DEFAULT NULL,
    mail_ready_at DATETIME(6) DEFAULT NULL,
    policy_ready_at DATETIME(6) DEFAULT NULL,
    capability_ready_at DATETIME(6) DEFAULT NULL,
    completed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(singleton_key),
    UNIQUE INDEX uniq_installation_uuid (installation_uuid),
    CONSTRAINT chk_installation_singleton CHECK (singleton_key = '1')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("INSERT INTO installation_state (singleton_key, database_ready_at) VALUES ('1', UTC_TIMESTAMP(6))");

        $this->addSql(<<<'SQL'
CREATE TABLE organisation_settings (
    singleton_key CHAR(1) NOT NULL,
    installation_uuid CHAR(36) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    canonical_base_url VARCHAR(2048) NOT NULL,
    locale VARCHAR(16) NOT NULL,
    time_zone VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(singleton_key),
    UNIQUE INDEX uniq_organisation_installation (installation_uuid),
    CONSTRAINT chk_organisation_singleton CHECK (singleton_key = '1')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE encrypted_secret (
    id CHAR(36) NOT NULL,
    record_type VARCHAR(64) NOT NULL,
    envelope LONGTEXT NOT NULL,
    key_version SMALLINT UNSIGNED NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    rotated_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_encrypted_secret_type (record_type)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE app_user (
    id CHAR(36) NOT NULL,
    login_identifier VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    active TINYINT(1) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_user_login (login_identifier),
    INDEX idx_user_role_active (role, active),
    CONSTRAINT chk_user_role CHECK (role IN ('ROLE_OWNER'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE mfa_credential (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    credential_type VARCHAR(32) NOT NULL,
    encrypted_secret_id CHAR(36) DEFAULT NULL,
    value_hash VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL,
    consumed_at DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_mfa_user_type (user_id, credential_type),
    UNIQUE INDEX uniq_mfa_encrypted_secret (encrypted_secret_id),
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE,
    CONSTRAINT fk_mfa_secret FOREIGN KEY (encrypted_secret_id) REFERENCES encrypted_secret (id) ON DELETE RESTRICT,
    CONSTRAINT chk_mfa_type CHECK (credential_type IN ('totp', 'recovery_code'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE sender_identity (
    singleton_key CHAR(1) NOT NULL,
    transport_type VARCHAR(16) NOT NULL,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL,
    encryption_mode VARCHAR(16) NOT NULL,
    smtp_username VARCHAR(254) DEFAULT NULL,
    credential_secret_id CHAR(36) DEFAULT NULL,
    sender_address VARCHAR(254) NOT NULL,
    sender_name VARCHAR(160) NOT NULL,
    reply_to_address VARCHAR(254) DEFAULT NULL,
    tls_peer_verification TINYINT(1) NOT NULL,
    test_message_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(singleton_key),
    UNIQUE INDEX uniq_sender_credential (credential_secret_id),
    CONSTRAINT fk_sender_secret FOREIGN KEY (credential_secret_id) REFERENCES encrypted_secret (id) ON DELETE RESTRICT,
    CONSTRAINT chk_sender_singleton CHECK (singleton_key = '1'),
    CONSTRAINT chk_sender_tls CHECK (tls_peer_verification = 1)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE delivery_policy (
    singleton_key CHAR(1) NOT NULL,
    messages_per_minute SMALLINT UNSIGNED NOT NULL,
    worker_concurrency SMALLINT UNSIGNED NOT NULL,
    retry_limit SMALLINT UNSIGNED NOT NULL,
    maximum_campaign_size INT UNSIGNED NOT NULL,
    maximum_csv_bytes INT UNSIGNED NOT NULL,
    maximum_csv_rows INT UNSIGNED NOT NULL,
    worker_mode VARCHAR(16) NOT NULL,
    worker_available TINYINT(1) NOT NULL,
    scheduler_available TINYINT(1) NOT NULL,
    PRIMARY KEY(singleton_key),
    CONSTRAINT chk_delivery_singleton CHECK (singleton_key = '1')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE privacy_policy (
    singleton_key CHAR(1) NOT NULL,
    open_tracking TINYINT(1) NOT NULL,
    click_tracking TINYINT(1) NOT NULL,
    rsvp_enabled TINYINT(1) NOT NULL,
    operational_retention_days SMALLINT UNSIGNED NOT NULL,
    delivery_retention_days SMALLINT UNSIGNED NOT NULL,
    suppression_mode VARCHAR(16) NOT NULL,
    unsubscribe_footer VARCHAR(500) NOT NULL,
    PRIMARY KEY(singleton_key),
    CONSTRAINT chk_privacy_singleton CHECK (singleton_key = '1'),
    CONSTRAINT chk_tracking_defaults CHECK (open_tracking = 0 AND click_tracking = 0 AND rsvp_enabled = 0)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE setup_installation_event (
    id CHAR(36) NOT NULL,
    owner_id CHAR(36) NOT NULL,
    action VARCHAR(64) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    result VARCHAR(16) NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_setup_completion_action (action),
    CONSTRAINT fk_setup_event_owner FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE RESTRICT,
    CONSTRAINT chk_setup_event_action CHECK (action = 'installation.completed')
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE setup_installation_event');
        $this->addSql('DROP TABLE privacy_policy');
        $this->addSql('DROP TABLE delivery_policy');
        $this->addSql('DROP TABLE sender_identity');
        $this->addSql('DROP TABLE mfa_credential');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE encrypted_secret');
        $this->addSql('DROP TABLE organisation_settings');
        $this->addSql('DROP TABLE installation_state');
    }
}
