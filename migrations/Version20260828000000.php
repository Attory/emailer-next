<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 12 Doctor state, configurable operations policy, retention evidence, and external backup automation.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 12 requires MariaDB/MySQL.');

        $this->addSql("ALTER TABLE health_check_run ADD run_type VARCHAR(16) NOT NULL DEFAULT 'full' AFTER id, ADD trigger_source VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER run_type, ADD INDEX idx_health_run_type_finished (run_type, finished_at), ADD CONSTRAINT chk_health_run_type CHECK (run_type IN ('lightweight', 'full')), ADD CONSTRAINT chk_health_trigger CHECK (trigger_source IN ('login', 'manual', 'daily', 'update'))");
        $this->addSql(<<<'SQL'
CREATE TABLE health_finding_state (
    code VARCHAR(64) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    summary VARCHAR(240) NOT NULL,
    risk VARCHAR(240) NOT NULL,
    evidence VARCHAR(240) NOT NULL,
    remediation VARCHAR(240) NOT NULL,
    sending_blocked TINYINT(1) NOT NULL,
    active TINYINT(1) NOT NULL,
    first_observed_at DATETIME(6) NOT NULL,
    last_observed_at DATETIME(6) NOT NULL,
    last_run_id CHAR(36) DEFAULT NULL,
    acknowledged_at DATETIME(6) DEFAULT NULL,
    acknowledged_by CHAR(36) DEFAULT NULL,
    PRIMARY KEY(code),
    INDEX idx_health_finding_active (active, severity, last_observed_at),
    CONSTRAINT fk_health_finding_run FOREIGN KEY (last_run_id) REFERENCES health_check_run (id) ON DELETE SET NULL,
    CONSTRAINT fk_health_finding_actor FOREIGN KEY (acknowledged_by) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT chk_health_finding_severity CHECK (severity IN ('critical', 'warning')),
    CONSTRAINT chk_health_finding_flags CHECK (sending_blocked IN (0, 1) AND active IN (0, 1))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE operations_policy (
    singleton_key CHAR(1) NOT NULL,
    doctor_interval_hours SMALLINT UNSIGNED NOT NULL,
    retention_automation_enabled TINYINT(1) NOT NULL,
    retention_interval_hours SMALLINT UNSIGNED NOT NULL,
    revoked_session_retention_days SMALLINT UNSIGNED NOT NULL,
    queue_retention_days SMALLINT UNSIGNED NOT NULL,
    dead_letter_retention_days SMALLINT UNSIGNED NOT NULL,
    health_retention_days SMALLINT UNSIGNED NOT NULL,
    delivery_attempt_retention_days SMALLINT UNSIGNED NOT NULL,
    log_retention_days SMALLINT UNSIGNED NOT NULL,
    backup_automation_enabled TINYINT(1) NOT NULL,
    backup_interval_hours SMALLINT UNSIGNED NOT NULL,
    backup_retention_days SMALLINT UNSIGNED NOT NULL,
    restore_drill_interval_days SMALLINT UNSIGNED NOT NULL,
    backup_destination_type VARCHAR(16) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY(singleton_key),
    CONSTRAINT chk_operations_singleton CHECK (singleton_key = '1'),
    CONSTRAINT chk_operations_flags CHECK (retention_automation_enabled IN (0, 1) AND backup_automation_enabled IN (0, 1)),
    CONSTRAINT chk_operations_intervals CHECK (doctor_interval_hours BETWEEN 1 AND 168 AND retention_interval_hours BETWEEN 1 AND 168 AND backup_interval_hours BETWEEN 1 AND 720),
    CONSTRAINT chk_operations_retention CHECK (revoked_session_retention_days BETWEEN 7 AND 365 AND queue_retention_days BETWEEN 1 AND 90 AND dead_letter_retention_days BETWEEN 30 AND 730 AND health_retention_days BETWEEN 30 AND 730 AND delivery_attempt_retention_days BETWEEN 30 AND 730 AND log_retention_days BETWEEN 1 AND 365 AND backup_retention_days BETWEEN 2 AND 365 AND restore_drill_interval_days BETWEEN 7 AND 365),
    CONSTRAINT chk_backup_destination_type CHECK (backup_destination_type IN ('none', 'filesystem'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("INSERT INTO operations_policy VALUES ('1', 24, 0, 24, 30, 7, 90, 90, 90, 30, 0, 24, 30, 90, 'none', UTC_TIMESTAMP(6))");
        $this->addSql(<<<'SQL'
CREATE TABLE operations_run (
    id CHAR(36) NOT NULL,
    operation VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    detail_code VARCHAR(64) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_operations_run_due (operation, status, finished_at),
    CONSTRAINT chk_operations_run_operation CHECK (operation IN ('doctor', 'retention', 'backup')),
    CONSTRAINT chk_operations_run_status CHECK (status IN ('succeeded', 'failed', 'skipped'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("ALTER TABLE backup_record DROP CONSTRAINT chk_backup_status, ADD restored_at DATETIME(6) DEFAULT NULL AFTER verified_at, ADD deleted_at DATETIME(6) DEFAULT NULL AFTER restored_at, ADD CONSTRAINT chk_backup_status CHECK (status IN ('created', 'verified', 'invalid', 'deleted'))");
        $this->addSql(<<<'SQL'
CREATE TABLE backup_transfer (
    id CHAR(36) NOT NULL,
    backup_id CHAR(36) NOT NULL,
    destination_type VARCHAR(16) NOT NULL,
    object_name VARCHAR(120) NOT NULL,
    ciphertext_sha256 CHAR(64) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    detail_code VARCHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_backup_transfer (backup_id, destination_type),
    INDEX idx_backup_transfer_status (status, attempted_at),
    CONSTRAINT fk_backup_transfer_record FOREIGN KEY (backup_id) REFERENCES backup_record (id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_transfer_destination CHECK (destination_type IN ('filesystem')),
    CONSTRAINT chk_backup_transfer_status CHECK (status IN ('succeeded', 'failed', 'deleted'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE housekeeping_run ADD deleted_delivery_attempt_rows INT UNSIGNED NOT NULL DEFAULT 0 AFTER deleted_dead_letter_rows, ADD deleted_backup_files INT UNSIGNED NOT NULL DEFAULT 0 AFTER deleted_rsvp_rows');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 12 operational policy and automation evidence are forward-only. Restore a verified pre-migration backup instead.');
    }
}
