<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Release\Application\PackageInspector;
use App\Module\Release\Application\ReleaseManager;
use App\Module\Release\Presentation\Cli\UpdateReleaseCommand;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class Phase8ReleaseUpdateTest extends KernelTestCase
{
    use Phase4State;

    private string $directory;
    private string $publicKey;
    private string $signingKeyFile;
    /** @var non-empty-string */
    private string $signingSecret;
    private string $sourceArchive;
    /** @var list<array<string, mixed>> */
    private array $laterMigrationRows = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-phase8-update-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
        $pair = sodium_crypto_sign_keypair();
        $this->signingSecret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $this->signingKeyFile = $this->directory.'/signing.key';
        file_put_contents($this->signingKeyFile, sodium_bin2base64($this->signingSecret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        chmod($this->signingKeyFile, 0o600);
        $this->publicKey = $this->directory.'/release.pub';
        file_put_contents($this->publicKey, sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
    }

    protected function tearDown(): void
    {
        $signingSecret = $this->signingSecret;
        sodium_memzero($signingSecret);
        $this->removeTree($this->directory);
        if (null !== self::$kernel) {
            $connection = $this->phase4Service(Connection::class);
            foreach ($this->laterMigrationRows as $row) {
                $connection->insert('doctrine_migration_versions', $row);
            }
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testCleanInstallAndSuccessfulRepresentativeUpdate(): void
    {
        [$connection, $manager, $backups, $archive, $inspector, $locks] = $this->fixture(0);
        $installer = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class), $this->directory.'/clean-install', '0.3.0', 0);
        $installed = $installer->install($archive, true);
        self::assertSame($installed, realpath($this->directory.'/clean-install/current'));
        self::assertSame(0o444, fileperms($installed.'/bin/console') & 0o777);

        $this->publishPreviousRelease();
        $manager->update($archive, $this->sourceArchive);
        self::assertSame($this->directory.'/releases/0.4.0', realpath($this->directory.'/releases/current'));
        self::assertSame(1, $backups->created);
        self::assertSame(0, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testFailedProductionOwnershipAssertionRemovesPublishedTarget(): void
    {
        [$connection, , $backups, $archive, $inspector, $locks] = $this->fixture(0);
        $releases = $this->directory.'/ownership-failure';
        $installer = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class), $releases, '0.3.0', 0, 'prod');

        try {
            $installer->install($archive);
            self::fail('Non-root production release ownership was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('root-controlled updater', strtolower($exception->getMessage()));
        }

        self::assertDirectoryDoesNotExist($releases.'/0.4.0');
    }

    public function testFailedHealthRestoresBackupAndPreviousPointerAndLeavesSendingQuiesced(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(2);
        $previous = $this->publishPreviousRelease();
        try {
            $manager->update($archive, $this->sourceArchive);
            self::fail('Failed post-switch Doctor was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame($previous, realpath($this->directory.'/releases/current'));
        self::assertSame(1, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testInsufficientDiskReserveFailsBeforeQuiescenceOrBackup(): void
    {
        [$connection, , $backups, $archive, $inspector, $locks] = $this->fixture(0);
        $this->publishPreviousRelease();
        $manager = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), self::createStub(InterruptedDeliveryRecovery::class), $this->directory.'/releases', '0.3.0', PHP_INT_MAX - 1);
        try {
            $manager->update($archive, $this->sourceArchive);
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
            $manager->update($archive, $this->sourceArchive);
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
            $manager->update($archive, $this->sourceArchive);
            self::fail('Incompatible source schema was accepted.');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testSupervisedNonInteractiveUpdateRequiresAndBindsExactApprovedTarget(): void
    {
        [, $manager, $backups, $archive] = $this->fixture(0);
        $this->publishPreviousRelease();
        $missingApproval = new CommandTester(new UpdateReleaseCommand($manager));
        self::assertSame(Command::FAILURE, $missingApproval->execute([
            'package' => $archive,
            '--source-package' => $this->sourceArchive,
        ], ['interactive' => false]));
        self::assertSame(0, $backups->created);

        $mismatchedApproval = new CommandTester(new UpdateReleaseCommand($manager));
        self::assertSame(Command::FAILURE, $mismatchedApproval->execute([
            'package' => $archive,
            '--source-package' => $this->sourceArchive,
            '--approved-target' => '0.3.0',
        ], ['interactive' => false]));
        self::assertSame(0, $backups->created);

        $approved = new CommandTester(new UpdateReleaseCommand($manager));
        self::assertSame(Command::SUCCESS, $approved->execute([
            'package' => $archive,
            '--source-package' => $this->sourceArchive,
            '--approved-target' => '0.4.0',
        ], ['interactive' => false]));
        self::assertSame($this->directory.'/releases/0.4.0', realpath($this->directory.'/releases/current'));
    }

    #[DataProvider('supportedVersionOneSources')]
    public function testVersionOneTargetUpdaterSucceedsFromEveryAcceptedPredecessorArtifact(string $sourceVersion, string $sourceCommit): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(
            0,
            sourceVersion: $sourceVersion,
            targetVersion: '1.0.0',
            minimumSourceVersion: '0.1.0',
            minimumSchema: '20260824010000',
            targetSchema: '20260828000000',
        );
        $this->publishAcceptedPredecessorRelease($sourceVersion, $sourceCommit);

        $manager->update($archive, $this->sourceArchive);

        self::assertSame($this->directory.'/releases/1.0.0', realpath($this->directory.'/releases/current'));
        self::assertSame(1, $backups->created);
        self::assertSame(0, $backups->restored);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    #[DataProvider('newsletterUpdateOutcomes')]
    public function testNewsletterTargetAuthenticatesDeployedPredecessorAndRecoversFailures(int $doctorExit, int $migrationExit, bool $succeeds): void
    {
        // The fake console leaves DDL/ledger unchanged; actual advancement is guarded below and rehearsed in the VM.
        [$connection, $manager, $backups, $archive] = $this->fixture($doctorExit, $migrationExit, minimumSchema: '20260828000000', targetSchema: '20261008000000', sourceVersion: '1.0.0', targetVersion: '1.0.2', minimumSourceVersion: '1.0.0');
        $previous = $this->publishAcceptedPredecessorRelease('1.0.0', 'eabd9abb153d1ea91c35b060422db04bfcbc2d7a');
        try {
            $manager->update($archive, $this->sourceArchive);
            self::assertTrue($succeeds);
        } catch (\RuntimeException) {
            self::assertFalse($succeeds);
        }
        self::assertSame($succeeds ? $this->directory.'/releases/1.0.2' : $previous, realpath($this->directory.'/releases/current'));
        self::assertSame($succeeds ? 0 : 1, $backups->restored);
        self::assertSame(1, $backups->created);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    #[DataProvider('newsletterUpdateOutcomes')]
    public function testUserRepairTargetAuthenticatesDeployedPredecessorAndRecoversFailures(int $doctorExit, int $migrationExit, bool $succeeds): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture($doctorExit, $migrationExit, minimumSchema: '20261008000000', targetSchema: '20261008000000', sourceVersion: '1.0.2', targetVersion: '1.0.3', minimumSourceVersion: '1.0.2', rollbackCompatible: true);
        $previous = $this->publishAcceptedPredecessorRelease('1.0.2', '4eca16bc7b212a8d35bfe1b6f05f199443a611df');
        try {
            $manager->update($archive, $this->sourceArchive);
            self::assertTrue($succeeds);
        } catch (\RuntimeException) {
            self::assertFalse($succeeds);
        }
        self::assertSame($succeeds ? $this->directory.'/releases/1.0.3' : $previous, realpath($this->directory.'/releases/current'));
        self::assertSame(0 === $migrationExit ? 0 : 1, $backups->restored);
        self::assertSame(1, $backups->created);
        self::assertSame('DoctrineMigrations\\Version20261008000000', $connection->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions'));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function newsletterUpdateOutcomes(): iterable
    {
        yield 'success' => [0, 0, true];
        yield 'migration failure' => [0, 2, false];
        yield 'health failure' => [2, 0, false];
    }

    #[DataProvider('schemaRecoveryFailures')]
    public function testDataRestoreCannotSwitchOlderCodeOntoUnrestoredSchema(bool $metadataOnly, int $migrationExit): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(2, $migrationExit, minimumSchema: '20260828000000', targetSchema: '20261008000000', sourceVersion: '1.0.0', targetVersion: '1.0.2', minimumSourceVersion: '1.0.0');
        $previous = $this->publishPreviousRelease('1.0.0');
        $backups->onRestore = static function () use ($connection, $metadataOnly): void {
            if ($metadataOnly) {
                $connection->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\Version20991231000000', 'executed_at' => Identity::now(), 'execution_time' => 1]);
            } else {
                $connection->executeStatement('ALTER TABLE campaign ADD COLUMN recovery_probe INT DEFAULT NULL');
            }
        };
        try {
            try {
                $manager->update($archive, $this->sourceArchive);
                self::fail('A row-only restore must not authorize rollback onto changed schema.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('automatic rollback failed', $exception->getMessage());
                self::assertSame(0 === $migrationExit ? $this->directory.'/releases/1.0.2' : $previous, realpath($this->directory.'/releases/current'));
                self::assertDirectoryExists($this->directory.'/releases/1.0.2');
                self::assertSame('rollback_failed', $connection->fetchOne("SELECT detail_code FROM release_operation WHERE status = 'failed' ORDER BY finished_at DESC LIMIT 1"));
                self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT accepting_work FROM delivery_runtime')));
                self::assertSame(1, $backups->restored);
                self::assertTrue($backups->restoreCompleted, 'The restore must return normally before schema verification fails.');
                $findings = $this->phase4Service(\App\Module\Health\Infrastructure\SystemReadinessProbe::class)->findings();
                self::assertContains('release_schema_recovery', array_column($findings, 'code'));
            }
        } finally {
            if ($metadataOnly) {
                $connection->delete('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\Version20991231000000']);
            } else {
                $connection->executeStatement('ALTER TABLE campaign DROP COLUMN recovery_probe');
            }
        }
    }

    /** @return iterable<string, array{bool, int}> */
    public static function schemaRecoveryFailures(): iterable
    {
        yield 'health failure with advanced ledger' => [true, 0];
        yield 'health failure with retained DDL' => [false, 0];
        yield 'migration failure with advanced ledger' => [true, 1];
        yield 'migration failure with partial DDL' => [false, 1];
    }

    public function testSignedPredecessorPackageMustExactlyMatchTheInstalledCurrentRelease(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(0);
        $previous = $this->publishPreviousRelease();
        self::assertNotFalse(file_put_contents($previous.'/composer.lock', "\n", FILE_APPEND));

        try {
            $manager->update($archive, $this->sourceArchive);
            self::fail('Installed predecessor drift from its signed package was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('signed package', strtolower($exception->getMessage()));
        }

        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testTamperedPredecessorPackageFailsBeforeDeliveryQuiescence(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(0);
        $this->publishPreviousRelease();
        self::assertNotFalse(file_put_contents($this->sourceArchive.'.sig', 'tamper', FILE_APPEND));

        try {
            $manager->update($archive, $this->sourceArchive);
            self::fail('Tampered signed predecessor evidence was accepted.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testRecordedUpdateSourceMustMatchTheActualCurrentPointer(): void
    {
        [$connection, $manager, $backups, $archive] = $this->fixture(
            0,
            sourceVersion: '0.1.0',
            targetVersion: '1.0.0',
            minimumSourceVersion: '0.1.0',
            minimumSchema: '20260824010000',
            targetSchema: '20260828000000',
        );
        $this->publishPreviousRelease('0.2.0');

        try {
            $manager->update($archive, $this->sourceArchive);
            self::fail('A recorded source different from the current pointer was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('recorded update source', strtolower($exception->getMessage()));
        }

        self::assertSame(0, $backups->created);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    /** @return iterable<string, array{string, string}> */
    public static function supportedVersionOneSources(): iterable
    {
        yield 'accepted 0.1.0' => ['0.1.0', '7d08cc6e20042fc448657fd87ea6722d91c60e7c'];
        yield 'accepted 0.2.0' => ['0.2.0', 'af0d994d32e47d49df6654d6000c42891f8804f2'];
        yield 'accepted 0.3.0' => ['0.3.0', '765a7d1e1a3565b65b186c683bc39a28b3d61466'];
        yield 'accepted 0.4.0' => ['0.4.0', 'c3c5d0ba5373e46f42183c3fc31264316f512afd'];
    }

    /** @return array{Connection, ReleaseManager, RecordingBackupOperations, string, PackageInspector, LockFactory} */
    private function fixture(int $doctorExit, int $migrationExit = 0, string $minimumSchema = '20260827000000', string $targetSchema = '20260828000000', string $sourceVersion = '0.3.0', string $targetVersion = '0.4.0', string $minimumSourceVersion = '0.3.0', bool $rollbackCompatible = false): array
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        // These fault fixtures model the historical schema ledger, not a current-code downgrade.
        $ledgerBoundary = '1.0.2' === $sourceVersion ? 'DoctrineMigrations\\Version20261008000000' : 'DoctrineMigrations\\Version20260828000000';
        $this->laterMigrationRows = $connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions WHERE version > ?', [$ledgerBoundary]);
        $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version > ?', [$ledgerBoundary]);
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, active_claims = 1 WHERE singleton_key = '1'");
        mkdir($this->directory.'/locks', 0o700);
        $locks = new LockFactory(new FlockStore($this->directory.'/locks'));
        $backups = new RecordingBackupOperations($this->directory.'/safety.enxbackup');
        [$archive, $publicKey] = $this->package($doctorExit, $migrationExit, $minimumSchema, $targetSchema, $targetVersion, $minimumSourceVersion, $rollbackCompatible);
        [$this->sourceArchive] = $this->package(0, 0, $this->sourceSchema($sourceVersion), $this->sourceSchema($sourceVersion), $sourceVersion, $sourceVersion);
        $inspector = new PackageInspector($publicKey, $sourceVersion);
        $recovery = self::createStub(InterruptedDeliveryRecovery::class);
        $recovery->method('recover')->willReturnCallback(static function () use ($connection): void {
            $connection->executeStatement("UPDATE delivery_runtime SET active_claims = 0 WHERE singleton_key = '1'");
        });
        $manager = new ReleaseManager($inspector, $backups, $connection, $locks, new MaintenanceOperationLock($locks), $recovery, $this->directory.'/releases', $sourceVersion, 0);

        return [$connection, $manager, $backups, $archive, $inspector, $locks];
    }

    private function publishPreviousRelease(string $version = '0.3.0'): string
    {
        $previous = $this->directory.'/releases/'.$version;
        self::assertFalse(is_dir($previous));
        self::assertTrue(mkdir($previous, 0o755, true));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->sourceArchive));
        self::assertTrue($zip->extractTo($previous));
        $zip->close();
        @unlink($this->directory.'/releases/current');
        symlink($previous, $this->directory.'/releases/current');

        return $previous;
    }

    private function publishAcceptedPredecessorRelease(string $version, string $commit): string
    {
        $projectRoot = \dirname(__DIR__, 2);
        $historyDirectory = getenv('EMAILER_ACCEPTED_HISTORY_GIT_DIR');
        if (false === $historyDirectory || '' === $historyDirectory) {
            $historyDirectory = $projectRoot.'/.git';
        }
        self::assertDirectoryExists($historyDirectory, 'Accepted predecessor Git history is unavailable.');
        $sourceArchive = $this->directory.'/accepted-source-'.$version.'.zip';
        $source = $this->directory.'/accepted-source-'.$version;
        $output = $this->directory.'/accepted-package-'.$version;
        self::assertTrue(mkdir($source, 0o700));
        self::assertTrue(mkdir($output, 0o700));
        $this->runProcess(['git', '--git-dir='.$historyDirectory, 'archive', '--format=zip', '--output='.$sourceArchive, $commit], $projectRoot, 'Accepted predecessor commit could not be archived.');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($sourceArchive));
        self::assertTrue($zip->extractTo($source));
        $zip->close();
        $this->runProcess(['cp', '-a', $projectRoot.'/vendor', $source.'/vendor'], $projectRoot, 'Locked predecessor dependencies could not be staged.');
        $importMapVendor = is_dir('/opt/emailer-assets-vendor')
            ? '/opt/emailer-assets-vendor'
            : $projectRoot.'/assets/vendor';
        self::assertDirectoryExists($importMapVendor, 'Locked predecessor import-map dependencies are unavailable.');
        $this->runProcess(['cp', '-a', $importMapVendor, $source.'/assets/vendor'], $projectRoot, 'Locked predecessor import-map dependencies could not be staged.');
        $this->runProcess(['bash', $source.'/tools/artifact/build.sh'], $source, 'Accepted predecessor runtime artifact could not be built.');
        $minimumSource = match ($version) {
            '0.1.0' => '0.1.0-rc.1',
            '0.2.0' => '0.1.0',
            '0.3.0' => '0.2.0',
            '0.4.0' => '0.3.0',
            '1.0.0' => '0.1.0',
            '1.0.2' => '1.0.0',
            default => throw new \InvalidArgumentException('Unsupported accepted predecessor version.'),
        };
        $rollbackCompatible = '0.1.0' === $version ? 'true' : 'false';
        $epochProcess = proc_open(
            ['git', '--git-dir='.$historyDirectory, 'show', '-s', '--format=%ct', $commit],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $epochPipes,
            $projectRoot,
        );
        self::assertIsResource($epochProcess, 'Accepted predecessor source epoch could not be read.');
        $sourceEpoch = trim((string) stream_get_contents($epochPipes[1]));
        fclose($epochPipes[1]);
        self::assertSame(0, proc_close($epochProcess), 'Accepted predecessor source epoch could not be read.');
        self::assertMatchesRegularExpression('/^[0-9]+$/D', $sourceEpoch);
        $this->runProcess([
            'php',
            $source.'/tools/release/build-package.php',
            '--artifact='.$source.'/build/artifact',
            '--output='.$output,
            '--version='.$version,
            '--source='.$commit,
            '--minimum-source='.$minimumSource,
            '--minimum-schema='.('1.0.2' === $version ? '20260828000000' : $this->sourceSchema($version)),
            '--schema='.$this->sourceSchema($version),
            '--rollback-schema-compatible='.$rollbackCompatible,
            '--signing-key='.$this->signingKeyFile,
            '--source-date-epoch='.$sourceEpoch,
        ], $source, 'Accepted predecessor signed package could not be built.');
        $archive = $output.'/emailer-next-'.$version.'.zip';
        self::assertFileExists($archive);
        foreach (['.manifest.json', '.sha256', '.spdx.json', '.sig'] as $suffix) {
            self::assertFileExists($archive.$suffix);
        }

        $previous = $this->directory.'/releases/'.$version;
        self::assertTrue(mkdir($previous, 0o755, true));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertTrue($zip->extractTo($previous));
        $zip->close();
        $services = file_get_contents($previous.'/config/services.yaml');
        self::assertIsString($services);
        self::assertStringContainsString("emailer.app_version: '".$version."'", $services);
        $this->sourceArchive = $archive;

        @unlink($this->directory.'/releases/current');
        self::assertTrue(symlink($previous, $this->directory.'/releases/current'));

        return $previous;
    }

    /** @param list<string> $command */
    private function runProcess(array $command, string $workingDirectory, string $failure): void
    {
        $process = proc_open(
            $command,
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            $workingDirectory,
        );
        self::assertIsResource($process, $failure);
        self::assertSame(0, proc_close($process), $failure);
    }

    /** @return array{string, string} */
    private function package(int $doctorExit, int $migrationExit, string $minimumSchema, string $targetSchema, string $targetVersion, string $minimumSourceVersion, bool $rollbackCompatible = false): array
    {
        $archive = $this->directory.'/emailer-next-'.$targetVersion.'.zip';
        $console = "<?php\n\$command = \$argv[1] ?? '';\nexit('emailer:doctor:run' === \$command ? ".$doctorExit." : ('doctrine:migrations:migrate' === \$command ? ".$migrationExit." : 0));\n";
        $frontController = '<?php return true;';
        $lock = '{"packages":[]}';
        $services = "parameters:\n    emailer.app_version: '".$targetVersion."'\n";
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('bin/console', $console));
        self::assertTrue($zip->addFromString('composer.lock', $lock));
        self::assertTrue($zip->addFromString('config/services.yaml', $services));
        self::assertTrue($zip->addFromString('public/index.php', $frontController));
        $zip->close();
        $files = ['bin/console' => hash('sha256', $console), 'composer.lock' => hash('sha256', $lock), 'config/services.yaml' => hash('sha256', $services), 'public/index.php' => hash('sha256', $frontController)];
        $supportedSources = '1.0.0' === $targetVersion && '0.1.0' === $minimumSourceVersion ? ['0.1.0', '0.2.0', '0.3.0', '0.4.0'] : [$minimumSourceVersion];
        $manifest = ['format' => 'emailer-next-release-v1', 'version' => $targetVersion, 'source_commit' => str_repeat('b', 40), 'minimum_source_version' => $minimumSourceVersion, 'supported_source_versions' => $supportedSources, 'minimum_schema_version' => $minimumSchema, 'schema_version' => $targetSchema, 'rollback_schema_compatible' => $rollbackCompatible, 'platform' => ['php_min' => '8.4.0', 'php_max_exclusive' => '8.6.0', 'mariadb' => ['10.11', '11.8']], 'files' => $files];
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $checksumBytes = hash_file('sha256', $archive).'  '.basename($archive)."\n";
        $sbomBytes = json_encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'Emailer Next '.$targetVersion, 'documentNamespace' => 'https://attory.example.test/spdx/emailer-next/'.str_repeat('b', 40).'/'.$targetVersion, 'packages' => [['SPDXID' => 'SPDXRef-Package-synthetic', 'name' => 'synthetic/runtime', 'versionInfo' => '1.0.0', 'licenseDeclared' => 'MIT']]], JSON_THROW_ON_ERROR)."\n";
        file_put_contents($archive.'.manifest.json', $manifestBytes);
        file_put_contents($archive.'.sha256', $checksumBytes);
        file_put_contents($archive.'.spdx.json', $sbomBytes);
        file_put_contents($archive.'.sig', sodium_bin2base64(sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $this->signingSecret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");

        return [$archive, $this->publicKey];
    }

    private function sourceSchema(string $version): string
    {
        return match ($version) {
            '0.1.0' => '20260824010000',
            '0.2.0' => '20260826000000',
            '0.3.0' => '20260827000000',
            '0.4.0', '1.0.0' => '20260828000000',
            '1.0.2' => '20261008000000',
            default => throw new \InvalidArgumentException('Unsupported synthetic source version.'),
        };
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
    public ?\Closure $onRestore = null;
    public bool $restoreCompleted = false;

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
        ($this->onRestore ?? static function (): void {})();
        $this->restoreCompleted = true;
    }
}
