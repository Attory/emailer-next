<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Release\Application\EditionRecoverySet;
use App\Module\Release\Application\PackageInspector;
use App\Module\Release\Application\ReleaseManager;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class NativePatchRecoveryTest extends TestCase
{
    public function testPublished131RecoveryReachesTheDatabaseLockWithoutExecutingRecoveryWhenItIsBusy(): void
    {
        $root = sys_get_temp_dir().'/ripe-patch-recovery-'.bin2hex(random_bytes(8));
        $operation = Identity::uuidV7();
        $directory = $root.'/native-update-recovery/'.$operation;
        mkdir($directory, 0o700, true);
        file_put_contents($directory.'/native-operation.json', '{}');
        chmod($directory.'/native-operation.json', 0o600);
        $connection = self::createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('emailer_test');
        $connection->expects(self::once())->method('fetchOne')->with(self::stringContains('GET_LOCK'), self::anything())->willReturn(0);
        $connection->expects(self::never())->method('executeStatement');
        try {
            try {
                $this->manager($connection, $root, '1.3.1')->recoverNativeUpdate($operation, $root.'/target.zip', $root.'/source.zip');
                self::fail('Recovery proceeded despite the held database operation lock.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Native recovery did not complete.', $error->getMessage());
            }
            $diagnostic = json_decode((string) file_get_contents($directory.'/native-recovery-failure.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($diagnostic);
            self::assertSame('Another database-scoped native release operation is active.', $diagnostic['message']);
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testPublished131CannotStartAnUpdateWhileAnotherInstallationsCheckpointIsPresent(): void
    {
        $root = sys_get_temp_dir().'/ripe-patch-custody-'.bin2hex(random_bytes(8));
        $directory = $root.'/native-update-recovery/'.Identity::uuidV7();
        mkdir($directory, 0o700, true);
        $bytes = json_encode(['format' => 'emailer-native-update-recovery-v1', 'private_directory' => $root.'/private',
            'database_binding' => 'different-installation', 'phase' => 'preparing'], JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/native-operation.json', $bytes);
        chmod($directory.'/native-operation.json', 0o600);
        $connection = self::createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('emailer_test');
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'database', 'dbname' => 'emailer_test']);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('executeStatement');
        try {
            try {
                $this->manager($connection, $root, '1.3.1')->update($root.'/target.zip', $root.'/source.zip');
                self::fail('A retained foreign native checkpoint did not prevent a new update.');
            } catch (\RuntimeException $error) {
                self::assertSame('Retained native operation belongs to a different installation or database.', $error->getMessage());
            }
            self::assertSame($bytes, file_get_contents($directory.'/native-operation.json'));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    private function manager(Connection $connection, string $root, string $source): ReleaseManager
    {
        $locks = new LockFactory(new InMemoryStore());

        return new ReleaseManager(new PackageInspector($root.'/test.pub', $source), self::createStub(BackupOperations::class),
            $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class),
            $root.'/releases', $source, completeRecovery: new EditionRecoverySet($connection), privateDirectory: $root.'/private');
    }
}
