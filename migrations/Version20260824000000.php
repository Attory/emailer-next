<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8 Doctor evidence, encrypted backup metadata, release operations, and housekeeping evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 8 requires MariaDB/MySQL.');

        $this->addSql(<<<'SQL'
CREATE TABLE health_check_run (
    id CHAR(36) NOT NULL,
    status VARCHAR(16) NOT NULL,
    critical_count SMALLINT UNSIGNED NOT NULL,
    warning_count SMALLINT UNSIGNED NOT NULL,
    findings_json JSON NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_health_run_finished (finished_at),
    CONSTRAINT chk_health_status CHECK (status IN ('healthy', 'warning', 'critical'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_record (
    id CHAR(36) NOT NULL,
    format_version SMALLINT UNSIGNED NOT NULL,
    filename VARCHAR(120) NOT NULL,
    ciphertext_sha256 CHAR(64) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    schema_version VARCHAR(32) NOT NULL,
    source_version VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    verified_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_backup_filename (filename),
    INDEX idx_backup_verified (verified_at),
    CONSTRAINT chk_backup_status CHECK (status IN ('created', 'verified', 'invalid'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE release_operation (
    id CHAR(36) NOT NULL,
    operation VARCHAR(16) NOT NULL,
    source_version VARCHAR(32) DEFAULT NULL,
    target_version VARCHAR(32) DEFAULT NULL,
    package_sha256 CHAR(64) DEFAULT NULL,
    status VARCHAR(16) NOT NULL,
    detail_code VARCHAR(64) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_release_operation_started (started_at),
    CONSTRAINT chk_release_operation CHECK (operation IN ('inspect', 'install', 'update', 'rollback')),
    CONSTRAINT chk_release_status CHECK (status IN ('started', 'succeeded', 'failed', 'rolled_back'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE housekeeping_run (
    id CHAR(36) NOT NULL,
    dry_run TINYINT(1) NOT NULL,
    deleted_import_files INT UNSIGNED NOT NULL,
    deleted_sessions INT UNSIGNED NOT NULL,
    deleted_queue_rows INT UNSIGNED NOT NULL,
    deleted_health_runs INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_housekeeping_finished (finished_at),
    CONSTRAINT chk_housekeeping_dry_run CHECK (dry_run IN (0, 1)),
    CONSTRAINT chk_housekeeping_status CHECK (status IN ('succeeded', 'failed'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 8 operational evidence is forward-only. Restore a verified pre-migration backup instead.');
    }
}
