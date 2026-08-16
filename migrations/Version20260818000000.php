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
        $origin = $this->connection->fetchOne("SELECT canonical_base_url FROM organisation_settings WHERE singleton_key = '1'");
        $this->abortIf(false !== $origin && (!\is_string($origin) || !$this->validCanonicalOrigin($origin)), 'The stored canonical base URL must be repaired to a credential-free HTTPS origin before Phase 4 migration.');

        $this->addSql('ALTER TABLE user_session DROP FOREIGN KEY fk_user_session_user');
        $this->addSql('ALTER TABLE user_session ADD CONSTRAINT fk_user_session_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mfa_credential ADD last_accepted_counter BIGINT DEFAULT NULL AFTER consumed_at');
        $this->addSql('ALTER TABLE login_throttle ADD pending_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER failure_count, ADD pending_updated_at DATETIME(6) DEFAULT NULL AFTER pending_count');
        $this->addSql('ALTER TABLE user_session ADD INDEX idx_session_reclaim (revoked_at, absolute_expires_at)');
        $this->addSql('ALTER TABLE login_throttle ADD INDEX idx_throttle_reclaim (pending_count, last_failed_at, locked_until)');
        $this->addSql('ALTER TABLE login_throttle DROP CONSTRAINT chk_login_throttle_type');
        $this->addSql("ALTER TABLE login_throttle ADD CONSTRAINT chk_login_throttle_type CHECK (scope_type IN ('account', 'source', 'reauth_account', 'reauth_context'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The Phase 4 security repair is forward-only. Restore a verified pre-migration backup instead.');
    }

    private function validCanonicalOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return false !== $parts
            && 'https' === ($parts['scheme'] ?? null)
            && \is_string($parts['host'] ?? null)
            && '' !== $parts['host']
            && 1 !== preg_match('/[\x00-\x20\x7F]/', $origin)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && (!isset($parts['path']) || \in_array($parts['path'], ['', '/'], true));
    }
}
