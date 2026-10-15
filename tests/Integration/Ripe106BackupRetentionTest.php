<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Backup\Application\BackupAutomation;
use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Application\Contract\ExternalBackupDestination;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Ripe106BackupRetentionTest extends KernelTestCase
{
    use Phase4State;

    public function testAutomaticRetentionPreservesLightLocalAndTransferredRecoveryAssets(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $private = self::getContainer()->getParameter('emailer.private_dir');
        self::assertIsString($private);
        $directory = $private.'/retention-fixture';
        mkdir($directory, 0o700);
        $ids = [];
        $paths = [];
        $destination = $this->createMock(ExternalBackupDestination::class);
        $destination->expects(self::never())->method('delete');
        $automation = new BackupAutomation($this->phase4Service(BackupEngine::class), $destination, $connection, $directory);
        try {
            foreach (['light', 'expired_native', 'latest_native'] as $kind) {
                $id = Identity::uuidV7();
                $ids[$kind] = $id;
                $filename = 'backup-'.$id.'.enxbackup';
                $paths[$kind] = $directory.'/'.$filename;
                // Non-restorable synthetic retention markers; no archive codec
                // claim is made by this filesystem-retention regression.
                $bytes = 'synthetic retention marker '.$kind;
                file_put_contents($paths[$kind], $bytes);
                chmod($paths[$kind], 0o600);
                $connection->insert('backup_record', ['id' => $id, 'format_version' => 1, 'filename' => $filename, 'ciphertext_sha256' => hash('sha256', $bytes), 'byte_size' => \strlen($bytes), 'schema_version' => 'light' === $kind ? '20261014000000' : '20261015000000', 'source_version' => 'light' === $kind ? '1.0.0' : '1.0.6', 'source_edition' => 'light' === $kind ? 'emailer-light' : 'emailer-ripe', 'status' => 'latest_native' === $kind ? 'verified' : 'created', 'created_at' => '2000-01-01 00:00:00.000000', 'verified_at' => 'latest_native' === $kind ? Identity::now() : null]);
            }
            $connection->insert('backup_transfer', ['id' => Identity::uuidV7(), 'backup_id' => $ids['light'], 'destination_type' => 'filesystem', 'object_name' => basename($paths['light']), 'ciphertext_sha256' => hash_file('sha256', $paths['light']), 'byte_size' => filesize($paths['light']), 'status' => 'succeeded', 'detail_code' => 'verified', 'attempted_at' => '2000-01-01 00:00:00.000000', 'completed_at' => '2000-01-01 00:00:00.000000']);
            self::assertSame(1, $automation->prune(true, 30));
            self::assertSame(1, $automation->prune(false, 30));
            self::assertFileExists($paths['light']);
            self::assertFileExists($paths['latest_native']);
            self::assertFileDoesNotExist($paths['expired_native']);
            self::assertSame('created', $connection->fetchOne('SELECT status FROM backup_record WHERE id = ?', [$ids['light']]));
            self::assertSame('succeeded', $connection->fetchOne('SELECT status FROM backup_transfer WHERE backup_id = ?', [$ids['light']]));
        } finally {
            foreach ($ids as $id) {
                $connection->delete('backup_transfer', ['backup_id' => $id]);
                $connection->delete('backup_record', ['id' => $id]);
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
            $this->removePhase4Files();
        }
    }
}
