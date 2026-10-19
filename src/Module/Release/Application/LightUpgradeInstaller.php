<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Module\Release\Application\Contract\EditionUpgrade;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/** Edition orchestration is deliberately separate from native Ripe version updates. */
final readonly class LightUpgradeInstaller implements EditionUpgrade
{
    public function __construct(private Connection $connection, private \App\Module\Audit\Application\Contract\AuditSink $audit, private PackageInspector $packages, private ReleaseManager $releases, private LightSourceInspection $source, private LightSchemaTransition $schema, private EditionRecoverySet $recovery, private MaintenanceOperationLock $maintenance, private string $projectDirectory, private string $privateDirectory, private string $masterKeyFile, private string $releasesDirectory, private string $recoveryDatabaseUrl, private string $environment, private SourceDatabaseFence $fence, private string $runtimeConfigurationFile, private string $ripePublicKeyFile, private string $ripeRecoveryKeyFile, private \App\Module\Secrets\Application\Contract\RipeRecoveryKeyProvisioning $recoveryKeys, private \App\Module\Backup\Application\Contract\BackupOperations $backups, private bool $retainSourceFence = false, private string $sourceServicePrefix = 'emailer-light', private string $operationReceiptFile = '')
    {
    }

    public function run(string $action, string $targetPackage, string $sourcePackage, string $recoveryDirectory, ?string $recoveryPassphrase = null, ?string $exportDirectory = null): array
    {
        if (!\in_array($action, ['inspect', 'execute', 'recover', 'accept', 'export-light-recovery'], true)) {
            throw new \InvalidArgumentException('Unsupported edition installer action.');
        }
        $this->assertIsolatedPath($recoveryDirectory);
        if (null !== $exportDirectory) {
            $this->assertIsolatedPath($exportDirectory);
        }
        $manifest = $this->packages->inspect($targetPackage);
        if ('1.3.0' !== $manifest['version'] || '20261015000000' !== $manifest['schema_version']) {
            throw new \RuntimeException('Only the authenticated Ripe 1.3.0 target is supported.');
        }
        if ('export-light-recovery' === $action) {
            if (null === $exportDirectory || str_starts_with($exportDirectory.'/', $this->privateDirectory.'/') || str_starts_with($exportDirectory.'/', $this->releasesDirectory.'/')) {
                throw new \RuntimeException('Light recovery export must target a separate empty isolated tree.');
            }
            $this->recovery->exportLightFiles($recoveryDirectory, $exportDirectory);

            return ['status' => 'isolated_light_recovery_files_exported', 'active_installation_changed' => false];
        }
        $sourceRoot = $this->sourceRoot();
        $sourceVersion = LightSourcePackage::authenticate($sourcePackage);
        if (basename($sourceRoot) !== $sourceVersion) {
            throw new \RuntimeException('The authenticated Light package differs from the installed source version.');
        }
        $this->verifyInstalled($sourceRoot, $sourcePackage);
        $this->validateSourceConfiguration($sourceRoot);
        $journal = $this->receipt(false);
        if ([] !== $journal && ($journal['target_package_sha256'] ?? null) !== $manifest['_verified_archive_sha256']) {
            throw new \RuntimeException('The current installer package differs from the durable operation journal. Retain the exact authenticated operation package.');
        }
        if ('inspect' === $action) {
            $source = $this->source->inspect(false);

            return ['format' => 'emailer-ripe-edition-inspection-v1', 'source' => 'emailer-light/'.$sourceVersion, 'target' => 'emailer-ripe/1.3.0', 'installation_uuid' => EditionData::text($source['identity']['installation_uuid']), 'mutation' => false, 'delivery' => 'explicit_hold_and_acceptance_required'];
        }
        $this->maintenance->acquire();
        $databaseLock = 'emailer-edition-'.substr(hash('sha256', EditionData::text($this->connection->getDatabase())), 0, 48);
        $databaseLockAcquired = false;
        try {
            if (1 !== EditionData::integer($this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$databaseLock]))) {
                throw new \RuntimeException('Another installation edition operation is active; no data was changed.');
            }
            $databaseLockAcquired = true;
            if ('recover' === $action) {
                $this->recover($recoveryDirectory, $sourceRoot);

                return ['status' => 'recovered', 'delivery' => 'held'];
            }
            if ('accept' === $action) {
                $receipt = $this->receipt();
                $this->verifyInstalled($this->releasesDirectory.'/ripe-1.3.0', $targetPackage);
                if (realpath($this->releasesDirectory.'/current') !== $this->releasesDirectory.'/ripe-1.3.0') {
                    throw new \RuntimeException('Acceptance requires the exact validated Ripe release to remain active.');
                }
                if ('accepted' === ($receipt['phase'] ?? null)) {
                    $this->clearRehearsalDatabase($receipt);
                    $this->finishAcceptedFence($receipt);

                    return ['status' => 'already_accepted', 'delivery' => 'held_until_emailer_delivery_resume'];
                }
                if ('activated' !== ($receipt['phase'] ?? null)) {
                    throw new \RuntimeException('Only a validated, activated transition can be accepted.');
                }
                $this->console($this->releasesDirectory.'/ripe-1.3.0', ['emailer:health:verify-runtime', '--offline-maintenance'], [0]);
                $this->clearRehearsalDatabase($receipt);
                // The replacement identity must be proven before acceptance becomes durable.
                if (true === ($receipt['retain_source_fence'] ?? false)) {
                    $this->fence->replacement(SourceDatabaseFence::account($receipt['database_account']));
                }
                $receipt['phase'] = 'accepted';
                $this->record($receipt);
                $this->finishAcceptedFence($receipt);

                return ['status' => 'accepted', 'delivery' => 'held_until_emailer_delivery_resume'];
            }
            if ([] !== $this->receipt(false)) {
                $receipt = $this->receipt();
                if (\in_array($receipt['phase'] ?? null, ['activated', 'accepted'], true)) {
                    throw new \RuntimeException('Edition transition already completed; use explicit acceptance, not conversion.');
                }
                $this->recover($recoveryDirectory, $sourceRoot);
            }
            $source = $this->source->inspect(false);
            $receipt = ['format' => 'emailer-ripe-edition-transition-v1', 'operation_id' => Identity::uuidV7(), 'installation_uuid' => EditionData::text($source['identity']['installation_uuid']), 'phase' => 'quiescing', 'retain_source_fence' => $this->retainSourceFence, 'source_worker_mode' => EditionData::text($this->connection->fetchOne("SELECT worker_mode FROM delivery_policy WHERE singleton_key = '1'")), 'recovery_directory' => $recoveryDirectory, 'source_root' => $sourceRoot, 'smtp_deferred' => 0 === EditionData::integer($this->connection->fetchOne('SELECT COUNT(*) FROM sender_identity')), 'target_package_sha256' => $manifest['_verified_archive_sha256']];
            EditionRecoverySet::directory($recoveryDirectory);
            $receipt['recovery_base'] = $recoveryDirectory;
            $recoveryDirectory .= '/'.$receipt['operation_id'];
            $receipt['recovery_directory'] = $recoveryDirectory;
            EditionRecoverySet::directory($recoveryDirectory);
            if (str_starts_with($recoveryDirectory.'/', $this->privateDirectory.'/') || str_starts_with($recoveryDirectory.'/', $this->releasesDirectory.'/')) {
                throw new \RuntimeException('Recovery set must be outside private storage and immutable release directories.');
            }
            $account = $this->fence->inspect();
            $receipt['database_account'] = $account;
            $this->record($receipt);
            $this->connection->transactional(function (): void {
                $runtime = $this->connection->fetchAssociative("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
                if (false === $runtime || 0 !== EditionData::integer($runtime['active_claims'])) {
                    throw new \RuntimeException('Active sends prevent transition; no claim is reset.');
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
            });
            $receipt['services_stopped'] = true;
            $this->record($receipt);
            $this->stopSourceServices();
            $this->fence->lock($account);
            $this->source->assertIdle();
            $source = $this->source->inspect(true);
            $before = $this->fingerprints($this->connection);
            $receipt['phase'] = 'backing_up';
            $this->record($receipt);
            $roots = ['private' => $this->privateDirectory, 'master_key' => $this->masterKeyFile];
            if ('' !== $this->runtimeConfigurationFile) {
                EditionRecoverySet::safeConfiguration($this->runtimeConfigurationFile);
                $roots['runtime_configuration'] = $this->runtimeConfigurationFile;
                $original = (new \Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents($this->runtimeConfigurationFile));
                foreach (['EMAILER_RECOVERY_KEY_FILE' => 'light_recovery_key'] as $variable => $role) {
                    if (isset($original[$variable]) && is_file(EditionData::text($original[$variable]))) {
                        $roots[$role] = EditionData::text($original[$variable]);
                    }
                }
            } elseif ('prod' === $this->environment) {
                throw new \RuntimeException('The protected source runtime configuration file is required for complete recovery.');
            }
            $this->recovery->create($recoveryDirectory, $roots);
            $scratch = $this->scratch();
            $leaseFile = EditionData::text($receipt['recovery_base']).'/scratch-owner.json';
            $lease = ['format' => 'emailer-edition-scratch-v1', 'installation_uuid' => $receipt['installation_uuid'], 'database' => $scratch->getDatabase(), 'target_package_sha256' => $receipt['target_package_sha256']];
            if ([] !== $scratch->createSchemaManager()->listTableNames()) {
                if (!is_file($leaseFile) || EditionData::read($leaseFile) !== $lease) {
                    throw new \RuntimeException('The recovery rehearsal database is nonempty and is not owned by this installation transition.');
                }
                EditionRecoverySet::safe($leaseFile, false);
            }
            EditionRecoverySet::write($leaseFile, json_encode($lease, JSON_THROW_ON_ERROR));
            $this->recovery->restore($recoveryDirectory, $scratch, false);
            if ($before !== $this->fingerprints($scratch) || EditionSchemaProof::capture($this->connection) !== EditionSchemaProof::capture($scratch)) {
                throw new \RuntimeException('Complete recovery rehearsal did not preserve database fingerprints.');
            }
            $target = $this->releasesDirectory.'/ripe-1.3.0';
            if (is_dir($target)) {
                $this->verifyInstalled($target, $targetPackage);
            } else {
                $target = $this->releases->install($targetPackage, false, true);
            }
            $receipt['phase'] = 'mutating';
            $this->record($receipt);
            try {
                if (null === $recoveryPassphrase) {
                    throw new \RuntimeException('Protected interactive Ripe recovery-package verification is required.');
                }
                $this->recoveryKeys->prepareRipeRecovery($recoveryPassphrase);
                if ('prod' === $this->environment) {
                    $owner = stat($this->masterKeyFile);
                    $package = lstat($this->ripeRecoveryKeyFile);
                    if (!\is_array($owner) || !\is_array($package) || 0 === $owner['uid'] || !\in_array($package['uid'], [0, $owner['uid']], true) || !chown($this->ripeRecoveryKeyFile, $owner['uid']) || !chgrp($this->ripeRecoveryKeyFile, $owner['gid'])) {
                        throw new \RuntimeException('The verified Ripe recovery package cannot be assigned to its dedicated application account.');
                    }
                }
                $this->schema->apply();
                $this->verifyMappedRows($before);
                // Active Light sessions retain their records and payloads but lose login authority.
                $invalidated = $this->connection->executeStatement('UPDATE user_session SET revoked_at = UTC_TIMESTAMP(6) WHERE revoked_at IS NULL');
                $this->verifyTargetShape();
                $sourceSchema = $this->recovery->verify($recoveryDirectory)['schema'];
                $preservationHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
                $this->connection->insert('ripe_edition_transition', ['installation_uuid' => EditionData::text($source['identity']['installation_uuid']), 'source_identity_json' => json_encode($source['identity'], JSON_THROW_ON_ERROR), 'source_product_id' => $source['identity']['product_id'], 'source_product_version' => $source['identity']['product_version'], 'source_schema_version' => $source['identity']['schema_version'], 'source_contract_version' => $source['identity']['upgrade_contract_version'], 'source_created_at' => $source['identity']['created_at'], 'source_updated_at' => $source['identity']['updated_at'], 'source_migration_version' => $source['migration']['version'], 'source_migration_executed_at' => $source['migration']['executed_at'], 'source_migration_execution_time' => $source['migration']['execution_time'], 'source_migration_json' => json_encode($source['migration'], JSON_THROW_ON_ERROR), 'source_schema_sha256' => hash('sha256', json_encode($sourceSchema, JSON_THROW_ON_ERROR)), 'preservation_sha256' => $preservationHash, 'target_version' => '1.3.0', 'target_schema' => '20261015000000', 'completed_at' => Identity::now()]);
                // Baseline records are established only AFTER every native structure and mapped row is verified.
                $this->connection->executeStatement('DELETE FROM doctrine_migration_versions');
                $migrationFiles = glob($this->projectDirectory.'/migrations/Version*.php');
                foreach (false === $migrationFiles ? [] : $migrationFiles as $migration) {
                    $this->connection->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\'.basename($migration, '.php'), 'executed_at' => Identity::now(), 'execution_time' => 0]);
                }
                $this->connection->update('product_identity', ['product_id' => 'emailer-ripe', 'product_version' => '1.3.0', 'schema_version' => '20261015000000', 'updated_at' => Identity::now()], ['singleton_key' => '1']);
                $lock = EditionData::read($this->privateDirectory.'/install.lock');
                $lock['product_id'] = 'emailer-ripe';
                $lock['product_version'] = '1.3.0';
                $lock['schema_version'] = '20261015000000';
                EditionRecoverySet::write($this->privateDirectory.'/install.lock', json_encode($lock, JSON_THROW_ON_ERROR), true);
                $this->updateRuntimeConfiguration();
                $this->console($target, ['about', '--no-interaction']);
                $this->backups->create();
                $this->console($target, ['doctrine:migrations:up-to-date']);
                $this->console($target, ['doctrine:schema:validate', '--skip-sync']);
                $this->console($target, ['emailer:health:verify-runtime', '--offline-maintenance'], [0]);
                $receipt['phase'] = 'validated';
                $this->record($receipt);
                $this->switchPointer($target);
                $this->console($target, ['emailer:health:verify-runtime', '--offline-maintenance'], [0]);
                $this->audit->append(new \App\Module\Audit\Application\Contract\AuditRecord(null, 'edition.light_to_ripe.completed', EditionData::text($source['identity']['installation_uuid']), 'success', ['source_version' => $sourceVersion, 'target_version' => '1.3.0', 'revoked_login_count' => $invalidated]));
                $receipt['phase'] = 'activated';
                $receipt['invalidated_session_count'] = $invalidated;
                $receipt['preservation_sha256'] = $preservationHash;
                $this->record($receipt);

                return ['status' => 'activated', 'delivery' => 'held', 'invalidated_sessions' => $invalidated];
            } catch (\Throwable $failure) {
                EditionRecoverySet::write($recoveryDirectory.'/transition-failure.json', json_encode(['exception_class' => $failure::class, 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR));
                try {
                    $this->recover($recoveryDirectory, $sourceRoot);
                } catch (\Throwable $recoveryFailure) {
                    EditionRecoverySet::write($recoveryDirectory.'/recovery-failure.json', json_encode(['exception_class' => $recoveryFailure::class, 'message' => $recoveryFailure->getMessage()], JSON_THROW_ON_ERROR));
                    throw new \RuntimeException('Edition transition failed; complete recovery requires operator repair. Delivery remains held. Detailed diagnostics remain protected in the recovery set.');
                }
                throw new \RuntimeException('Edition transition failed; verified Light recovery completed. Delivery remains held. Detailed diagnostics remain protected in the recovery set.');
            }
        } catch (\Throwable $failure) {
            $pending = $this->receipt(false);
            if ($databaseLockAcquired && 'execute' === $action && \in_array($pending['phase'] ?? null, ['quiescing', 'backing_up'], true)) {
                try {
                    EditionRecoverySet::write(EditionData::text($pending['recovery_directory']).'/transition-failure.json', json_encode(['exception_class' => $failure::class, 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR));
                    $this->recover($recoveryDirectory, $sourceRoot);
                } catch (\Throwable) {
                    throw new \RuntimeException('Edition preparation failed. Delivery remains held; rerun the exact authenticated installer with --edition-action=recover after repairing the preparation failure.');
                }
                throw new \RuntimeException('Edition preparation failed; unchanged Light was verified and its account restored. Delivery remains held. Protected recovery diagnostics identify the preparation failure; repair it before rerunning the exact installer.');
            }
            throw $failure;
        } finally {
            try {
                if ($databaseLockAcquired) {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$databaseLock]);
                }
            } finally {
                $this->maintenance->release();
            }
        }
    }

    /** @param array<string, mixed> $receipt */
    private function finishAcceptedFence(array $receipt): void
    {
        if (true === ($receipt['fence_restored'] ?? false)) {
            if (true === ($receipt['retain_source_fence'] ?? false) && ($receipt['writer_replacement'] ?? null) !== $this->fence->replacement(SourceDatabaseFence::account($receipt['database_account']))) {
                throw new \RuntimeException('The accepted writer handoff has drifted. Keep delivery held and investigate.');
            }

            return;
        }
        if (true === ($receipt['retain_source_fence'] ?? false)) {
            $receipt['writer_replacement'] = $this->fence->replacement(SourceDatabaseFence::account($receipt['database_account']));
        } else {
            $this->fence->restore(SourceDatabaseFence::account($receipt['database_account']));
        }
        $receipt['fence_restored'] = true;
        $this->record($receipt);
    }

    /** @param array<string, mixed> $receipt */
    private function clearRehearsalDatabase(array $receipt): void
    {
        $scratch = $this->scratch();
        $leaseFile = EditionData::text($receipt['recovery_base']).'/scratch-owner.json';
        EditionRecoverySet::safe($leaseFile, false);
        $lease = ['format' => 'emailer-edition-scratch-v1', 'installation_uuid' => $receipt['installation_uuid'], 'database' => $scratch->getDatabase(), 'target_package_sha256' => $receipt['target_package_sha256']];
        if (EditionData::read($leaseFile) !== $lease) {
            throw new \RuntimeException('Recovery rehearsal cleanup requires the original installation ownership receipt.');
        }
        $tables = $scratch->createSchemaManager()->listTableNames();
        $source = EditionData::object(EditionData::read($this->projectDirectory.'/config/upgrade/light-schema-details-1.0.0.json')['tables'] ?? null);
        if ([] !== array_diff($tables, array_keys($source)) || false !== $scratch->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' LIMIT 1")) {
            throw new \RuntimeException('Recovery rehearsal cleanup refuses an unexpected database inventory.');
        }
        // A partial cleanup is replayable: every remaining table must still be
        // owned by the verified lease and the published source inventory.
        $scratch->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $scratch->executeStatement('DROP TABLE '.$scratch->quoteSingleIdentifier($table));
            }
        } finally {
            $scratch->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $scratch->close();
        }
    }

    private function recover(string $directory, string $sourceRoot): void
    {
        $receipt = $this->receipt();
        if ('accepted' === ($receipt['phase'] ?? null) || ($receipt['recovery_base'] ?? null) !== $directory && ($receipt['recovery_directory'] ?? null) !== $directory || ($receipt['source_root'] ?? null) !== $sourceRoot) {
            throw new \RuntimeException('Recovery journal does not authorize this unfinished transition. Ripe to Light downgrade is prohibited.');
        }
        if ('recovered' === ($receipt['phase'] ?? null)) {
            if (realpath($this->releasesDirectory.'/current') !== $sourceRoot) {
                throw new \RuntimeException('Recovered source pointer drifted; the old checkpoint cannot overwrite subsequent state.');
            }
            $source = $this->source->inspect(false);
            if (($source['identity']['installation_uuid'] ?? null) !== $receipt['installation_uuid']) {
                throw new \RuntimeException('Recovered source installation identity drifted.');
            }
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
            $this->fence->restore(SourceDatabaseFence::account($receipt['database_account']));

            return;
        }
        $directory = EditionData::text($receipt['recovery_directory']);
        $originalPhase = $receipt['phase'] ?? null;
        $requiresRestore = 'recovering' === $originalPhase ? ($receipt['recovery_requires_restore'] ?? true) : !\in_array($originalPhase, ['quiescing', 'backing_up'], true);
        if (!\is_bool($requiresRestore)) {
            throw new \RuntimeException('Recovery journal has an invalid restoration decision; delivery remains held.');
        }
        $receipt['recovery_requires_restore'] = $requiresRestore;
        $this->record(array_replace($receipt, ['phase' => 'recovering']));
        if ($requiresRestore || true === ($receipt['services_stopped'] ?? false)) {
            $this->stopSourceServices();
            $this->fence->lock(SourceDatabaseFence::account($receipt['database_account']));
        }
        if (false === $requiresRestore) {
            // No DDL or private-state activation can have happened in these phases.
            $this->source->inspect(false);
        } else {
            $this->recovery->restore($directory, $this->connection, true);
        }
        $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
        $this->switchPointer($sourceRoot);
        $this->source->inspect(false !== $requiresRestore);
        $this->console($sourceRoot, ['about', '--no-interaction']);
        $leaseFile = EditionData::text($receipt['recovery_base']).'/scratch-owner.json';
        if (is_file($leaseFile)) {
            $scratch = $this->scratch();
            $lease = ['format' => 'emailer-edition-scratch-v1', 'installation_uuid' => $receipt['installation_uuid'], 'database' => $scratch->getDatabase(), 'target_package_sha256' => $receipt['target_package_sha256']];
            $scratch->close();
            if (EditionData::read($leaseFile) === $lease) {
                $this->clearRehearsalDatabase($receipt);
            }
        }
        $this->record(array_replace($receipt, ['phase' => 'recovered']));
        $this->fence->restore(SourceDatabaseFence::account($receipt['database_account']));
    }

    private function validateSourceConfiguration(string $sourceRoot): void
    {
        EditionRecoverySet::safeConfiguration($this->runtimeConfigurationFile);
        $normal = (new \Symfony\Component\Dotenv\Dotenv())->parse(EditionData::text(file_get_contents($this->runtimeConfigurationFile)));
        foreach (['EMAILER_PRIVATE_DIR' => $this->privateDirectory, 'EMAILER_SECRET_KEY_FILE' => $this->masterKeyFile, 'EMAILER_RELEASES_DIR' => $this->releasesDirectory] as $variable => $expected) {
            $canonical = 'EMAILER_SECRET_KEY_FILE' === $variable && !file_exists($expected) ? realpath(\dirname($expected)).'/'.basename($expected) : realpath($expected);
            if (($normal[$variable] ?? null) !== $expected || $canonical !== $expected || is_link($expected)) {
                throw new \RuntimeException('Source runtime configuration and selected installation paths disagree for '.$variable.'.');
            }
        }
        $sourcePublic = sodium_base642bin(trim(EditionData::text(file_get_contents($sourceRoot.'/release/emailer-light-release.pub'))), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $ripePublic = sodium_base642bin(trim(EditionData::text(file_get_contents($this->ripePublicKeyFile))), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        if (32 !== \strlen($sourcePublic) || 32 !== \strlen($ripePublic) || hash_equals($sourcePublic, $ripePublic)) {
            throw new \RuntimeException('The independently pinned Ripe signing identity must be distinct from the published Light signing identity.');
        }
        if ('prod' !== $this->environment) {
            return;
        }
        $journal = $this->receipt(false);
        $workerMode = $journal['source_worker_mode'] ?? $this->connection->fetchOne("SELECT worker_mode FROM delivery_policy WHERE singleton_key = '1'");
        if ('cron' === $workerMode) {
            return;
        }
        foreach ([$this->sourceServicePrefix.'-worker.service', $this->sourceServicePrefix.'-scheduler.service', $this->sourceServicePrefix.'-operations.service'] as $unit) {
            $properties = $this->readProcess(['systemctl', 'show', $unit, '--property=LoadState', '--property=WorkingDirectory', '--property=EnvironmentFiles', '--property=ExecStart']);
            if (!str_contains($properties, 'LoadState=loaded') || !str_contains($properties, 'WorkingDirectory='.$this->releasesDirectory.'/current') || !str_contains($properties, $this->runtimeConfigurationFile.' ') || !str_contains($properties, $this->releasesDirectory.'/current/bin/console')) {
                throw new \RuntimeException('A standard Light delivery service is absent or belongs to a different installation; service handling is refused.');
            }
        }
    }

    /** @param list<string> $command */
    private function readProcess(array $command): string
    {
        $pipes = [];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Read-only installation service inspection failed.');
        }
        $contents = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (0 !== proc_close($process) || false === $contents || 65536 < \strlen($contents)) {
            throw new \RuntimeException('Read-only installation service inspection failed.');
        }

        return $contents;
    }

    private function assertIsolatedPath(string $path): void
    {
        if (!str_starts_with($path, '/') || '/' === $path || str_ends_with($path, '/') || 1 === preg_match('~(^|/)\.\.?(/|$)|//|[\x00-\x1f]~', $path)) {
            throw new \RuntimeException('Recovery or export path must be an absolute normalized protected path.');
        }
        foreach ([$this->privateDirectory, $this->releasesDirectory, $this->projectDirectory, \dirname($this->masterKeyFile)] as $protected) {
            $resolved = realpath($protected);
            if (false === $resolved || $resolved !== $protected || str_starts_with($path.'/', $resolved.'/')) {
                throw new \RuntimeException('Recovery or export path overlaps protected installation state or application code.');
            }
        }
        for ($ancestor = $path; '/' !== $ancestor; $ancestor = \dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new \RuntimeException('Recovery or export path has a linked ancestor.');
            }
        }
    }

    private function sourceRoot(): string
    {
        $root = realpath($this->releasesDirectory.'/current');
        if (false === $root || \dirname($root) !== realpath($this->releasesDirectory)) {
            throw new \RuntimeException('Current release pointer has an unsafe or mismatched installation root.');
        }
        $journal = $this->receipt(false);
        if (isset($journal['source_root']) && \is_string($journal['source_root'])) {
            if (\dirname($journal['source_root']) !== realpath($this->releasesDirectory) || !\in_array(basename($journal['source_root']), LightSourcePackage::SUPPORTED_VERSIONS, true) || !\in_array($root, [$journal['source_root'], $this->releasesDirectory.'/ripe-1.3.0'], true)) {
                throw new \RuntimeException('Durable edition journal and current installation roots disagree.');
            }

            return $journal['source_root'];
        }
        if (!\in_array(basename($root), LightSourcePackage::SUPPORTED_VERSIONS, true)) {
            throw new \RuntimeException('Current release is not Light 1.0.0; edition downgrade or repeated conversion is prohibited.');
        }

        return $root;
    }

    private function verifyInstalled(string $root, string $archive): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('Authenticated archive cannot be read.');
        }
        try {
            $expected = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name || str_contains($name, '..') || str_starts_with($name, '/') || str_ends_with($name, '/')) {
                    throw new \RuntimeException('Authenticated archive contains unsafe entries.');
                }
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    throw new \RuntimeException('Authenticated entry cannot be read.');
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                $expected[$name] = hash_final($hash);
            }
            $seen = [];
            $rootStat = lstat($root);
            if ('prod' === $this->environment && (false === $rootStat || 0 !== $rootStat['uid'] || 0 !== ($rootStat['mode'] & 0o222))) {
                throw new \RuntimeException('Production source release is not root-owned and immutable.');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                    throw new \RuntimeException('Installed source contains unsafe files.');
                }
                $metadata = lstat($entry->getPathname());
                if ('prod' === $this->environment && (false === $metadata || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o222))) {
                    throw new \RuntimeException('Production source release is not root-owned and immutable.');
                }
                if ($entry->isDir()) {
                    continue;
                }
                if (!$entry->isFile()) {
                    throw new \RuntimeException('Installed source contains a non-regular object.');
                }
                $name = substr($entry->getPathname(), \strlen($root) + 1);
                $stat = lstat($entry->getPathname());
                if (false === $stat || 1 !== $stat['nlink'] || !isset($expected[$name]) || !hash_equals($expected[$name], (string) hash_file('sha256', $entry->getPathname()))) {
                    throw new \RuntimeException('Installed source differs from its authenticated archive.');
                }
                $seen[$name] = true;
            }
            if (\count($expected) !== \count($seen)) {
                throw new \RuntimeException('Installed source has missing files.');
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, array{columns: list<string>, digest: string, count: int}> */
    private function fingerprints(Connection $connection): array
    {
        $result = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $columns = EditionData::list($connection->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]));
            $result[$table] = ['columns' => $columns] + $this->tableFingerprint($connection, $table, $columns);
        }
        ksort($result);

        return $result;
    }

    /** @param list<string> $columns
     * @return array{digest: string, count: int}
     */
    private function tableFingerprint(Connection $connection, string $table, array $columns): array
    {
        $primary = EditionData::list($connection->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX", [$table]));
        if ([] === $primary) {
            throw new \RuntimeException('Preservation inventory requires stable primary keys.');
        }
        $key = hash_hkdf('sha256', (string) file_get_contents($this->masterKeyFile), 32, 'emailer-ripe/light-preservation-v1');
        $hash = hash_init('sha256', HASH_HMAC, $key);
        sodium_memzero($key);
        $count = 0;
        $sql = 'SELECT '.implode(',', array_map($connection->quoteSingleIdentifier(...), $columns)).' FROM '.$connection->quoteSingleIdentifier($table).' ORDER BY '.implode(',', array_map($connection->quoteSingleIdentifier(...), $primary));
        foreach ($connection->iterateAssociative($sql) as $row) {
            foreach ($row as &$value) {
                $value = EditionData::scalar($value);
                $value = null === $value ? null : base64_encode($value);
            }
            unset($value);
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
            ++$count;
        }

        return ['digest' => hash_final($hash), 'count' => $count];
    }

    /** @param array<string, array{columns: list<string>, digest: string, count: int}> $before */
    private function verifyMappedRows(array $before): void
    {
        $source = $this->scratch();
        foreach ($before as $table => $fingerprint) {
            if (\in_array($table, ['product_identity', 'doctrine_migration_versions'], true)) {
                continue;
            }
            $columns = array_values(array_diff($fingerprint['columns'], 'app_user' === $table ? ['singleton_key'] : []));
            if ($this->tableFingerprint($source, $table, $columns) !== $this->tableFingerprint($this->connection, $table, $columns)) {
                throw new \RuntimeException('Contract preservation comparison failed for '.$table.'.');
            }
        }
    }

    private function verifyTargetShape(): void
    {
        EditionIntegrity::assertForeignKeys($this->connection);
        $expected = EditionData::read($this->projectDirectory.'/config/upgrade/ripe-light-schema-1.0.6.json');
        if (EditionSchemaProof::capture($this->connection, true) !== ['tables' => EditionData::strings($expected['tables'] ?? null), 'triggers' => EditionData::strings($expected['triggers'] ?? null)]) {
            throw new \RuntimeException('Complete target edition schema, constraints, indexes or triggers differ from the verified baseline.');
        }
        $native = EditionData::read($this->projectDirectory.'/config/upgrade/ripe-native-schema-1.0.6.json');
        foreach (EditionData::strings($native['tables']) as $table => $ddl) {
            preg_match_all('/(?:\(|,)\s+`([^`]+)`\s+[a-z]/i', $ddl, $matches);
            if ([] === $matches[1]) {
                throw new \RuntimeException('Native target column proof is malformed.');
            }
            $actual = EditionData::list($this->connection->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]));
            if ([] !== array_diff($matches[1], $actual)) {
                throw new \RuntimeException('Target native schema structure is incomplete.');
            }
        }
        foreach (['contact', 'contact_attribute', 'contact_field_definition', 'contact_import', 'contact_import_candidate', 'contact_list', 'list_membership', 'campaign_list_selection', 'mfa_credential', 'password_reset_token'] as $table) {
            if (0 !== EditionData::integer($this->connection->fetchOne('SELECT COUNT(*) FROM '.$table))) {
                throw new \RuntimeException('Ripe-only structures must begin empty.');
            }
        }
        if (false !== $this->connection->fetchOne("SELECT 1 FROM app_user WHERE role <> 'ROLE_OWNER' OR active <> 1 OR disabled_at IS NOT NULL OR mfa_changed_at IS NOT NULL") || false !== $this->connection->fetchOne("SELECT 1 FROM organisation_settings WHERE security_level <> 'high'")) {
            throw new \RuntimeException('Owner, High or unconfigured MFA mapping failed.');
        }
    }

    private function scratch(): Connection
    {
        if ('' === $this->recoveryDatabaseUrl) {
            throw new \RuntimeException('A separately prepared isolated recovery database is required.');
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($this->recoveryDatabaseUrl);
        if (($parameters['dbname'] ?? null) !== $this->connection->getDatabase().'_edition_recovery') {
            throw new \RuntimeException('Recovery database must be the separately prepared edition_recovery schema.');
        }

        $sourceParameters = $this->connection->getParams();
        if (($parameters['host'] ?? null) !== ($sourceParameters['host'] ?? null) || ($parameters['port'] ?? 3306) !== ($sourceParameters['port'] ?? 3306)) {
            throw new \RuntimeException('Recovery rehearsal database must be on the verified source server.');
        }

        $name = EditionData::text($parameters['dbname']);
        if (false === $this->connection->fetchOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])) {
            $this->connection->executeStatement('CREATE DATABASE '.$this->connection->quoteSingleIdentifier($name).' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        }

        return DriverManager::getConnection($parameters);
    }

    private function updateRuntimeConfiguration(): void
    {
        if ($this->retainSourceFence || '' === $this->runtimeConfigurationFile) {
            return;
        }
        $bytes = (string) file_get_contents($this->runtimeConfigurationFile);
        $original = (new \Symfony\Component\Dotenv\Dotenv())->parse($bytes);
        if (($original['EMAILER_PRIVATE_DIR'] ?? null) !== $this->privateDirectory || ($original['EMAILER_RELEASES_DIR'] ?? null) !== $this->releasesDirectory || ($original['EMAILER_SECRET_KEY_FILE'] ?? null) !== $this->masterKeyFile) {
            throw new \RuntimeException('Protected source runtime configuration belongs to a different installation root.');
        }
        foreach (['EMAILER_RELEASE_PUBLIC_KEY_FILE' => $this->ripePublicKeyFile, 'EMAILER_RECOVERY_KEY_FILE' => $this->ripeRecoveryKeyFile] as $name => $path) {
            if (1 !== preg_match('#^/[A-Za-z0-9_./-]+$#D', $path) || !is_file($path) || is_link($path)) {
                throw new \RuntimeException('Independently selected Ripe trust or recovery path is unsafe.');
            }
            $line = $name.'='.$path;
            $count = 0;
            $bytes = preg_replace('/^'.preg_quote($name, '/').'=[^\r\n]*$/m', $line, $bytes, -1, $count) ?? $bytes;
            if ($count > 1) {
                throw new \RuntimeException('Protected runtime configuration has an ambiguous edition setting.');
            }
            if (0 === $count) {
                $bytes = rtrim($bytes)."\n".$line."\n";
            }
        }
        // Only the explicitly selected Ripe pins change. The complete original file is recoverable.
        EditionRecoverySet::write($this->runtimeConfigurationFile, $bytes, true);
    }

    private function stopSourceServices(): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        foreach ([$this->sourceServicePrefix.'-scheduler.timer', $this->sourceServicePrefix.'-operations.timer', $this->sourceServicePrefix.'-scheduler.service', $this->sourceServicePrefix.'-operations.service', $this->sourceServicePrefix.'-worker.service'] as $unit) {
            $state = $this->readProcess(['systemctl', 'show', $unit, '--property=LoadState']);
            if (str_contains($state, 'LoadState=not-found')) {
                continue;
            }
            $this->process(['systemctl', 'stop', $unit], [0]);
            $this->process(['systemctl', 'is-active', '--quiet', $unit], [3]);
        }
    }

    /**
     * @param list<string> $arguments
     * @param list<int>    $accepted
     */
    private function console(string $release, array $arguments, array $accepted = [0]): void
    {
        $receipt = $this->receipt();
        $runtime = EditionData::text($receipt['recovery_directory']).'/validation-'.hash('sha256', $release);
        EditionRecoverySet::directory($runtime);
        $log = $runtime.'/validation-'.str_replace(':', '-', $arguments[0]).'.log';
        $this->process(array_merge([PHP_BINARY, $release.'/bin/console'], $arguments), $accepted, LegacyDatabaseEnvironment::forRelease($release, ['DATABASE_URL' => EditionData::text(getenv('DATABASE_URL')), 'EMAILER_RUNTIME_DIR' => $runtime]), $log);
        if ('emailer:doctor:run' === $arguments[0]) {
            $report = EditionData::read($log);
            $findings = $report['findings'] ?? null;
            if (!\is_array($findings) || !array_is_list($findings)) {
                throw new \RuntimeException('Target Doctor verification report is malformed.');
            }
            // The contract requires unconfigured MFA. Doctor retains its critical finding;
            // only this offline edition validation recognizes the expected enrollment step.
            foreach ($findings as $finding) {
                $finding = EditionData::object($finding);
                $expectedMfa = 'owner_mfa' === ($finding['code'] ?? null) && false === ($finding['sending_blocked'] ?? null);
                $deferredMail = true === ($receipt['smtp_deferred'] ?? false) && 'sender_identity' === ($finding['code'] ?? null) && 0 === EditionData::integer($this->connection->fetchOne('SELECT COUNT(*) FROM sender_identity'));
                if ('critical' === ($finding['severity'] ?? null) && !$expectedMfa && !$deferredMail) {
                    throw new \RuntimeException('Target Doctor reported an unexpected critical readiness finding.');
                }
            }
        }
    }

    /**
     * @param list<string>          $command
     * @param list<int>             $accepted
     * @param array<string, string> $environmentOverrides
     */
    private function process(array $command, array $accepted, array $environmentOverrides = [], ?string $privateLog = null): void
    {
        if (null !== $privateLog) {
            EditionRecoverySet::write($privateLog, '');
            EditionRecoverySet::write($privateLog.'.stderr', '');
        }
        $pipes = [];
        $environment = array_replace(getenv(), $environmentOverrides);
        $process = proc_open(LegacyDatabaseEnvironment::command($command, $environment), [['file', '/dev/null', 'r'], ['file', $privateLog ?? '/dev/null', 'a'], ['file', null === $privateLog ? '/dev/null' : $privateLog.'.stderr', 'a']], $pipes, null, $environment);
        $exit = \is_resource($process) ? proc_close($process) : -1;
        if (!\in_array($exit, $accepted, true)) {
            $step = $command[2] ?? $command[0];
            throw new \RuntimeException('Edition process validation failed for '.basename($step).' (exit '.$exit.'). Diagnostics remain in the protected recovery set.');
        }
    }

    /** @return array<string, mixed> */
    private function receipt(bool $required = true): array
    {
        $path = '' === $this->operationReceiptFile ? $this->privateDirectory.'/edition-delivery-hold.json' : $this->operationReceiptFile;
        if ('' !== $this->operationReceiptFile) {
            EditionRecoverySet::directory(\dirname($path));
            if (file_exists($path)) {
                $metadata = lstat($path);
                if (!\is_array($metadata) || 0 !== $metadata['uid']) {
                    throw new \RuntimeException('The installer edition journal must be root owned.');
                }
            }
        }
        if (!$required && !file_exists($path)) {
            return [];
        }
        EditionRecoverySet::safe($path, false);
        $receipt = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($receipt)) {
            throw new \RuntimeException('Edition journal is malformed.');
        }

        return EditionData::object($receipt);
    }

    /** @param array<string, mixed> $receipt */
    private function record(array $receipt): void
    {
        if ('' !== $this->operationReceiptFile) {
            EditionRecoverySet::directory(\dirname($this->operationReceiptFile));
            EditionRecoverySet::write($this->operationReceiptFile, json_encode($receipt, JSON_THROW_ON_ERROR));
        }
        EditionRecoverySet::write($this->privateDirectory.'/edition-delivery-hold.json', json_encode($receipt, JSON_THROW_ON_ERROR));
        $directory = $receipt['recovery_directory'] ?? null;
        if (\is_string($directory) && is_dir($directory)) {
            if ('' !== $this->operationReceiptFile) {
                EditionRecoverySet::write($directory.'/transition-operation.json', json_encode($receipt, JSON_THROW_ON_ERROR));
            }
            $public = array_intersect_key($receipt, array_flip(['format', 'operation_id', 'installation_uuid', 'phase', 'target_package_sha256', 'invalidated_session_count', 'preservation_sha256']));
            $public['source_edition'] = 'emailer-light';
            $public['source_version'] = basename(EditionData::text($receipt['source_root']));
            $public['target_edition'] = 'emailer-ripe';
            $public['target_version'] = '1.3.0';
            EditionRecoverySet::write($directory.'/transition-receipt.json', json_encode($public, JSON_THROW_ON_ERROR));
        }
    }

    private function switchPointer(string $target): void
    {
        $temporary = $this->releasesDirectory.'/.edition-current-'.bin2hex(random_bytes(8));
        if (!symlink($target, $temporary) || !rename($temporary, $this->releasesDirectory.'/current')) {
            throw new \RuntimeException('Atomic edition code activation failed.');
        }
        $directory = fopen($this->releasesDirectory, 'r');
        if (false === $directory || !fsync($directory)) {
            throw new \RuntimeException('Edition code activation durability failed.');
        }
        fclose($directory);
    }
}
