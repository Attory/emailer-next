<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Secrets\Infrastructure\FilesystemMasterKey;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use App\Module\Setup\Infrastructure\DoctrineSetupState;
use App\Module\Setup\Infrastructure\FilesystemInstallationLock;
use App\Module\Setup\Infrastructure\LockedSetupLifecycle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class Phase13FreshDatabaseSetupTest extends KernelTestCase
{
    public function testFreshDatabaseInitializesMetadataOnlyAfterCredentialVerificationAndMigratesOnce(): void
    {
        self::bootKernel();
        $baseline = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $baseline);
        $params = $baseline->getParams();
        self::assertSame('emailer_test', $params['dbname']);
        $host = $params['host'];
        $port = $params['port'] ?? 3306;
        self::assertIsString($host);
        self::assertTrue(\is_int($port) || \is_string($port));
        $baseParams = ['driver' => 'pdo_mysql', 'host' => $host, 'port' => (int) $port, 'charset' => 'utf8mb4'];
        $admin = DriverManager::getConnection([...$baseParams, 'dbname' => 'emailer_test', 'user' => 'root', 'password' => 'local-root-only']);
        $suffix = bin2hex(random_bytes(6));
        $database = 'emailer_test_setup_'.$suffix;
        $user = 'setup_'.$suffix;
        $password = bin2hex(random_bytes(24));
        $directory = sys_get_temp_dir().'/emailer-fresh-setup-'.$suffix;
        self::assertTrue(mkdir($directory, 0o700));
        $connection = null;
        try {
            $admin->executeStatement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
            $admin->executeStatement('CREATE USER '.$admin->quote($user)."@'%' IDENTIFIED BY ".$admin->quote($password));
            $admin->executeStatement('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$admin->quote($user)."@'%'");
            $connection = DriverManager::getConnection([...$baseParams, 'dbname' => $database, 'user' => $user, 'password' => $password]);
            $configuration = new Configuration();
            $configuration->addMigrationsDirectory('DoctrineMigrations', \dirname(__DIR__, 2).'/migrations');
            $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
            $lock = new FilesystemInstallationLock($directory.'/install.lock');
            $lifecycle = new LockedSetupLifecycle($connection, $lock, new LockFactory(new FlockStore($directory)));
            $setup = new DatabaseSetup($connection, $factory, $lifecycle);
            self::assertSame([], $connection->createSchemaManager()->listTableNames());
            try {
                $setup->configure(new DatabaseInput($host, (int) $port, $database, $user, 'wrong-synthetic-password'));
                self::fail('Incorrect application credentials were accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Database connection verification failed.', $exception->getMessage());
                self::assertStringNotContainsString($password, $exception->getMessage());
            }
            self::assertSame([], $connection->createSchemaManager()->listTableNames());
            $input = new DatabaseInput($host, (int) $port, $database, $user, $password);
            $setup->configure($input);
            self::assertTrue($connection->createSchemaManager()->tablesExist(['doctrine_migration_versions', 'installation_state', 'backup_record']));
            $versions = $connection->fetchAllAssociative('SELECT version FROM doctrine_migration_versions ORDER BY version');
            self::assertCount(13, $versions);
            // CLI setup initializes the key in this same process immediately after DDL.
            $key = new FilesystemMasterKey($directory.'/master.key', \dirname(__DIR__, 2).'/public', \dirname(__DIR__, 2), 'test');
            $state = new DoctrineSetupState($connection, $key, $lifecycle);
            self::assertSame(0, $connection->getTransactionNestingLevel());
            $installationId = $state->initializeKey();
            self::assertTrue($key->exists());
            self::assertSame($installationId, $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'"));
            self::assertNotNull($connection->fetchOne("SELECT key_ready_at FROM installation_state WHERE singleton_key = '1'"));
            $connection->beginTransaction();
            $connection->executeStatement("UPDATE installation_state SET organisation_ready_at = '2000-01-01' WHERE singleton_key = '1'");
            $connection->rollBack();
            self::assertNull($connection->fetchOne("SELECT organisation_ready_at FROM installation_state WHERE singleton_key = '1'"));
            $setup->configure($input);
            self::assertSame($versions, $connection->fetchAllAssociative('SELECT version FROM doctrine_migration_versions ORDER BY version'));
            $lock->create([]);
            try {
                $setup->configure($input);
                self::fail('Locked setup accepted a database mutation.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Installation setup is permanently locked.', $exception->getMessage());
            }
        } finally {
            $connection?->close();
            $admin->executeStatement('DROP DATABASE IF EXISTS `'.$database.'`');
            $admin->executeStatement('DROP USER IF EXISTS '.$admin->quote($user)."@'%'");
            $admin->close();
            $files = glob($directory.'/*');
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
