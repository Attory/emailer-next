<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 4 identity roles, hashed server sessions, login throttles, password resets, and append-only audit events.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 4 requires MariaDB/MySQL.');

        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_user_role');
        $this->addSql(<<<'SQL'
ALTER TABLE app_user
    ADD updated_at DATETIME(6) DEFAULT NULL,
    ADD password_changed_at DATETIME(6) DEFAULT NULL,
    ADD mfa_changed_at DATETIME(6) DEFAULT NULL,
    ADD last_login_at DATETIME(6) DEFAULT NULL,
    ADD disabled_at DATETIME(6) DEFAULT NULL
SQL);
        $this->addSql('UPDATE app_user SET updated_at = created_at, password_changed_at = created_at');
        $this->addSql('ALTER TABLE app_user MODIFY updated_at DATETIME(6) NOT NULL, MODIFY password_changed_at DATETIME(6) NOT NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE app_user ADD CONSTRAINT chk_user_role CHECK (
    role IN ('ROLE_OWNER', 'ROLE_ADMINISTRATOR', 'ROLE_OPERATOR', 'ROLE_AUDITOR')
)
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE user_session (
    id_hash CHAR(64) NOT NULL,
    user_id CHAR(36) DEFAULT NULL,
    payload MEDIUMBLOB NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    idle_expires_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) DEFAULT NULL,
    source_hash CHAR(64) DEFAULT NULL,
    user_agent_hash CHAR(64) DEFAULT NULL,
    PRIMARY KEY(id_hash),
    INDEX idx_user_session_user_active (user_id, revoked_at, last_seen_at),
    INDEX idx_user_session_expiry (idle_expires_at, absolute_expires_at),
    CONSTRAINT fk_user_session_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE login_throttle (
    scope_hash CHAR(64) NOT NULL,
    scope_type VARCHAR(16) NOT NULL,
    failure_count SMALLINT UNSIGNED NOT NULL,
    first_failed_at DATETIME(6) NOT NULL,
    last_failed_at DATETIME(6) NOT NULL,
    not_before DATETIME(6) NOT NULL,
    locked_until DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(scope_hash),
    INDEX idx_login_throttle_expiry (locked_until, last_failed_at),
    CONSTRAINT chk_login_throttle_type CHECK (scope_type IN ('account', 'source'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE password_reset_token (
    id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX uniq_password_reset_hash (token_hash),
    INDEX idx_password_reset_user (user_id, consumed_at, expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE audit_event (
    id CHAR(36) NOT NULL,
    actor_user_id CHAR(36) DEFAULT NULL,
    action VARCHAR(96) NOT NULL,
    target_id CHAR(64) DEFAULT NULL,
    occurred_at DATETIME(6) NOT NULL,
    source_hash CHAR(64) DEFAULT NULL,
    result VARCHAR(16) NOT NULL,
    summary_json JSON NOT NULL,
    PRIMARY KEY(id),
    INDEX idx_audit_occurred (occurred_at, id),
    INDEX idx_audit_actor (actor_user_id, occurred_at),
    INDEX idx_audit_action (action, occurred_at),
    CONSTRAINT chk_audit_result CHECK (result IN ('success', 'failure', 'denied'))
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` ENGINE = InnoDB
SQL);
        $this->addSql("CREATE TRIGGER audit_event_no_update BEFORE UPDATE ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
        $this->addSql("CREATE TRIGGER audit_event_no_delete BEFORE DELETE ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 4 identity and audit storage is forward-only. Restore a verified pre-migration backup instead.');
    }
}
