<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Application\RestoreDrillEvidence;
use App\Module\Backup\Infrastructure\OperationalBackupStatus;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class Phase13RestoreDrillTest extends KernelTestCase
{
    use Phase4State;

    public function testRestoringASafetySnapshotCannotResurrectACompletedReleaseAsStarted(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $operation = Identity::uuidV7();
        $connection->insert('release_operation', ['id' => $operation, 'operation' => 'update', 'source_version' => '1.0.5', 'target_version' => '1.0.6', 'package_sha256' => str_repeat('a', 64), 'status' => 'started', 'detail_code' => 'verified', 'started_at' => Identity::now(), 'finished_at' => null]);
        $overlaysBefore = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action='backup.release_history_preserved'"));
        $engine = $this->phase4Service(BackupEngine::class);
        $backup = $engine->create();
        $connection->update('release_operation', ['status' => 'failed', 'detail_code' => 'update_failed', 'finished_at' => Identity::now()], ['id' => $operation]);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0,active_claims=0 WHERE singleton_key='1'");
        $engine->restoreForDrill($backup['path']);
        self::assertSame('failed', $connection->fetchOne('SELECT status FROM release_operation WHERE id=?', [$operation]));
        self::assertSame('update_failed', $connection->fetchOne('SELECT detail_code FROM release_operation WHERE id=?', [$operation]));
        self::assertSame($overlaysBefore + 1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action='backup.release_history_preserved'")));
        $gate = $this->createMock(\App\Module\Delivery\Application\Contract\SendingGate::class);
        $gate->expects(self::once())->method('assertMaintenanceReady');
        (new \App\Module\Delivery\Application\DeliveryResumer($connection, $gate, $this->phase4Service(\App\Shared\MaintenanceOperationLock::class)))->resume();
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testRealRestoreIssuesEvidenceAndRecordingDoesNotRestoreLiveStateOrFreshenAReplay(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement('DELETE FROM backup_record');
        $suppression = Identity::uuidV7();
        $connection->insert('suppression', ['id' => $suppression, 'email_canonical' => 'drill@example.test', 'reason' => 'manual', 'source' => 'drill-test', 'created_at' => Identity::now()]);
        $assetRoot = self::getContainer()->getParameter('emailer.template_asset_dir');
        self::assertIsString($assetRoot);
        if (!is_dir($assetRoot)) {
            self::assertTrue(mkdir($assetRoot, 0o700, true));
        }
        file_put_contents($assetRoot.'/synthetic.png', 'synthetic asset bytes');
        $engine = $this->phase4Service(BackupEngine::class);
        $backup = $engine->create();
        $connection->delete('suppression', ['id' => $suppression]);
        unlink($assetRoot.'/synthetic.png');
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $receiptPath = $engine->restoreForDrill($backup['path']);
        self::assertSame(0o600, fileperms($receiptPath) & 0o777);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression WHERE id = ?', [$suppression])));
        self::assertSame('synthetic asset bytes', file_get_contents($assetRoot.'/synthetic.png'));
        $receipt = (string) file_get_contents($receiptPath);
        $payload = $this->phase4Service(RestoreDrillEvidence::class)->authenticate($receipt);
        self::assertSame(51, $payload['checked_tables']);
        self::assertSame(1, $payload['checked_assets']);

        // Model the originating database's state, which was never restored.
        $verificationTime = '2020-01-01 00:00:00.000000';
        $connection->update('backup_record', ['restored_at' => null, 'verified_at' => $verificationTime], ['id' => $backup['id']]);
        $connection->executeStatement("UPDATE organisation_settings SET display_name = 'Live changes after backup' WHERE singleton_key = '1'");
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1 WHERE singleton_key = '1'");
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('emailer:backup:record-restore-drill'));
        self::assertSame(Command::FAILURE, $tester->execute(['receipt' => $receiptPath], ['interactive' => false]));
        self::assertNull($connection->fetchOne('SELECT restored_at FROM backup_record WHERE id = ?', [$backup['id']]));
        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute(['receipt' => $receiptPath]));
        self::assertSame($payload['restored_at'], $connection->fetchOne('SELECT restored_at FROM backup_record WHERE id = ?', [$backup['id']]));
        self::assertSame('Live changes after backup', $connection->fetchOne("SELECT display_name FROM organisation_settings WHERE singleton_key = '1'"));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
        $engine->recordRestoreDrill($receiptPath);
        self::assertSame($verificationTime, $connection->fetchOne('SELECT verified_at FROM backup_record WHERE id = ?', [$backup['id']]));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'backup.restore_drill_recorded' AND target_id = ?", [$backup['id']])));

        $newer = $engine->create();
        $status = $this->phase4Service(OperationalBackupStatus::class)->status();
        self::assertSame($payload['restored_at'], $status['metrics'][1]['value']);
        self::assertNotSame($backup['id'], $newer['id']);
    }

    public function testUnsafeTamperedFutureMismatchedAndDamagedBackupEvidenceCannotBeRecorded(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement('DELETE FROM backup_record');
        $engine = $this->phase4Service(BackupEngine::class);
        $backup = $engine->create();
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $receiptPath = $engine->restoreForDrill($backup['path']);
        $payload = $this->phase4Service(RestoreDrillEvidence::class)->authenticate((string) file_get_contents($receiptPath));
        $connection->update('backup_record', ['restored_at' => null], ['id' => $backup['id']]);
        $invalidPath = \dirname($receiptPath).'/invalid-receipt.json';
        $evidence = $this->phase4Service(RestoreDrillEvidence::class);
        $variants = [
            str_replace('emailer-next-restore-drill-v1', 'emailer-next-restore-drill-v2', (string) file_get_contents($receiptPath)),
            $evidence->issue([...$payload, 'restored_at' => '2999-01-01 00:00:00.000000']),
            $evidence->issue([...$payload, 'ciphertext_sha256' => hash('sha256', 'wrong backup')]),
            $evidence->issue([...$payload, 'checked_tables' => 1]),
        ];
        foreach ($variants as $variant) {
            file_put_contents($invalidPath, $variant);
            chmod($invalidPath, 0o600);
            $this->assertRecordingRejected($engine, $invalidPath);
        }
        unlink($invalidPath);
        self::assertTrue(symlink($receiptPath, $invalidPath));
        $this->assertRecordingRejected($engine, $invalidPath);
        unlink($invalidPath);
        chmod($receiptPath, 0o644);
        $this->assertRecordingRejected($engine, $receiptPath);
        chmod($receiptPath, 0o600);
        $connection->update('backup_record', ['deleted_at' => Identity::now()], ['id' => $backup['id']]);
        $this->assertRecordingRejected($engine, $receiptPath);
        $connection->update('backup_record', ['deleted_at' => null], ['id' => $backup['id']]);
        rename($backup['path'], $backup['path'].'.absent');
        $this->assertRecordingRejected($engine, $receiptPath);
        rename($backup['path'].'.absent', $backup['path']);
        file_put_contents($backup['path'], 'damage', FILE_APPEND);
        $this->assertRecordingRejected($engine, $receiptPath);
        self::assertNull($connection->fetchOne('SELECT restored_at FROM backup_record WHERE id = ?', [$backup['id']]));
    }

    public function testDrillRefusesActiveDeliveryAndPublishesNoReceiptAfterValidationFailure(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement('DELETE FROM backup_record');
        $connection->executeStatement("UPDATE organisation_settings SET display_name = 'Snapshot before drill' WHERE singleton_key = '1'");
        $engine = $this->phase4Service(BackupEngine::class);
        $backup = $engine->create();
        try {
            $engine->restoreForDrill($backup['path']);
            self::fail('Active delivery accepted a restore drill.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Restore requires maintenance mode with no active delivery claims.', $exception->getMessage());
        }
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM backup_record')));
        $connection->executeStatement("UPDATE organisation_settings SET display_name = 'State before restore' WHERE singleton_key = '1'");
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        // Simulate a database silently changing one restored value after insertion.
        $connection->executeStatement("CREATE TRIGGER drill_validation_failure BEFORE INSERT ON organisation_settings FOR EACH ROW SET NEW.display_name = IF(NEW.display_name = 'Snapshot before drill', 'Changed during restore', NEW.display_name)");
        try {
            try {
                $engine->restoreForDrill($backup['path']);
                self::fail('A mismatched restored row issued successful evidence.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Restore failed; the pre-restore backup was reapplied.', $exception->getMessage());
                self::assertSame('Restore-drill rows do not match the backup.', $exception->getPrevious()?->getMessage());
            }
        } finally {
            $connection->executeStatement('DROP TRIGGER drill_validation_failure');
        }
        self::assertSame('State before restore', $connection->fetchOne("SELECT display_name FROM organisation_settings WHERE singleton_key = '1'"));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
        self::assertSame([], glob(\dirname($backup['path']).'/restore-drill-*.json'));
        self::assertNull($connection->fetchOne('SELECT MAX(restored_at) FROM backup_record'));
    }

    private function assertRecordingRejected(BackupEngine $engine, string $path): void
    {
        try {
            $engine->recordRestoreDrill($path);
            self::fail('Invalid restore-drill evidence was recorded.');
        } catch (\RuntimeException) {
        }
    }
}
