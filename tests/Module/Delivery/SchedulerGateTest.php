<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SchedulerGateTest extends TestCase
{
    public function testQuiescedRuntimeBlocksRecoveryWritesDuringMaintenance(): void
    {
        $directory = sys_get_temp_dir().'/emailer-scheduler-quiesced-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        try {
            $state = (object) ['recovered' => false, 'purged' => false];
            $connection = self::createStub(Connection::class);
            $connection->method('fetchOne')->willReturn(0);
            $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use ($state): int {
                if (str_contains($sql, "campaign_test_delivery SET state = 'failed'")) {
                    $state->recovered = true;
                }

                return 1;
            });
            $links = self::createStub(RecipientUnsubscribeLinks::class);
            $links->method('purgeExpired')->willReturnCallback(static function () use ($state): int {
                $state->purged = true;

                return 0;
            });
            $gate = new class($state) implements SendingGate {
                public function __construct(private \stdClass $state)
                {
                }

                public function assertReady(): void
                {
                    TestCase::assertFalse($this->state->recovered);
                    TestCase::assertFalse($this->state->purged);
                    throw new \DomainException('delivery quiesced');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $scheduler = new DeliveryScheduler($connection, self::createStub(CampaignDeliveryGateway::class), self::createStub(AuditSink::class), new LockFactory(new FlockStore($directory)), $links, $gate);

            $this->expectException(\DomainException::class);
            $scheduler->run('scheduler-test');
        } finally {
            @rmdir($directory);
        }
    }

    public function testCriticalSendingGateStillAllowsInterruptedWorkRecoveryBeforeBlockingNewWork(): void
    {
        $directory = sys_get_temp_dir().'/emailer-scheduler-gate-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        try {
            $state = (object) ['recovered' => false, 'purged' => false];
            $connection = self::createStub(Connection::class);
            $connection->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
            $connection->method('fetchAllAssociative')->willReturn([]);
            $connection->method('fetchOne')->willReturn(1);
            $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use ($state): int {
                if (str_contains($sql, "campaign_test_delivery SET state = 'failed'")) {
                    $state->recovered = true;
                }

                return 1;
            });
            $links = self::createStub(RecipientUnsubscribeLinks::class);
            $links->method('purgeExpired')->willReturnCallback(static function () use ($state): int {
                $state->purged = true;

                return 0;
            });
            $gate = new class($state) implements SendingGate {
                public function __construct(private \stdClass $state)
                {
                }

                public function assertReady(): void
                {
                    TestCase::assertTrue($this->state->recovered);
                    TestCase::assertTrue($this->state->purged);
                    throw new \DomainException('critical readiness');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $scheduler = new DeliveryScheduler($connection, self::createStub(CampaignDeliveryGateway::class), self::createStub(AuditSink::class), new LockFactory(new FlockStore($directory)), $links, $gate);

            $this->expectException(\DomainException::class);
            $scheduler->run('scheduler-test');
        } finally {
            @rmdir($directory);
        }
    }
}
