<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Delivery\Application\Contract\SendingNotReady;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\Delivery\Infrastructure\WorkerHeartbeatSubscriber;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerHeartbeatIdentityTest extends KernelTestCase
{
    use Phase4State;

    public function testPidReuseCannotPreserveThePreviousProcessKind(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $hostname = gethostname();
        $id = substr((false === $hostname ? 'host' : $hostname).'-'.getmypid(), 0, 100);
        $now = Identity::now();
        try {
            $connection->insert('worker_heartbeat', ['worker_id' => $id, 'worker_kind' => 'scheduler', 'schema_version' => 1, 'started_at' => $now, 'heartbeat_at' => $now, 'stopped_at' => null]);
            $worker = new WorkerHeartbeatSubscriber($connection);
            $worker->started();
            self::assertSame('worker', $connection->fetchOne('SELECT worker_kind FROM worker_heartbeat WHERE worker_id=?', [$id]));
            $worker->stopped();
            self::assertNotNull($connection->fetchOne('SELECT stopped_at FROM worker_heartbeat WHERE worker_id=?', [$id]));
            $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            try {
                $this->phase4Service(DeliveryScheduler::class)->run($id);
                self::fail('A quiesced fixture must retain its sending gate.');
            } catch (SendingNotReady) {
                self::assertSame('scheduler', $connection->fetchOne('SELECT worker_kind FROM worker_heartbeat WHERE worker_id=?', [$id]));
                self::assertNull($connection->fetchOne('SELECT stopped_at FROM worker_heartbeat WHERE worker_id=?', [$id]));
            }
        } finally {
            $this->removePhase4Files();
        }
    }
}
