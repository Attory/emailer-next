<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class DeliveryScheduler
{
    public function __construct(private Connection $connection, private CampaignDeliveryGateway $gateway, private AuditSink $audit, private LockFactory $lockFactory, private RecipientUnsubscribeLinks $unsubscribeLinks, private SendingGate $sendingGate)
    {
    }

    /** @return array{scheduled: int, reconciled: int} */
    public function run(string $schedulerId): array
    {
        $lock = $this->lockFactory->createLock('emailer-delivery-scheduler', 55.0);
        if (!$lock->acquire()) {
            return ['scheduled' => 0, 'reconciled' => 0];
        }
        try {
            $this->heartbeat($schedulerId);
            if (!$this->acceptingWork()) {
                $this->sendingGate->assertReady();
            }
            $this->recoverInterruptedClaims();
            $this->recoverInterruptedTests();
            $this->unsubscribeLinks->purgeExpired();
            $this->sendingGate->assertReady();
            $due = $this->connection->fetchAllAssociative("SELECT id, operation_version FROM campaign WHERE state = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP(6) ORDER BY scheduled_at LIMIT 100");
            $scheduled = 0;
            foreach ($due as $row) {
                if (!\is_string($row['id'])) {
                    continue;
                }
                $storedVersion = $this->intValue($row['operation_version']);
                $version = 1 + $storedVersion;
                if (1 !== $this->connection->executeStatement("UPDATE campaign SET state = 'queueing', scheduled_at = NULL, operation_version = ?, updated_at = ? WHERE id = ? AND state = 'scheduled' AND operation_version = ?", [$version, Identity::now(), $row['id'], $storedVersion])) {
                    continue;
                }
                $this->gateway->materializeAndDispatch($row['id'], $version);
                ++$scheduled;
            }
            $campaigns = $this->connection->fetchFirstColumn("SELECT DISTINCT campaign_id FROM campaign_recipient WHERE state IN ('pending', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP(6)) LIMIT 100");
            $reconciled = 0;
            foreach ($campaigns as $campaignId) {
                if (\is_string($campaignId)) {
                    $this->gateway->dispatchDue($campaignId);
                    ++$reconciled;
                }
            }

            return ['scheduled' => $scheduled, 'reconciled' => $reconciled];
        } finally {
            $lock->release();
        }
    }

    private function recoverInterruptedClaims(): void
    {
        $this->connection->transactional(function (): void {
            $rows = $this->connection->fetchAllAssociative("SELECT r.id, r.campaign_id, a.id attempt_id FROM campaign_recipient r LEFT JOIN delivery_attempt a ON a.campaign_recipient_id = r.id AND a.state = 'started' WHERE r.state = 'processing' AND r.claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE) LIMIT 100 FOR UPDATE");
            foreach ($rows as $row) {
                if (!\is_string($row['id'])) {
                    continue;
                }
                if (\is_string($row['attempt_id'])) {
                    $this->connection->update('delivery_attempt', ['state' => 'ambiguous', 'failure_class' => 'ambiguous', 'finished_at' => Identity::now()], ['id' => $row['attempt_id'], 'state' => 'started']);
                    $this->connection->update('campaign_recipient', ['state' => 'ambiguous', 'last_failure_class' => 'ambiguous', 'terminal_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $row['id'], 'state' => 'processing']);
                    $this->audit->append(new AuditRecord(null, 'delivery.interrupted.ambiguous', $row['id'], 'failure'));
                } else {
                    $this->connection->update('campaign_recipient', ['state' => 'retry', 'next_attempt_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $row['id'], 'state' => 'processing']);
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET active_claims = GREATEST(active_claims - 1, 0), updated_at = ? WHERE singleton_key = '1'", [Identity::now()]);
                if (\is_string($row['campaign_id']) && false === $this->connection->fetchOne("SELECT 1 FROM campaign_recipient WHERE campaign_id = ? AND state IN ('pending', 'processing', 'retry')", [$row['campaign_id']])) {
                    $this->connection->executeStatement("UPDATE campaign SET state = 'completed', updated_at = ? WHERE id = ? AND state = 'sending'", [Identity::now(), $row['campaign_id']]);
                }
            }
        });
    }

    private function heartbeat(string $schedulerId): void
    {
        $now = Identity::now();
        $this->connection->executeStatement("INSERT INTO worker_heartbeat (worker_id, worker_kind, schema_version, started_at, heartbeat_at, stopped_at) VALUES (?, 'scheduler', 1, ?, ?, NULL) ON DUPLICATE KEY UPDATE schema_version = 1, heartbeat_at = VALUES(heartbeat_at), stopped_at = NULL", [$schedulerId, $now, $now]);
    }

    private function recoverInterruptedTests(): void
    {
        $this->connection->executeStatement("UPDATE campaign_test_delivery SET state = 'failed', failure_class = 'ambiguous' WHERE state = 'processing' AND requested_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)");
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Scheduler numeric state is invalid.');
        }

        return (int) $value;
    }

    private function acceptingWork(): bool
    {
        return 1 === $this->intValue($this->connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'"));
    }
}
