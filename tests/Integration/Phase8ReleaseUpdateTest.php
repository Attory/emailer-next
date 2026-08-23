<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Release\Application\PackageInspector;
use App\Module\Release\Application\ReleaseManager;
use App\Shared\MaintenanceOperationLock;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class Phase8ReleaseUpdateTest extends KernelTestCase
{
    use Phase4State;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-phase8-update-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testCleanInstallAndSuccessfulRepresentativeUpdate(): void
    {
        [$connection, $manager, $backups, $archive, $inspector, $locks] = $this->fixture(0);
        $installer = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class), $this->directory.'/clean-install', '0.1.0-rc.1', 0);
        $installed = $installer->install($archive, true);
        self::assertSame($installed, realpath($this->directory.'/clean-install/current'));
        self::assertSame(0o444, fileperms($installed.'/bin/console') & 0o777);

        $this->publishPreviousRelease();
        $manager->update($archive);
        self::assertSame($this->directory.'/releases/0.1.0', realpath($this->directory.'/releases/current'));
        self::assertSame(1, $backups->created);
        self::assertSame(0, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testFailedHealthUsesDeclaredCompatiblePointerRollbackAndLeavesSendingQuiesced(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(2);
        $previous = $this->publishPreviousRelease();
        try {
            $manager->update($archive);
            self::fail('Failed post-switch Doctor was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame($previous, realpath($this->directory.'/releases/current'));
        self::assertSame(0, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testInsufficientDiskReserveFailsBeforeQuiescenceOrBackup(): void
    {
        [$connection, , $backups, $archive, $inspector, $locks] = $this->fixture(0);
        $this->publishPreviousRelease();
        $manager = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class), $this->directory.'/releases', '0.1.0-rc.1', PHP_INT_MAX - 1);
        try {
            $manager->update($archive);
            self::fail('Impossible rollback reserve was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testInterruptedMigrationRestoresBackupAndPreviousPointer(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(0, 2);
        $previous = $this->publishPreviousRelease();
        try {
            $manager->update($archive);
            self::fail('Interrupted migration was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame($previous, realpath($this->directory.'/releases/current'));
        self::assertSame(1, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testIncompatibleSchemaFailsBeforeQuiescenceOrBackup(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(0, 0, '20260825000000', '20260825000000');
        $this->publishPreviousRelease();
        try {
            $manager->update($archive);
            self::fail('Incompatible source schema was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    /** @return array{Connection, ReleaseManager, RecordingBackupOperations, string, PackageInspector, LockFactory} */
    private function fixture(int $doctorExit, int $migrationExit = 0, string $minimumSchema = '20260824010000', string $targetSchema = '20260824010000'): array
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, active_claims = 1 WHERE singleton_key = '1'");
        mkdir($this->directory.'/locks', 0o700);
        $locks = new LockFactory(new FlockStore($this->directory.'/locks'));
        $backups = new RecordingBackupOperations($this->directory.'/safety.enxbackup');
        [$archive, $publicKey] = $this->package($doctorExit, $migrationExit, $minimumSchema, $targetSchema);
        $inspector = new PackageInspector($publicKey, '0.1.0-rc.1');
        $recovery = self::createStub(InterruptedDeliveryRecovery::class);
        $recovery->method('recover')->willReturnCallback(static function () use ($connection): void {
            $connection->executeStatement("UPDATE delivery_runtime SET active_claims = 0 WHERE singleton_key = '1'");
        });
        $manager = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), $recovery, $this->directory.'/releases', '0.1.0-rc.1', 0);

        return [$connection, $manager, $backups, $archive, $inspector, $locks];
    }

    private function publishPreviousRelease(): string
    {
        $previous = $this->directory.'/releases/0.1.0-rc.1';
        if (!is_dir($previous)) {
            mkdir($previous, 0o755, true);
        }
        @unlink($this->directory.'/releases/current');
        symlink($previous, $this->directory.'/releases/current');

        return $previous;
    }

    /** @return array{string, string} */
    private function package(int $doctorExit, int $migrationExit, string $minimumSchema, string $targetSchema): array
    {
        $archive = $this->directory.'/emailer-next-0.1.0.zip';
        $console = "<?php\n\$command = \$argv[1] ?? '';\nexit('emailer:doctor:run' === \$command ? ".$doctorExit." : ('doctrine:migrations:migrate' === \$command ? ".$migrationExit." : 0));\n";
        $frontController = '<?php return true;';
        $lock = '{"packages":[]}';
        $services = "parameters:\n    emailer.app_version: '0.1.0'\n";
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('bin/console', $console));
        self::assertTrue($zip->addFromString('composer.lock', $lock));
        self::assertTrue($zip->addFromString('config/services.yaml', $services));
        self::assertTrue($zip->addFromString('public/index.php', $frontController));
        $zip->close();
        $files = ['bin/console' => hash('sha256', $console), 'composer.lock' => hash('sha256', $lock), 'config/services.yaml' => hash('sha256', $services), 'public/index.php' => hash('sha256', $frontController)];
        $manifest = ['format' => 'emailer-next-release-v1', 'version' => '0.1.0', 'source_commit' => str_repeat('b', 40), 'minimum_source_version' => '0.1.0-rc.1', 'minimum_schema_version' => $minimumSchema, 'schema_version' => $targetSchema, 'rollback_schema_compatible' => true, 'platform' => ['php_min' => '8.4.0', 'php_max_exclusive' => '8.6.0', 'mariadb' => ['10.11', '11.8']], 'files' => $files];
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $checksumBytes = hash_file('sha256', $archive).'  '.basename($archive)."\n";
        $sbomBytes = json_encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'Emailer Next 0.1.0', 'documentNamespace' => 'https://attory.example.test/spdx/emailer-next/'.str_repeat('b', 40).'/0.1.0', 'packages' => [['SPDXID' => 'SPDXRef-Package-synthetic', 'name' => 'synthetic/runtime', 'versionInfo' => '1.0.0', 'licenseDeclared' => 'MIT']]], JSON_THROW_ON_ERROR)."\n";
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        file_put_contents($archive.'.manifest.json', $manifestBytes);
        file_put_contents($archive.'.sha256', $checksumBytes);
        file_put_contents($archive.'.spdx.json', $sbomBytes);
        file_put_contents($archive.'.sig', sodium_bin2base64(sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $secret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        $publicKey = $this->directory.'/release.pub';
        file_put_contents($publicKey, sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        sodium_memzero($secret);

        return [$archive, $publicKey];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            @chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600);
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @chmod($directory, 0o700);
        @rmdir($directory);
    }
}

final class RecordingBackupOperations implements BackupOperations
{
    public int $created = 0;
    public int $restored = 0;

    public function __construct(private readonly string $path)
    {
    }

    public function create(): array
    {
        ++$this->created;

        return ['id' => 'backup', 'path' => $this->path, 'sha256' => str_repeat('a', 64), 'bytes' => 1];
    }

    public function verify(string $path): array
    {
        return [];
    }

    public function restore(string $path): void
    {
        ++$this->restored;
    }
}
