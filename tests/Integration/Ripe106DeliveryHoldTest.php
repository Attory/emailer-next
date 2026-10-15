<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Delivery\Application\DeliveryResumer;
use App\Module\Delivery\Infrastructure\DeliveryReadinessProbe;
use App\Shared\EditionDeliveryHold;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Ripe106DeliveryHoldTest extends KernelTestCase
{
    use Phase4State;

    public function testTransportHoldIsTransientBeforeConfigurationSecretsOrSmtpAreUsed(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $private = self::getContainer()->getParameter('emailer.private_dir');
        self::assertIsString($private);
        $file = $private.'/edition-delivery-hold.json';
        file_put_contents($file, '{"phase":"quiescing"}');
        $configuration = $this->createMock(\App\Module\Configuration\Application\Contract\DeliveryConfiguration::class);
        $configuration->expects(self::never())->method('current');
        $vault = $this->createMock(\App\Module\Secrets\Application\Contract\SecretVault::class);
        $vault->expects(self::never())->method('reveal');
        $transport = new \App\Module\Delivery\Infrastructure\SymfonySmtpDeliveryTransport($configuration, $vault, new \App\Module\Delivery\Infrastructure\OutboundMimeMessageFactory(), 'test', new EditionDeliveryHold($private));
        try {
            try {
                $transport->send(new \App\Module\Delivery\Application\Contract\OutboundMessage('recipient@example.test', 'sender@example.test', 'Synthetic', null, 'Synthetic', '<p>synthetic</p>', 'synthetic', 'synthetic@example.test'));
                self::fail('A held transport reached SMTP.');
            } catch (\App\Module\Delivery\Application\Contract\DeliveryTransportFailure $failure) {
                self::assertSame('transient', $failure->classification);
                self::assertFalse($failure->affectsCircuit);
            }
        } finally {
            unlink($file);
            $this->removePhase4Files();
        }
    }

    public function testAcceptedHoldStillBlocksClaimsAndFailedRemovalCannotEnableDelivery(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $private = self::getContainer()->getParameter('emailer.private_dir');
        self::assertIsString($private);
        $file = $private.'/edition-delivery-hold.json';
        file_put_contents($file, '{"phase":"accepted"}');
        chmod($file, 0o600);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
        try {
            $probe = new DeliveryReadinessProbe($connection, 'test', new EditionDeliveryHold($private));
            self::assertContains('edition_delivery_hold', array_column($probe->findings(), 'code'));
            // A directory at the marker path models a failed unlink without relying on root's permissions.
            unlink($file);
            mkdir($file, 0o700);
            file_put_contents($file.'/obstruction', 'synthetic');
            try {
                $this->phase4Service(DeliveryResumer::class)->resume();
                self::fail('An invalid persistent hold allowed delivery resume.');
            } catch (\DomainException) {
                self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
            }
        } finally {
            if (is_dir($file)) {
                unlink($file.'/obstruction');
                rmdir($file);
            } elseif (is_file($file)) {
                unlink($file);
            }
            $this->removePhase4Files();
        }
    }

    public function testFailedUnlinkRollsBackResumeBeforeAnyClaimCanStart(): void
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('Permission failure rehearsal requires the application identity, not root.');
        }
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $private = self::getContainer()->getParameter('emailer.private_dir');
        self::assertIsString($private);
        $file = $private.'/edition-delivery-hold.json';
        file_put_contents($file, '{"phase":"accepted"}');
        chmod($file, 0o600);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
        $gate = $this->createMock(\App\Module\Delivery\Application\Contract\SendingGate::class);
        $gate->expects(self::once())->method('assertMaintenanceReady');
        $resumer = new DeliveryResumer($connection, $gate, $this->phase4Service(\App\Shared\MaintenanceOperationLock::class), new EditionDeliveryHold($private));
        chmod($private, 0o500);
        try {
            try {
                $resumer->resume();
                self::fail('A failed hold removal enabled delivery.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Accepted delivery hold could not be released.', $exception->getMessage());
                self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
                self::assertFileExists($file);
            }
        } finally {
            chmod($private, 0o700);
            unlink($file);
            $this->removePhase4Files();
        }
    }
}
