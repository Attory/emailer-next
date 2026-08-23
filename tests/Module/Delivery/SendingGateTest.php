<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\DeliverCampaignRecipientHandler;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SendingGateTest extends TestCase
{
    public function testCriticalGateStopsAQueuedMessageBeforeDatabaseClaimOrTransport(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $transport = self::createMock(DeliveryTransport::class);
        $transport->expects(self::never())->method('send');
        $gate = new class implements SendingGate {
            public function assertReady(): void
            {
                throw new \DomainException('critical readiness');
            }

            public function assertMaintenanceReady(): void
            {
            }
        };
        $handler = new DeliverCampaignRecipientHandler($connection, self::createStub(DeliveryConfiguration::class), self::createStub(SuppressionLookup::class), $transport, self::createStub(CampaignDeliveryGateway::class), self::createStub(RecipientUnsubscribeLinks::class), self::createStub(AuditSink::class), $gate);

        $this->expectException(\DomainException::class);
        $handler(new DeliverCampaignRecipient('00000000-0000-7000-8000-000000000001', 1));
    }
}
