<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Release\Application\LightSourceInspection;
use App\Module\Secrets\Application\Contract\LightSecretInspection;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EditionIdleHeartbeatTest extends KernelTestCase
{
    use Phase4State;

    public function testSchedulerFreshnessIsNotAnActiveConsumerButLiveWorkersStillBlock(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0, active_claims=0 WHERE singleton_key='1'");
        $inspection = new LightSourceInspection($connection, '/app', '/unused', '/unused', self::createStub(LightSecretInspection::class));
        $now = Identity::now();
        try {
            $connection->insert('worker_heartbeat', ['worker_id' => 'scheduler-idle-fixture', 'worker_kind' => 'scheduler', 'schema_version' => 1, 'started_at' => $now, 'heartbeat_at' => $now, 'stopped_at' => null]);
            $inspection->assertIdle();
            self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM worker_heartbeat WHERE worker_id='scheduler-idle-fixture' AND stopped_at IS NULL")));
            $connection->insert('worker_heartbeat', ['worker_id' => 'active-consumer-fixture', 'worker_kind' => 'worker', 'schema_version' => 1, 'started_at' => $now, 'heartbeat_at' => $now, 'stopped_at' => null]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Active delivery claim, lease, worker or conflicting operation');
            $inspection->assertIdle();
        } finally {
            $this->removePhase4Files();
        }
    }
}
