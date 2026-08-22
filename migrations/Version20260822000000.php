<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6 campaign approval snapshots, durable delivery ledger, Messenger queues, rate/circuit state, and worker coordination.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 6 requires MariaDB/MySQL.');

        // Phase 5 was published once before its final cleanup timestamp/index was present.
        // Repair already-versioned installations while remaining harmless on fresh installs.
        $this->addSql('ALTER TABLE contact_import ADD COLUMN IF NOT EXISTS files_deleted_at DATETIME(6) DEFAULT NULL AFTER expires_at');
        $this->addSql('ALTER TABLE contact_import ADD INDEX IF NOT EXISTS idx_contact_import_expiry (files_deleted_at, expires_at)');
        $this->addSql('ALTER TABLE sender_identity DROP CONSTRAINT chk_sender_phase3_mailpit');
        $this->addSql(<<<'SQL'
ALTER TABLE sender_identity ADD CONSTRAINT chk_sender_phase6_transport CHECK (
    transport_type = 'smtp' AND (
        (smtp_host IN ('mailpit', '127.0.0.1', '::1') AND smtp_port = 1025 AND encryption_mode = 'none' AND smtp_username IS NULL AND credential_secret_id IS NULL AND tls_peer_verification = 0)
        OR
        (encryption_mode IN ('starttls', 'tls') AND smtp_username IS NOT NULL AND credential_secret_id IS NOT NULL AND tls_peer_verification = 1)
    )
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign (
    id CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    template_version_id CHAR(36) NOT NULL,
    state VARCHAR(24) NOT NULL,
    scheduled_at DATETIME(6) DEFAULT NULL,
    approved_at DATETIME(6) DEFAULT NULL,
    approved_by CHAR(36) DEFAULT NULL,
    approval_waived_test TINYINT(1) NOT NULL DEFAULT 0,
    operation_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(36) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_campaign_state_schedule (state, scheduled_at),
    CONSTRAINT fk_campaign_template FOREIGN KEY (template_version_id) REFERENCES template_version (id) ON DELETE RESTRICT,
    CONSTRAINT fk_campaign_approver FOREIGN KEY (approved_by) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT chk_campaign_state CHECK (state IN ('draft', 'review', 'approved', 'scheduled', 'queueing', 'sending', 'paused', 'completed', 'cancelled', 'failed')),
    CONSTRAINT chk_campaign_waiver CHECK (approval_waived_test IN (0, 1))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_list_selection (
    campaign_id CHAR(36) NOT NULL,
    list_id CHAR(36) NOT NULL,
    PRIMARY KEY(campaign_id, list_id),
    CONSTRAINT fk_campaign_selection_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_selection_list FOREIGN KEY (list_id) REFERENCES contact_list (id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_content_snapshot (
    campaign_id CHAR(36) NOT NULL,
    template_version_id CHAR(36) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    preheader VARCHAR(200) DEFAULT NULL,
    html_body MEDIUMTEXT NOT NULL,
    text_body MEDIUMTEXT NOT NULL,
    merge_fields_json JSON NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    sender_address VARCHAR(254) NOT NULL,
    sender_name VARCHAR(160) NOT NULL,
    reply_to_address VARCHAR(254) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(campaign_id),
    CONSTRAINT fk_campaign_content_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_content_version FOREIGN KEY (template_version_id) REFERENCES template_version (id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_audience_snapshot (
    campaign_id CHAR(36) NOT NULL,
    recipient_count INT UNSIGNED NOT NULL,
    audience_sha256 CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(campaign_id),
    CONSTRAINT fk_campaign_audience_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_audience_recipient (
    campaign_id CHAR(36) NOT NULL,
    contact_id CHAR(36) NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    profile_json JSON NOT NULL,
    PRIMARY KEY(campaign_id, contact_id),
    UNIQUE INDEX uniq_campaign_audience_email (campaign_id, email_canonical),
    CONSTRAINT fk_campaign_audience_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_audience_recipient_contact FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_test_delivery (
    id CHAR(36) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    requested_by CHAR(36) DEFAULT NULL,
    recipient_address VARCHAR(254) NOT NULL,
    state VARCHAR(16) NOT NULL,
    failure_class VARCHAR(16) DEFAULT NULL,
    requested_at DATETIME(6) NOT NULL,
    accepted_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_campaign_test_campaign (campaign_id, requested_at),
    CONSTRAINT fk_campaign_test_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_test_actor FOREIGN KEY (requested_by) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT chk_campaign_test_state CHECK (state IN ('pending', 'processing', 'accepted', 'failed'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE campaign_recipient (
    id CHAR(36) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    contact_id CHAR(36) NOT NULL,
    state VARCHAR(24) NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) DEFAULT NULL,
    claimed_at DATETIME(6) DEFAULT NULL,
    accepted_at DATETIME(6) DEFAULT NULL,
    terminal_at DATETIME(6) DEFAULT NULL,
    message_id VARCHAR(190) NOT NULL,
    operation_version INT UNSIGNED NOT NULL,
    last_failure_class VARCHAR(16) DEFAULT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_campaign_contact_delivery (campaign_id, contact_id),
    UNIQUE INDEX uniq_delivery_message_id (message_id),
    INDEX idx_delivery_due (state, next_attempt_at),
    INDEX idx_delivery_campaign_state (campaign_id, state),
    CONSTRAINT fk_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES campaign (id) ON DELETE RESTRICT,
    CONSTRAINT fk_delivery_contact FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE RESTRICT,
    CONSTRAINT chk_delivery_state CHECK (state IN ('pending', 'processing', 'retry', 'accepted', 'suppressed', 'permanent_failure', 'ambiguous', 'cancelled'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE delivery_attempt (
    id CHAR(36) NOT NULL,
    campaign_recipient_id CHAR(36) NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL,
    failure_class VARCHAR(16) DEFAULT NULL,
    provider_reference_hash CHAR(64) DEFAULT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_delivery_attempt_number (campaign_recipient_id, attempt_number),
    INDEX idx_delivery_attempt_open (state, started_at),
    CONSTRAINT fk_attempt_recipient FOREIGN KEY (campaign_recipient_id) REFERENCES campaign_recipient (id) ON DELETE RESTRICT,
    CONSTRAINT chk_attempt_state CHECK (state IN ('started', 'accepted', 'transient', 'permanent', 'ambiguous')),
    CONSTRAINT chk_attempt_failure CHECK (failure_class IS NULL OR failure_class IN ('transient', 'permanent', 'ambiguous'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE delivery_runtime (
    singleton_key CHAR(1) NOT NULL,
    schema_version INT UNSIGNED NOT NULL,
    accepting_work TINYINT(1) NOT NULL,
    active_claims SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rate_window_started_at DATETIME(6) NOT NULL,
    rate_window_count INT UNSIGNED NOT NULL DEFAULT 0,
    circuit_state VARCHAR(16) NOT NULL,
    circuit_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    circuit_open_until DATETIME(6) DEFAULT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(singleton_key),
    CONSTRAINT chk_delivery_runtime_singleton CHECK (singleton_key = '1'),
    CONSTRAINT chk_delivery_accepting CHECK (accepting_work IN (0, 1)),
    CONSTRAINT chk_delivery_circuit CHECK (circuit_state IN ('closed', 'open', 'half_open'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("INSERT INTO delivery_runtime VALUES ('1', 1, 1, 0, UTC_TIMESTAMP(6), 0, 'closed', 0, NULL, UTC_TIMESTAMP(6))");
        $this->addSql(<<<'SQL'
CREATE TABLE delivery_domain_rate (
    domain_hash CHAR(64) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    message_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(domain_hash)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE worker_heartbeat (
    worker_id VARCHAR(100) NOT NULL,
    worker_kind VARCHAR(16) NOT NULL,
    schema_version INT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    stopped_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(worker_id),
    INDEX idx_worker_heartbeat (worker_kind, heartbeat_at),
    CONSTRAINT chk_worker_kind CHECK (worker_kind IN ('worker', 'scheduler'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE messenger_messages (
    id BIGINT AUTO_INCREMENT NOT NULL,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_messenger_queue (queue_name, available_at, delivered_at, id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 6 campaign and delivery history is forward-only. Restore a verified pre-migration backup instead.');
    }
}
