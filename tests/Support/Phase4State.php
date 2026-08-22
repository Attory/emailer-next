<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\IdentityUtil;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Setup\Application\Contract\InstallationLock;
use Doctrine\DBAL\Connection;

trait Phase4State
{
    private function preparePhase4State(Connection $connection): void
    {
        $lock = self::getContainer()->get(InstallationLock::class);
        if (!$lock instanceof InstallationLock) {
            throw new \RuntimeException('Installation lock service is unavailable.');
        }
        if ($lock->exists()) {
            $lock->remove();
        }

        $connection->executeStatement('UPDATE email_template SET published_version_id = NULL');
        $connection->executeStatement("UPDATE sender_identity SET smtp_host = 'mailpit', smtp_port = 1025, encryption_mode = 'none', smtp_username = NULL, credential_secret_id = NULL, tls_peer_verification = 0, one_click_dkim_confirmed = 0 WHERE singleton_key = '1'");
        foreach (['recipient_token', 'recipient_token_key', 'messenger_messages', 'delivery_attempt', 'campaign_recipient', 'campaign_test_delivery', 'campaign_audience_recipient', 'campaign_audience_snapshot', 'campaign_content_snapshot', 'campaign_list_selection', 'campaign', 'delivery_domain_rate', 'worker_heartbeat', 'template_asset', 'template_version', 'email_template', 'contact_import_candidate', 'contact_import', 'list_membership', 'contact_attribute', 'suppression', 'contact_list', 'contact_field_definition', 'contact', 'setup_installation_event', 'password_reset_token', 'login_throttle', 'user_session', 'mfa_credential', 'app_user', 'encrypted_secret'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement("UPDATE recipient_token_key_state SET current_version = 0, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
        $connection->executeStatement("UPDATE delivery_runtime SET schema_version = 1, accepting_work = 1, active_claims = 0, rate_window_started_at = UTC_TIMESTAMP(6), rate_window_count = 0, circuit_state = 'closed', circuit_failures = 0, circuit_open_until = NULL, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
        $installationId = IdentityUtil::uuidV7();
        $now = IdentityUtil::now();
        $connection->executeStatement(<<<'SQL'
UPDATE installation_state SET
    installation_uuid = ?,
    database_ready_at = ?,
    key_ready_at = ?,
    organisation_ready_at = ?,
    owner_ready_at = ?,
    mail_ready_at = ?,
    policy_ready_at = ?,
    capability_ready_at = ?,
    completed_at = ?
WHERE singleton_key = '1'
SQL, [$installationId, $now, $now, $now, $now, $now, $now, $now, $now]);
        $connection->executeStatement(<<<'SQL'
INSERT INTO organisation_settings (
    singleton_key, installation_uuid, display_name, canonical_base_url, locale, time_zone, created_at, updated_at
)
VALUES ('1', ?, 'Emailer Next Test', 'https://localhost', 'en_AU', 'Australia/Sydney', ?, ?)
ON DUPLICATE KEY UPDATE
    installation_uuid = VALUES(installation_uuid), display_name = VALUES(display_name),
    canonical_base_url = VALUES(canonical_base_url), locale = VALUES(locale), time_zone = VALUES(time_zone),
    updated_at = VALUES(updated_at)
SQL, [$installationId, $now, $now]);
        $connection->executeStatement(<<<'SQL'
INSERT INTO sender_identity (
    singleton_key, transport_type, smtp_host, smtp_port, encryption_mode, smtp_username,
    credential_secret_id, sender_address, sender_name, reply_to_address, tls_peer_verification,
    test_message_at, created_at, updated_at
)
VALUES ('1', 'smtp', 'mailpit', 1025, 'none', NULL, NULL, 'phase4-sender@example.test', 'Emailer Next Test', NULL, 0, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    transport_type = 'smtp', smtp_host = 'mailpit', smtp_port = 1025, encryption_mode = 'none',
    smtp_username = NULL, credential_secret_id = NULL, sender_address = VALUES(sender_address),
    sender_name = VALUES(sender_name), tls_peer_verification = 0, one_click_dkim_confirmed = 0, test_message_at = VALUES(test_message_at),
    updated_at = VALUES(updated_at)
SQL, [$now, $now, $now]);
        $connection->executeStatement(<<<'SQL'
INSERT INTO delivery_policy (singleton_key, messages_per_minute, worker_concurrency, retry_limit, maximum_campaign_size, maximum_csv_bytes, maximum_csv_rows, worker_mode, worker_available, scheduler_available)
VALUES ('1', 120, 4, 3, 10000, 5000000, 50000, 'supervised', 1, 1)
ON DUPLICATE KEY UPDATE messages_per_minute = 120, worker_concurrency = 4, retry_limit = 3, maximum_campaign_size = 10000, worker_mode = 'supervised', worker_available = 1, scheduler_available = 1
SQL);
        $masterKey = self::getContainer()->get(MasterKey::class);
        if (!$masterKey instanceof MasterKey) {
            throw new \RuntimeException('Master-key service is unavailable.');
        }
        $masterKey->create();
        $lock->create(['installation_uuid' => $installationId, 'completed_at' => $now, 'version' => 1]);
    }

    private function removePhase4Files(): void
    {
        foreach (['emailer.master_key_file', 'emailer.install_lock_file'] as $parameter) {
            $path = self::getContainer()->getParameter($parameter);
            if (\is_string($path) && (file_exists($path) || is_link($path))) {
                unlink($path);
            }
        }

        $importDirectory = self::getContainer()->getParameter('emailer.import_dir');
        if (!\is_string($importDirectory) || !is_dir($importDirectory)) {
            return;
        }

        $entries = scandir($importDirectory);
        if (false === $entries) {
            throw new \RuntimeException('Unable to inspect the test import directory.');
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $importDirectory.'/'.$entry;
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                throw new \RuntimeException('Unable to remove a generated test import file.');
            }
        }
    }

    private function createPhase4User(
        Connection $connection,
        string $login,
        string $password,
        AdministrativeRole $role = AdministrativeRole::Owner,
        bool $active = true,
    ): AuthenticatedUser {
        $id = IdentityUtil::uuidV7();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $now = IdentityUtil::now();
        $connection->insert('app_user', [
            'id' => $id,
            'login_identifier' => IdentityUtil::normalizeLogin($login),
            'password_hash' => $hash,
            'role' => $role->value,
            'active' => $active ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
            'password_changed_at' => $now,
            'mfa_changed_at' => null,
            'last_login_at' => null,
            'disabled_at' => $active ? null : $now,
        ]);

        return new AuthenticatedUser($id, IdentityUtil::normalizeLogin($login), $hash, $role, $active);
    }

    /** @return array<string, string> */
    private function phase4HttpsServer(string $source = '2001:db8::40'): array
    {
        return [
            'HTTPS' => 'on',
            'DOCUMENT_ROOT' => \dirname(__DIR__, 2).'/public',
            'REMOTE_ADDR' => $source,
            'HTTP_USER_AGENT' => 'EmailerNextPhase4Test/1.0',
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function phase4Service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function phase4Int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('Expected numeric database result.');
        }

        return (int) $value;
    }

    private function phase4String(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('Expected string database result.');
        }

        return $value;
    }

    /** @param list<string> $needles */
    private function assertPhase4RuntimeFilesExclude(array $needles): void
    {
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        foreach ([$projectDirectory.'/var/cache/test', $projectDirectory.'/var/log'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getSize() > 2_000_000) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!\is_string($contents)) {
                    continue;
                }
                foreach ($needles as $needle) {
                    self::assertStringNotContainsString($needle, $contents, $file->getPathname());
                }
            }
        }
    }
}
