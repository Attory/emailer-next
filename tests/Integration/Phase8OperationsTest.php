<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Backup\Application\BackupEngine;
use App\Module\Health\Application\Doctor;
use App\Module\Housekeeping\Application\Housekeeper;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Phase8OperationsTest extends KernelTestCase
{
    use Phase4State;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testEncryptedBackupRejectsCorruptionAndRestorePreservesSuppression(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement('DELETE FROM housekeeping_run');
        $connection->executeStatement('DELETE FROM health_check_run');
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, active_claims = 0, circuit_state = 'closed' WHERE singleton_key = '1'");
        $suppressionId = Identity::uuidV7();
        $connection->insert('suppression', ['id' => $suppressionId, 'email_canonical' => 'phase8-recipient@example.test', 'reason' => 'manual', 'source' => 'phase8-test', 'created_at' => Identity::now()]);
        $backups = $this->phase4Service(BackupEngine::class);
        $backup = $backups->create();
        $this->files[] = $backup['path'];
        self::assertSame('emailer-next-backup-v1', $backups->verify($backup['path'])['format']);
        $corrupt = $backup['path'].'.corrupt';
        copy($backup['path'], $corrupt);
        chmod($corrupt, 0o600);
        $this->files[] = $corrupt;
        $handle = fopen($corrupt, 'r+b');
        self::assertIsResource($handle);
        fseek($handle, -1, SEEK_END);
        fwrite($handle, 'X');
        fclose($handle);
        try {
            $backups->verify($corrupt);
            self::fail('Corrupted backup was accepted.');
        } catch (\RuntimeException) {
        }
        $connection->delete('suppression', ['id' => $suppressionId]);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0 WHERE singleton_key = '1'");
        $backups->restore($backup['path']);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression WHERE id = ?', [$suppressionId])));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testDoctorAndHousekeepingEvidenceAreBoundedAndRerunnable(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement('DELETE FROM housekeeping_run');
        $connection->executeStatement('DELETE FROM health_check_run');
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, active_claims = 0, circuit_state = 'closed' WHERE singleton_key = '1'");
        $doctor = $this->phase4Service(Doctor::class);
        self::assertSame('healthy', $doctor->run()['status']);
        self::assertSame('healthy', $doctor->run()['status']);
        $housekeeper = $this->phase4Service(Housekeeper::class);
        self::assertSame(['imports' => 0, 'sessions' => 0, 'queue' => 0, 'dead_letter' => 0, 'delivery_attempts' => 0, 'health' => 0, 'analytics' => 0, 'rsvp' => 0, 'backups' => 0], $housekeeper->run(true));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM housekeeping_run')));
    }
}
