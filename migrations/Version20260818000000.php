<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 4 security repair: monotonic session tombstones, single-use TOTP counters, and authentication throttle scopes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 4 requires MariaDB/MySQL.');

        $this->addSql('ALTER TABLE user_session DROP FOREIGN KEY fk_user_session_user');
        $this->addSql('ALTER TABLE user_session ADD CONSTRAINT fk_user_session_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mfa_credential ADD last_accepted_counter BIGINT DEFAULT NULL AFTER consumed_at');
        $this->addSql('ALTER TABLE login_throttle DROP CONSTRAINT chk_login_throttle_type');
        $this->addSql("ALTER TABLE login_throttle ADD CONSTRAINT chk_login_throttle_type CHECK (scope_type IN ('account', 'source', 'reauth_account', 'reauth_context'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The Phase 4 security repair is forward-only. Restore a verified pre-migration backup instead.');
    }
}
