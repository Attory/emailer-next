<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Delivery\Application\Contract\SendingGate;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final readonly class DeliveryResumer
{
    public function __construct(private Connection $connection, private SendingGate $sendingGate, private MaintenanceOperationLock $maintenance, private ?\App\Shared\EditionDeliveryHold $editionHold = null)
    {
    }

    public function resume(): void
    {
        $this->maintenance->acquire();
        try {
            $this->editionHold?->assertResumeAllowed();
            $this->sendingGate->assertMaintenanceReady();
            $this->connection->transactional(function (): void {
                $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
                if (false === $runtime || !is_numeric($runtime['schema_version']) || !is_numeric($runtime['accepting_work']) || 1 !== (int) $runtime['schema_version'] || 0 !== (int) $runtime['accepting_work']) {
                    throw new \RuntimeException('Delivery runtime was not quiesced or compatible.');
                }
                // A crash or failed unlink leaves accepting_work = 0. Claims can only
                // become available after the durable hold has been removed successfully.
                $this->editionHold?->releaseAfterResume();
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
            });
        } finally {
            $this->maintenance->release();
        }
    }
}
