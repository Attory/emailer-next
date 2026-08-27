<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Module\Secrets\Application\MasterKeyRotator;
use App\Module\Secrets\Application\RecoveryKeyExporter;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Phase13MasterKeyRotationTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testRotationBacksUpReencryptsAndAuditsWithoutChangingSecretValue(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $installationId = $this->phase4String($connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'"));
        $actor = $this->createPhase4User($connection, 'rotation-owner@example.test', 'correct horse battery staple');
        $vault = $this->phase4Service(SecretVault::class);
        $secretId = $vault->store('smtp.credential', 'synthetic-rotation-value', $installationId);
        $master = $this->phase4Service(MasterKey::class);
        $before = $master->load();
        $passphrase = 'synthetic phase13 recovery passphrase';
        $this->phase4Service(RecoveryKeyExporter::class)->export($passphrase);

        try {
            $result = $this->phase4Service(MasterKeyRotator::class)->rotate($actor->id(), $passphrase);
            self::assertSame(1, $result['rotated_secret_count']);
            self::assertSame('verified', $connection->fetchOne('SELECT status FROM backup_record WHERE id = ?', [$result['backup_id']]));
            self::assertSame('synthetic-rotation-value', $vault->reveal($secretId, 'smtp.credential', $installationId));
            $after = $master->load();
            try {
                self::assertFalse(hash_equals($before, $after));
            } finally {
                sodium_memzero($after);
            }
            self::assertSame(1, $this->phase4Int($connection->fetchOne(
                "SELECT COUNT(*) FROM audit_event WHERE actor_user_id = ? AND action = 'secrets.master_key.rotated' AND result = 'success'",
                [$actor->id()],
            )));
        } finally {
            sodium_memzero($before);
        }
    }

    public function testRotationRefusesActiveDeliveryBeforeChangingKey(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'busy-rotation-owner@example.test', 'correct horse battery staple');
        $master = $this->phase4Service(MasterKey::class);
        $before = $master->load();
        $backupCount = $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM backup_record'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Delivery must be quiesced');
            $this->phase4Service(MasterKeyRotator::class)->rotate($actor->id(), 'synthetic phase13 recovery passphrase');
        } finally {
            $after = $master->load();
            try {
                self::assertTrue(hash_equals($before, $after));
                self::assertSame($backupCount, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM backup_record')));
            } finally {
                sodium_memzero($before);
                sodium_memzero($after);
            }
        }
    }

    public function testFailureAfterFilesystemReplacementRollsBackDatabaseAndKey(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $installationId = $this->phase4String($connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'"));
        $vault = $this->phase4Service(SecretVault::class);
        $secretId = $vault->store('smtp.credential', 'synthetic-rollback-value', $installationId);
        $envelope = $this->phase4String($connection->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$secretId]));
        $master = $this->phase4Service(MasterKey::class);
        $before = $master->load();
        $passphrase = 'synthetic phase13 rollback passphrase';
        $this->phase4Service(RecoveryKeyExporter::class)->export($passphrase);

        try {
            try {
                $this->phase4Service(MasterKeyRotator::class)->rotate('invalid-audit-actor', $passphrase);
                self::fail('Invalid audit evidence did not abort rotation.');
            } catch (\InvalidArgumentException) {
            }
            $after = $master->load();
            try {
                self::assertTrue(hash_equals($before, $after));
            } finally {
                sodium_memzero($after);
            }
            self::assertSame($envelope, $connection->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$secretId]));
            self::assertSame('synthetic-rollback-value', $vault->reveal($secretId, 'smtp.credential', $installationId));
        } finally {
            sodium_memzero($before);
        }
    }

    public function testWrongRecoveryPassphrasePreventsBackupAndKeyMutation(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'recovery-gate-owner@example.test', 'correct horse battery staple');
        $master = $this->phase4Service(MasterKey::class);
        $before = $master->load();
        $this->phase4Service(RecoveryKeyExporter::class)->export('synthetic correct recovery passphrase');
        $backupCount = $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM backup_record'));

        try {
            try {
                $this->phase4Service(MasterKeyRotator::class)->rotate($actor->id(), 'synthetic wrong recovery passphrase');
                self::fail('Wrong recovery passphrase was accepted for rotation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('recovery-key package', $exception->getMessage());
            }
            $after = $master->load();
            try {
                self::assertTrue(hash_equals($before, $after));
            } finally {
                sodium_memzero($after);
            }
            self::assertSame($backupCount, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM backup_record')));
        } finally {
            sodium_memzero($before);
        }
    }
}
