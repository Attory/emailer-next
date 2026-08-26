<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Application;

use App\Module\Contacts\Application\Contract\ImportStorage;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class Housekeeper
{
    private const BATCH = 200;

    public function __construct(private Connection $connection, private ImportStorage $imports, private LockFactory $locks, private MaintenanceOperationLock $maintenance)
    {
    }

    /** @return array{imports: int, sessions: int, queue: int, dead_letter: int, health: int, analytics: int, rsvp: int} */
    public function run(bool $dryRun): array
    {
        $this->maintenance->acquire();
        try {
            return $this->runWithLock($dryRun);
        } finally {
            $this->maintenance->release();
        }
    }

    /** @return array{imports: int, sessions: int, queue: int, dead_letter: int, health: int, analytics: int, rsvp: int} */
    private function runWithLock(bool $dryRun): array
    {
        $lock = $this->locks->createLock('emailer-housekeeping', 300.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another housekeeping run is active.');
        }
        try {
            return $this->runLocked($dryRun);
        } finally {
            $lock->release();
        }
    }

    /** @return array{imports: int, sessions: int, queue: int, dead_letter: int, health: int, analytics: int, rsvp: int} */
    private function runLocked(bool $dryRun): array
    {
        $started = Identity::now();
        $expiredImports = $this->connection->fetchAllAssociative('SELECT id, file_token, report_token FROM contact_import WHERE files_deleted_at IS NULL AND expires_at < UTC_TIMESTAMP(6) ORDER BY expires_at LIMIT '.self::BATCH);
        $retention = $this->connection->fetchAssociative("SELECT tracking_retention_days, rsvp_retention_days FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $retention || !is_numeric($retention['tracking_retention_days']) || 1 > (int) $retention['tracking_retention_days'] || 365 < (int) $retention['tracking_retention_days'] || !is_numeric($retention['rsvp_retention_days']) || 1 > (int) $retention['rsvp_retention_days'] || 365 < (int) $retention['rsvp_retention_days']) {
            throw new \RuntimeException('Analytics retention policy is invalid.');
        }
        $analyticsCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $retention['tracking_retention_days'].' days')->format('Y-m-d H:i:s.u');
        $rsvpCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $retention['rsvp_retention_days'].' days')->format('Y-m-d H:i:s.u');
        $counts = [
            'imports' => \count($expiredImports),
            'sessions' => $this->count('SELECT COUNT(*) FROM (SELECT id_hash FROM user_session WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY) ORDER BY revoked_at LIMIT '.self::BATCH.') expired'),
            'queue' => $this->count("SELECT COUNT(*) FROM (SELECT id FROM messenger_messages WHERE queue_name <> 'failed' AND delivered_at IS NOT NULL AND delivered_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) ORDER BY id LIMIT ".self::BATCH.') expired'),
            'dead_letter' => $this->count("SELECT COUNT(*) FROM (SELECT id FROM messenger_messages WHERE queue_name = 'failed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY) ORDER BY id LIMIT ".self::BATCH.') expired'),
            'health' => $this->count('SELECT COUNT(*) FROM (SELECT id FROM health_check_run WHERE finished_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY finished_at LIMIT '.self::BATCH.') expired'),
            'analytics' => $this->count('SELECT COUNT(*) FROM (SELECT campaign_recipient_id, event_type, link_index FROM analytics_aggregate WHERE last_seen_at < ? ORDER BY last_seen_at LIMIT '.self::BATCH.') expired', [$analyticsCutoff]),
            'rsvp' => $this->count('SELECT COUNT(*) FROM (SELECT campaign_recipient_id FROM rsvp_response WHERE last_submitted_at < ? ORDER BY last_submitted_at LIMIT '.self::BATCH.') expired', [$rsvpCutoff]),
        ];
        if (!$dryRun) {
            $deletedImports = 0;
            foreach ($expiredImports as $row) {
                if (\is_string($row['file_token'])) {
                    $this->imports->delete($row['file_token']);
                }
                if (\is_string($row['report_token'])) {
                    $this->imports->deleteReport($row['report_token']);
                }
                $deletedImports += (int) $this->connection->update('contact_import', ['files_deleted_at' => Identity::now()], ['id' => $row['id'], 'files_deleted_at' => null]);
            }
            $counts['imports'] = $deletedImports;
            $counts['sessions'] = (int) $this->connection->executeStatement('DELETE FROM user_session WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY) ORDER BY revoked_at LIMIT '.self::BATCH);
            $counts['queue'] = (int) $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name <> 'failed' AND delivered_at IS NOT NULL AND delivered_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) ORDER BY id LIMIT ".self::BATCH);
            $counts['dead_letter'] = (int) $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY) ORDER BY id LIMIT ".self::BATCH);
            $counts['health'] = (int) $this->connection->executeStatement('DELETE FROM health_check_run WHERE finished_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY finished_at LIMIT '.self::BATCH);
            $counts['analytics'] = (int) $this->connection->executeStatement('DELETE FROM analytics_aggregate WHERE last_seen_at < ? ORDER BY last_seen_at LIMIT '.self::BATCH, [$analyticsCutoff]);
            $counts['rsvp'] = (int) $this->connection->executeStatement('DELETE FROM rsvp_response WHERE last_submitted_at < ? ORDER BY last_submitted_at LIMIT '.self::BATCH, [$rsvpCutoff]);
        }
        $this->connection->insert('housekeeping_run', ['id' => Identity::uuidV7(), 'dry_run' => $dryRun ? 1 : 0, 'deleted_import_files' => $counts['imports'], 'deleted_sessions' => $counts['sessions'], 'deleted_queue_rows' => $counts['queue'], 'deleted_dead_letter_rows' => $counts['dead_letter'], 'deleted_health_runs' => $counts['health'], 'deleted_analytics_rows' => $counts['analytics'], 'deleted_rsvp_rows' => $counts['rsvp'], 'status' => 'succeeded', 'started_at' => $started, 'finished_at' => Identity::now()]);

        return $counts;
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Housekeeping count is invalid.');
        }

        return (int) $value;
    }
}
