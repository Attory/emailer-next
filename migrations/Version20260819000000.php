<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5 contacts, lists, suppression, private imports, templates, immutable versions, and safe raster assets.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 5 requires MariaDB/MySQL.');

        $this->addSql(<<<'SQL'
CREATE TABLE contact (
    id CHAR(36) NOT NULL,
    email_entered VARCHAR(254) NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    display_name VARCHAR(160) DEFAULT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    locale VARCHAR(20) DEFAULT NULL,
    time_zone VARCHAR(64) DEFAULT NULL,
    status VARCHAR(16) NOT NULL,
    consent_source VARCHAR(120) NOT NULL,
    consent_at DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_contact_email (email_canonical),
    INDEX idx_contact_status (status, updated_at),
    CONSTRAINT chk_contact_status CHECK (status IN ('active', 'archived'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE contact_field_definition (
    field_key VARCHAR(48) NOT NULL,
    label VARCHAR(100) NOT NULL,
    field_type VARCHAR(16) NOT NULL,
    max_length SMALLINT UNSIGNED DEFAULT NULL,
    active TINYINT(1) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(field_key),
    CONSTRAINT chk_contact_field_type CHECK (field_type IN ('text', 'integer', 'boolean', 'date')),
    CONSTRAINT chk_contact_field_active CHECK (active IN (0, 1))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE contact_attribute (
    contact_id CHAR(36) NOT NULL,
    field_key VARCHAR(48) NOT NULL,
    value_text VARCHAR(500) NOT NULL,
    PRIMARY KEY(contact_id, field_key),
    CONSTRAINT fk_contact_attribute_contact FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_attribute_definition FOREIGN KEY (field_key) REFERENCES contact_field_definition (field_key) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE contact_list (
    id CHAR(36) NOT NULL,
    name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_contact_list_name (name),
    INDEX idx_contact_list_active (archived_at, name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE list_membership (
    contact_id CHAR(36) NOT NULL,
    list_id CHAR(36) NOT NULL,
    source VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(contact_id, list_id),
    INDEX idx_membership_list (list_id, created_at),
    CONSTRAINT fk_membership_contact FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE,
    CONSTRAINT fk_membership_list FOREIGN KEY (list_id) REFERENCES contact_list (id) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE suppression (
    id CHAR(36) NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    reason VARCHAR(32) NOT NULL,
    source VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_suppression_email (email_canonical),
    CONSTRAINT chk_suppression_reason CHECK (reason IN ('manual', 'unsubscribe', 'complaint', 'hard_bounce', 'imported'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE contact_import (
    id CHAR(36) NOT NULL,
    actor_user_id CHAR(36) DEFAULT NULL,
    list_id CHAR(36) NOT NULL,
    file_token CHAR(48) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    mode VARCHAR(16) NOT NULL,
    mapping_json JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    total_count INT UNSIGNED NOT NULL DEFAULT 0,
    valid_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    suppressed_count INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    report_token CHAR(48) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    files_deleted_at DATETIME(6) DEFAULT NULL,
    committed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_contact_import_file (file_token),
    UNIQUE INDEX uniq_contact_import_report (report_token),
    INDEX idx_contact_import_expiry (files_deleted_at, expires_at),
    CONSTRAINT fk_contact_import_actor FOREIGN KEY (actor_user_id) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_import_list FOREIGN KEY (list_id) REFERENCES contact_list (id) ON DELETE RESTRICT,
    CONSTRAINT chk_contact_import_mode CHECK (mode IN ('headered', 'headerless')),
    CONSTRAINT chk_contact_import_status CHECK (status IN ('uploaded', 'previewed', 'committed', 'failed'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE email_template (
    id CHAR(36) NOT NULL,
    name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    status VARCHAR(16) NOT NULL,
    published_version_id CHAR(36) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_email_template_name (name),
    INDEX idx_email_template_status (status, updated_at),
    CONSTRAINT chk_email_template_status CHECK (status IN ('draft', 'published', 'archived'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE contact_import_candidate (
    import_id CHAR(36) NOT NULL,
    source_row INT UNSIGNED NOT NULL,
    email_entered VARCHAR(254) NOT NULL,
    email_canonical VARCHAR(254) NOT NULL,
    profile_json JSON NOT NULL,
    PRIMARY KEY(import_id, source_row),
    UNIQUE INDEX uniq_import_candidate_email (import_id, email_canonical),
    CONSTRAINT fk_import_candidate_import FOREIGN KEY (import_id) REFERENCES contact_import (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE template_version (
    id CHAR(36) NOT NULL,
    template_id CHAR(36) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    subject VARCHAR(200) NOT NULL,
    preheader VARCHAR(200) DEFAULT NULL,
    html_body MEDIUMTEXT NOT NULL,
    text_body MEDIUMTEXT NOT NULL,
    merge_fields_json JSON NOT NULL,
    link_manifest_json JSON NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    created_by CHAR(36) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_template_version (template_id, version_number),
    INDEX idx_template_version_created (template_id, created_at),
    CONSTRAINT fk_template_version_template FOREIGN KEY (template_id) REFERENCES email_template (id) ON DELETE RESTRICT,
    CONSTRAINT fk_template_version_actor FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql('ALTER TABLE email_template ADD CONSTRAINT fk_template_published_version FOREIGN KEY (published_version_id) REFERENCES template_version (id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
CREATE TABLE template_asset (
    id CHAR(36) NOT NULL,
    filename VARCHAR(80) NOT NULL,
    media_type VARCHAR(32) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    created_by CHAR(36) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_template_asset_filename (filename),
    CONSTRAINT fk_template_asset_actor FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE SET NULL,
    CONSTRAINT chk_template_asset_media CHECK (media_type IN ('image/jpeg', 'image/png', 'image/webp'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 5 recipient, suppression, and published-content storage is forward-only. Restore a verified pre-migration backup instead.');
    }
}
