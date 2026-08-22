<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

trait ResetsPhase3State
{
    private function resetPhase3Database(Connection $connection): void
    {
        $connection->executeStatement('DELETE FROM recipient_token');
        $connection->executeStatement('DELETE FROM recipient_token_key');
        $connection->executeStatement("UPDATE recipient_token_key_state SET current_version = 0, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
        foreach (['setup_installation_event', 'mfa_credential', 'sender_identity', 'delivery_policy', 'privacy_policy', 'app_user', 'encrypted_secret', 'organisation_settings'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement(<<<'SQL'
UPDATE installation_state SET
    installation_uuid = NULL,
    database_ready_at = UTC_TIMESTAMP(6),
    key_ready_at = NULL,
    organisation_ready_at = NULL,
    owner_ready_at = NULL,
    mail_ready_at = NULL,
    policy_ready_at = NULL,
    capability_ready_at = NULL,
    completed_at = NULL
WHERE singleton_key = '1'
SQL);
    }

    private function removePhase3Files(): void
    {
        foreach (['emailer.master_key_file', 'emailer.install_lock_file'] as $parameter) {
            $path = self::getContainer()->getParameter($parameter);
            if (\is_string($path) && (file_exists($path) || is_link($path))) {
                unlink($path);
            }
        }
    }
}
