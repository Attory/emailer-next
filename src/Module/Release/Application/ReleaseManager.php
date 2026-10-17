<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class ReleaseManager
{
    public function __construct(private PackageInspector $inspector, private BackupOperations $backups, private Connection $connection, private LockFactory $locks, private MaintenanceOperationLock $maintenance, private InterruptedDeliveryRecovery $recovery, private string $releasesDirectory, private string $currentVersion, private int $rollbackReserveBytes = 536870912, private string $environment = 'test', private ?EditionRecoverySet $completeRecovery = null, private string $privateDirectory = '', private string $masterKeyFile = '', private string $runtimeConfigurationFile = '', private ?\App\Module\Health\Application\Contract\RuntimeVerification $runtimeVerification = null, private bool $retainSourceFence = false, private string $replacementRuntimeFile = '')
    {
    }

    /** @return array<string, mixed> */
    public function inspect(string $archive): array
    {
        return $this->inspector->inspect($archive);
    }

    public function install(string $archive, bool $switch = false, bool $editionQualified = false): string
    {
        $manifest = $this->inspector->inspect($archive);
        $verifiedArchiveHash = $manifest['_verified_archive_sha256'] ?? null;
        if (!\is_string($verifiedArchiveHash)) {
            throw new \RuntimeException('Verified archive identity is unavailable.');
        }
        $version = $manifest['version'];
        if (!\is_string($version)) {
            throw new \RuntimeException('Release version is invalid.');
        }
        $files = $manifest['files'] ?? null;
        if (!\is_array($files)) {
            throw new \RuntimeException('Release file manifest is invalid.');
        }
        $files = $this->stringMap($files);
        $this->ensureReleaseRoot();
        $target = $this->releasesDirectory.'/'.($editionQualified ? 'ripe-' : '').$version;
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('The immutable release directory already exists.');
        }
        $staging = $this->releasesDirectory.'/.staging-'.Identity::uuidV7();
        $published = false;
        if (!mkdir($staging, 0o755)) {
            throw new \RuntimeException('Release staging cannot be created.');
        }
        try {
            $stagedArchive = $staging.'/.verified-package.zip';
            if (!copy($archive, $stagedArchive) || !chmod($stagedArchive, 0o600) || !hash_equals($verifiedArchiveHash, (string) hash_file('sha256', $stagedArchive))) {
                throw new \RuntimeException('Verified release cannot be staged without change.');
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($stagedArchive)) {
                throw new \RuntimeException('Verified release extraction failed.');
            }
            try {
                if (!$zip->extractTo($staging)) {
                    throw new \RuntimeException('Verified release extraction failed.');
                }
            } finally {
                $zip->close();
            }
            @unlink($stagedArchive);
            if (file_exists($stagedArchive) || is_link($stagedArchive)) {
                throw new \RuntimeException('Verified release staging cannot be removed.');
            }
            $this->assertExtracted($staging, $files);
            $this->freeze($staging);
            if (!rename($staging, $target)) {
                throw new \RuntimeException('Immutable release publication failed.');
            }
            $published = true;
            $this->assertPublishedOwnership($target);
            if ($switch) {
                $this->switchPointer($target);
            }

            return $target;
        } catch (\Throwable $exception) {
            $this->removeStaging($staging);
            if ($published) {
                $this->removeFailedRelease($target);
            }
            throw $exception;
        }
    }

    public function update(string $archive, string $sourceArchive): void
    {
        $this->maintenance->acquire();
        try {
            $this->updateWithLock($archive, $sourceArchive);
        } finally {
            $this->maintenance->release();
        }
    }

    private function updateWithLock(string $archive, string $sourceArchive): void
    {
        $lock = $this->locks->createLock('emailer-release-update', 900.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another release operation is active.');
        }
        $operationId = null;
        $safety = null;
        $previous = false;
        $pointerSwitched = false;
        $migrationApplied = false;
        $target = null;
        $manifest = [];
        $sourceSchemaFingerprint = null;
        $completeCheckpoint = null;
        $nativeJournal = null;
        $databaseLockAcquired = false;
        try {
            if (1 !== EditionData::integer($this->connection->fetchOne('SELECT GET_LOCK(?,0)', [$this->nativeLockName()]))) {
                throw new \RuntimeException('Another database-scoped native release operation is active.');
            }
            $databaseLockAcquired = true;
            $this->assertNativeOperationOwnership();
            $lastUpdate = $this->connection->fetchAssociative("SELECT id, status, detail_code FROM release_operation WHERE operation = 'update' ORDER BY started_at DESC, id DESC LIMIT 1");
            if (false !== $this->connection->fetchOne("SELECT 1 FROM release_operation WHERE status = 'started' LIMIT 1") || false !== $lastUpdate && 'failed' === $lastUpdate['status'] && 'rollback_failed' === $lastUpdate['detail_code']) {
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
                throw new \RuntimeException('An unfinished release operation requires supervised recovery from its retained checkpoint before another update. Delivery must remain quiesced.');
            }
            $previous = realpath($this->releasesDirectory.'/current');
            if (false === $previous || !is_dir($previous) || !\in_array(basename($previous), [$this->currentVersion, 'ripe-'.$this->currentVersion], true)) {
                throw new \RuntimeException('Recorded update source does not match the immutable current release.');
            }
            $this->assertPublishedOwnership($previous);
            $sourceManifest = $this->inspector->inspectInstalledSource($sourceArchive);
            $this->assertInstalledReleaseMatchesManifest($previous, $sourceManifest);
            $manifest = $this->inspector->inspect($archive);
            $targetVersion = $manifest['version'] ?? null;
            if (!\is_string($targetVersion) || version_compare($targetVersion, $this->currentVersion, '<=')) {
                throw new \RuntimeException('Release downgrade or same-version update is not allowed.');
            }
            $this->assertPlatformAndSchemaCompatibility($manifest);
            $this->assertDiskCapacity($archive, $manifest);
            $sourceSchemaFingerprint = $this->schemaFingerprint();
            $operationId = Identity::uuidV7();
            if ($this->completeNativePair($this->currentVersion, $targetVersion) && null !== $this->completeRecovery) {
                if ('' === $this->privateDirectory || '' === $this->masterKeyFile) {
                    throw new \RuntimeException('Native update requires complete recovery configuration.');
                }
                $completeCheckpoint = \dirname($this->privateDirectory).'/native-update-recovery/'.$operationId;
                EditionRecoverySet::directory($completeCheckpoint);
                $nativeJournal = ['format' => 'emailer-native-update-recovery-v1', 'operation_id' => $operationId, 'previous_operation_id' => false === $lastUpdate ? null : $lastUpdate['id'], 'phase' => 'preparing', 'retain_source_fence' => $this->retainSourceFence, 'database_binding' => $this->nativeDatabaseBinding(), 'private_directory' => $this->privateDirectory, 'source_root' => $previous, 'source_version' => $this->currentVersion, 'target_version' => $targetVersion, 'target_archive_sha256' => hash_file('sha256', $archive), 'source_archive_sha256' => hash_file('sha256', $sourceArchive), 'source_schema_fingerprint' => $sourceSchemaFingerprint, 'source_master_sha256' => hash_file('sha256', $this->masterKeyFile), 'installation_uuid' => $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'"), 'database_account' => (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->inspect()];
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
            }
            $this->connection->insert('release_operation', ['id' => $operationId, 'operation' => 'update', 'source_version' => $this->currentVersion, 'target_version' => $manifest['version'], 'package_sha256' => hash_file('sha256', $archive), 'status' => 'started', 'detail_code' => 'verified', 'started_at' => Identity::now(), 'finished_at' => null]);
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, updated_at = ? WHERE singleton_key = '1'", [Identity::now()]);
            $this->recovery->recover();
            $deadline = microtime(true) + 30.0;
            while (false !== $this->connection->fetchOne("SELECT 1 FROM delivery_runtime WHERE singleton_key = '1' AND active_claims > 0")) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Active delivery claims did not quiesce within the safe deadline.');
                }
                usleep(250000);
            }
            if (null !== $nativeJournal) {
                (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->lock(SourceDatabaseFence::account($nativeJournal['database_account']));
            }
            $safety = $this->backups->create();
            $this->transferSafetyBackupToServiceOwner($safety['path']);
            if ($this->completeNativePair($this->currentVersion, $targetVersion) && null !== $this->completeRecovery) {
                if ('' === $this->privateDirectory || '' === $this->masterKeyFile) {
                    throw new \RuntimeException('Native update requires complete private-state recovery configuration.');
                }
                $roots = ['private' => $this->privateDirectory, 'master_key' => $this->masterKeyFile];
                if ('' !== $this->runtimeConfigurationFile) {
                    $roots['runtime_configuration'] = $this->runtimeConfigurationFile;
                    $runtime = EditionData::strings((new \Symfony\Component\Dotenv\Dotenv())->parse(EditionData::text(file_get_contents($this->runtimeConfigurationFile))));
                    if (isset($runtime['EMAILER_RECOVERY_KEY_FILE']) && is_file($runtime['EMAILER_RECOVERY_KEY_FILE'])) {
                        $roots['ripe_recovery_key'] = $runtime['EMAILER_RECOVERY_KEY_FILE'];
                    }
                }
                $this->completeRecovery->create(EditionData::text($completeCheckpoint), $roots);
                $this->completeRecovery->verify(EditionData::text($completeCheckpoint));
                $nativeJournal['source_data_fingerprint'] = $this->nativeDataFingerprint(EditionData::text($completeCheckpoint));
                $nativeJournal['phase'] = 'checkpointed';
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
            }
            $target = $this->install($archive, false);
            if (null !== $nativeJournal) {
                $nativeJournal['phase'] = 'migrating';
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
            }
            $this->runConsole($target, ['doctrine:migrations:migrate', '--no-interaction'], [0]);
            $migrationApplied = true;
            $this->switchPointer($target);
            $pointerSwitched = true;
            if (null !== $nativeJournal) {
                $nativeJournal['phase'] = 'activated';
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
            }
            if (version_compare($targetVersion, '1.1.0', '<')) {
                $this->runConsole($target, ['emailer:doctor:run', '--json', '--maintenance-verification'], [0, 1]);
            } else {
                $this->runConsole($target, ['emailer:health:verify-runtime', '--offline-maintenance'], [0]);
            }
            if (\in_array($targetVersion, ['1.1.0', '1.2.0'], true) && null !== $nativeJournal) {
                $this->synchronizeNativeIdentity($targetVersion);
            }
            $this->connection->update('release_operation', ['status' => 'succeeded', 'detail_code' => 'health_passed', 'finished_at' => Identity::now()], ['id' => $operationId]);
            if (null !== $nativeJournal) {
                $nativeJournal['phase'] = 'completed';
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
                if (!$this->retainSourceFence) {
                    (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->restore(SourceDatabaseFence::account($nativeJournal['database_account'] ?? null));
                    $nativeJournal['fence_restored'] = true;
                    $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
                }
            }
        } catch (\Throwable $exception) {
            // Once the durable database outcome is success, never restore its old
            // checkpoint. A later rerun may only finish reopening this account.
            if (null !== $operationId && 'succeeded' === $this->connection->fetchOne('SELECT status FROM release_operation WHERE id=?', [$operationId])) {
                throw new \RuntimeException('The update succeeded, but its final account-restoration receipt is incomplete. Rerun setup to finish completion; recovery cannot downgrade it.', previous: $exception);
            }
            if (null !== $safety) {
                try {
                    if (null !== $nativeJournal) {
                        (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->lock(SourceDatabaseFence::account($nativeJournal['database_account'] ?? null));
                    }
                    if (null !== $nativeJournal && 'preparing' === $nativeJournal['phase']) {
                        // No staging/migration/private activation can precede the
                        // durable checkpointed phase. Do not introduce a data
                        // restore merely because backup preparation failed.
                        if (null === $sourceSchemaFingerprint || !hash_equals($sourceSchemaFingerprint, $this->schemaFingerprint()) || !hash_equals(EditionData::text($nativeJournal['source_master_sha256']), EditionData::text(hash_file('sha256', $this->masterKeyFile)))) {
                            throw new \RuntimeException('Uncheckpointed native source no longer matches its preparation proof.');
                        }
                    } elseif (null !== $completeCheckpoint && null !== $this->completeRecovery && null !== $nativeJournal) {
                        $this->completeRecovery->restore($completeCheckpoint, $this->connection, true);
                        $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
                        if (null === $sourceSchemaFingerprint || !hash_equals($sourceSchemaFingerprint, $this->schemaFingerprint())) {
                            throw new \RuntimeException('Complete native source schema verification failed after recovery.');
                        }
                        if (!hash_equals(EditionData::text($nativeJournal['source_data_fingerprint'] ?? null), $this->nativeDataFingerprint(EditionData::text($completeCheckpoint)))) {
                            throw new \RuntimeException('Complete native source data verification failed after recovery.');
                        }
                        if (false !== $previous) {
                            $this->switchPointer($previous);
                        }
                    } elseif ($pointerSwitched && $migrationApplied && true === ($manifest['rollback_schema_compatible'] ?? false) && false !== $previous) {
                        $this->switchPointer($previous);
                    } else {
                        $this->backups->restore($safety['path']);
                        if (null === $sourceSchemaFingerprint || !hash_equals($sourceSchemaFingerprint, $this->schemaFingerprint())) {
                            throw new \RuntimeException('Safety backup restored data but not the exact source schema; supervised schema recovery is required.');
                        }
                        if (false !== $previous) {
                            $this->switchPointer($previous);
                        }
                    }
                } catch (\Throwable $rollback) {
                    if (null !== $operationId) {
                        $this->recordFailedOperation($operationId, $archive, $manifest, 'rollback_failed');
                    }
                    throw new \RuntimeException('Update failed and automatic rollback failed; delivery remains quiesced.', previous: $rollback);
                }
            }
            if (null !== $operationId) {
                $this->recordFailedOperation($operationId, $archive, $manifest, 'update_failed');
            }
            if (null !== $nativeJournal) {
                $nativeJournal['phase'] = 'recovered';
                $this->nativeJournal(EditionData::text($completeCheckpoint), $nativeJournal);
                (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->restore(SourceDatabaseFence::account($nativeJournal['database_account'] ?? null));
            }
            if (null !== $target) {
                $this->removeFailedRelease($target);
            }
            throw $exception;
        } finally {
            try {
                if ($databaseLockAcquired) {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->nativeLockName()]);
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function synchronizeNativeIdentity(string $version): void
    {
        $path = $this->privateDirectory.'/install.lock';
        EditionRecoverySet::safe($path, false);
        $lock = EditionData::read($path);
        $uuid = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (($lock['installation_uuid'] ?? null) !== $uuid || (isset($lock['product_id']) && 'emailer-ripe' !== $lock['product_id'])) {
            throw new \RuntimeException('Native installation identity is inconsistent.');
        }
        $recorded = $lock['product_version'] ?? null;
        $legacy = '1.0.6' === $this->currentVersion && '1.0.5' === $recorded && false !== $this->connection->fetchOne("SELECT 1 FROM release_operation WHERE source_version='1.0.5' AND target_version='1.0.6' AND status='succeeded' LIMIT 1");
        if (null !== $recorded && $this->currentVersion !== $recorded && !$legacy) {
            throw new \RuntimeException('Native installation version disagrees with its authenticated source.');
        }
        $lock['product_id'] = 'emailer-ripe';
        $lock['product_version'] = $version;
        $lock['schema_version'] = '20261015000000';
        EditionRecoverySet::write($path, json_encode($lock, JSON_THROW_ON_ERROR), true);
        if ($this->connection->createSchemaManager()->tablesExist(['product_identity'])) {
            $this->connection->update('product_identity', ['product_version' => $version], ['singleton_key' => '1', 'product_id' => 'emailer-ripe', 'installation_uuid' => $uuid]);
        }
    }

    public function finishCommittedNativeUpdate(string $operationId, string $archive, string $sourceArchive): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $operationId)) {
            throw new \RuntimeException('The retained native operation identifier is invalid.');
        }
        $this->maintenance->acquire();
        $locked = false;
        try {
            if (1 !== EditionData::integer($this->connection->fetchOne('SELECT GET_LOCK(?,0)', [$this->nativeLockName()]))) {
                throw new \RuntimeException('Another native operation is active.');
            }
            $locked = true;
            $directory = \dirname($this->privateDirectory).'/native-update-recovery/'.$operationId;
            $journal = EditionData::read($directory.'/native-operation.json');
            $manifest = $this->inspector->inspect($archive);
            $this->inspector->inspectInstalledSource($sourceArchive);
            $target = EditionData::text($manifest['version'] ?? null);
            $latest = $this->connection->fetchAssociative("SELECT id,status FROM release_operation WHERE operation='update' ORDER BY started_at DESC,id DESC LIMIT 1");
            if (false === $latest || $latest['id'] !== $operationId || 'succeeded' !== $latest['status'] || !$this->completeNativePair($this->currentVersion, $target) || ($journal['operation_id'] ?? null) !== $operationId || ($journal['source_version'] ?? null) !== $this->currentVersion || ($journal['target_version'] ?? null) !== $target || ($journal['database_binding'] ?? null) !== $this->nativeDatabaseBinding() || ($journal['private_directory'] ?? null) !== $this->privateDirectory || ($journal['target_archive_sha256'] ?? null) !== hash_file('sha256', $archive) || ($journal['source_archive_sha256'] ?? null) !== hash_file('sha256', $sourceArchive) || realpath($this->releasesDirectory.'/current') !== $this->releasesDirectory.'/'.$target) {
                throw new \RuntimeException('Only the exact latest committed update can finish restoring its recorded account.');
            }
            $this->assertInstalledReleaseMatchesManifest($this->releasesDirectory.'/'.$target, $manifest, $target);
            if (true === ($journal['retain_source_fence'] ?? false) && true === ($journal['fence_restored'] ?? false) && ($journal['writer_replacement'] ?? null) !== (new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile))->replacement(SourceDatabaseFence::account($journal['database_account'] ?? null))) {
                throw new \RuntimeException('The completed writer handoff has drifted. Keep delivery held and investigate.');
            }
            if (true !== ($journal['fence_restored'] ?? false)) {
                if (($journal['source_master_sha256'] ?? null) !== hash_file('sha256', $this->masterKeyFile) || ($journal['installation_uuid'] ?? null) !== $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'")) {
                    throw new \RuntimeException('Committed-update key or installation identity has drifted.');
                }
                $fence = new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile);
                if (true === ($journal['retain_source_fence'] ?? false)) {
                    $journal['writer_replacement'] = $fence->replacement(SourceDatabaseFence::account($journal['database_account'] ?? null));
                } else {
                    $fence->restore(SourceDatabaseFence::account($journal['database_account'] ?? null));
                }
                $journal['phase'] = 'completed';
                $journal['fence_restored'] = true;
                $this->nativeJournal($directory, $journal);
            }
        } finally {
            if ($locked) {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->nativeLockName()]);
            }
            $this->maintenance->release();
        }
    }

    public function recoverNativeUpdate(string $operationId, string $archive, string $sourceArchive): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $operationId) || !\in_array($this->currentVersion, ['1.0.5', '1.0.6', '1.1.0'], true) || null === $this->completeRecovery) {
            throw new \RuntimeException('Native recovery requires the recorded supported source launcher and exact operation UUID.');
        }
        $directory = \dirname($this->privateDirectory).'/native-update-recovery/'.$operationId;
        EditionRecoverySet::safe($directory, true);
        EditionRecoverySet::safe($directory.'/native-operation.json', false);
        $this->maintenance->acquire();
        $lock = $this->locks->createLock('emailer-release-update', 900.0);
        $databaseLockAcquired = false;
        try {
            if (!$lock->acquire()) {
                throw new \RuntimeException('Another release operation is active.');
            }
            if (1 !== EditionData::integer($this->connection->fetchOne('SELECT GET_LOCK(?,0)', [$this->nativeLockName()]))) {
                throw new \RuntimeException('Another database-scoped native release operation is active.');
            }
            $databaseLockAcquired = true;
            $journal = EditionData::read($directory.'/native-operation.json');
            $manifest = $this->inspector->inspect($archive);
            $sourceManifest = $this->inspector->inspectInstalledSource($sourceArchive);
            $source = NativeReleaseRoot::source($this->releasesDirectory, $this->currentVersion, $journal['source_root'] ?? null);
            $targetVersion = EditionData::text($manifest['version'] ?? null);
            if ('emailer-native-update-recovery-v1' !== ($journal['format'] ?? null) || $operationId !== ($journal['operation_id'] ?? null) || !$this->completeNativePair($this->currentVersion, $targetVersion) || $targetVersion !== ($journal['target_version'] ?? null) || $this->currentVersion !== ($journal['source_version'] ?? null) || $this->privateDirectory !== ($journal['private_directory'] ?? null) || $source !== ($journal['source_root'] ?? null) || $this->nativeDatabaseBinding() !== ($journal['database_binding'] ?? null) || !hash_equals(EditionData::text($journal['target_archive_sha256']), EditionData::text(hash_file('sha256', $archive))) || !hash_equals(EditionData::text($journal['source_archive_sha256']), EditionData::text(hash_file('sha256', $sourceArchive)))) {
                throw new \RuntimeException('Native recovery identity, installation or authenticated package does not match.');
            }
            $this->assertPublishedOwnership($source);
            $this->assertInstalledReleaseMatchesManifest($source, $sourceManifest);
            $current = realpath($this->releasesDirectory.'/current');
            if (!\in_array($current, [$source, $this->releasesDirectory.'/'.$targetVersion], true) || 'completed' === ($journal['phase'] ?? null)) {
                throw new \RuntimeException('Completed native updates cannot be downgraded by recovery.');
            }
            $live = $this->connection->createSchemaManager()->tablesExist(['release_operation']) ? $this->connection->fetchAssociative('SELECT status,detail_code FROM release_operation WHERE id=?', [$operationId]) : false;
            $this->assertNativeOperationOwnership($operationId);
            $latest = $this->connection->createSchemaManager()->tablesExist(['release_operation']) ? $this->connection->fetchOne("SELECT id FROM release_operation WHERE operation='update' ORDER BY started_at DESC,id DESC LIMIT 1") : false;
            $journalOnly = false === $live && 'preparing' === ($journal['phase'] ?? null) && (false === $latest ? null : $latest) === ($journal['previous_operation_id'] ?? null);
            if (false !== $latest && $latest !== $operationId && !$journalOnly) {
                throw new \RuntimeException('Native recovery must select the latest operation; later update history cannot be rolled back.');
            }
            if (false !== $live && 'succeeded' === $live['status']) {
                throw new \RuntimeException('A successful native update cannot be recovered as a downgrade.');
            }
            $phase = EditionData::text($journal['phase']);
            if (!\in_array($phase, ['preparing', 'checkpointed', 'migrating', 'activated', 'recovering', 'recovered'], true)) {
                throw new \RuntimeException('Native recovery journal phase is unsupported.');
            }
            $fence = new SourceDatabaseFence($this->connection, $this->runtimeConfigurationFile, $this->replacementRuntimeFile);
            $account = SourceDatabaseFence::account($journal['database_account'] ?? null);
            $runtimeExists = $this->connection->createSchemaManager()->tablesExist(['delivery_runtime']);
            if ($runtimeExists) {
                if (0 !== EditionData::integer($this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'"))) {
                    throw new \RuntimeException('Active claims prevent native recovery; no claim is reset.');
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            } elseif ('recovering' !== $phase) {
                throw new \RuntimeException('Native runtime cannot establish safe recovery quiescence.');
            }
            if ('recovered' === $phase && false !== $live && 'failed' === $live['status'] && 'rollback_failed' !== $live['detail_code'] && $current === $source && hash_equals(EditionData::text($journal['source_schema_fingerprint']), $this->schemaFingerprint()) && is_file($this->masterKeyFile) && hash_equals(EditionData::text($journal['source_master_sha256']), EditionData::text(hash_file('sha256', $this->masterKeyFile))) && $journal['installation_uuid'] === $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'")) {
                if (true !== ($journal['fence_restored'] ?? false)) {
                    $fence->restore($account);
                    $journal['fence_restored'] = true;
                    $this->nativeJournal($directory, $journal);
                }

                return;
            }
            if ('recovered' === $phase) {
                throw new \RuntimeException('A completed native recovery has drifted; its old checkpoint cannot overwrite subsequent source state.');
            }
            $fence->lock($account);
            $restore = 'recovering' === $phase ? ($journal['restore_required'] ?? true) : isset($journal['source_data_fingerprint']);
            if (!\is_bool($restore)) {
                throw new \RuntimeException('Native restoration decision is malformed.');
            }
            $journal['restore_required'] = $restore;
            $journal['phase'] = 'recovering';
            $this->nativeJournal($directory, $journal);
            if ($restore) {
                $checkpoint = $this->completeRecovery->verify($directory);
                if (($checkpoint['roots']['private'] ?? null) !== $this->privateDirectory || ($checkpoint['roots']['master_key'] ?? null) !== $this->masterKeyFile) {
                    throw new \RuntimeException('Native checkpoint private roots disagree with this installation.');
                }
                $this->completeRecovery->restore($directory, $this->connection, true);
                if (!hash_equals(EditionData::text($journal['source_data_fingerprint']), $this->nativeDataFingerprint($directory))) {
                    throw new \RuntimeException('Restored native data fingerprints differ from the original checkpoint.');
                }
            } elseif ($current !== $source) {
                throw new \RuntimeException('Uncheckpointed native recovery cannot switch an activated release.');
            }
            if (!hash_equals(EditionData::text($journal['source_schema_fingerprint']), $this->schemaFingerprint()) || !hash_equals(EditionData::text($journal['source_master_sha256']), EditionData::text(hash_file('sha256', $this->masterKeyFile))) || $journal['installation_uuid'] !== $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'")) {
                throw new \RuntimeException('Native source schema, key or installation recovery proof failed.');
            }
            EditionIntegrity::assertForeignKeys($this->connection);
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            $this->switchPointer($source);
            $runtime = $directory.'/source-validation';
            EditionRecoverySet::directory($runtime);
            $this->runConsole($source, ['about', '--no-interaction'], [0], $runtime);
            $this->runConsole($source, ['doctrine:schema:validate', '--skip-sync', '--no-interaction'], [0], $runtime);
            if ('1.0.5' === $this->currentVersion) {
                $this->runConsole($source, ['emailer:doctor:run', '--json', '--maintenance-verification'], [0, 1], $runtime);
            } else {
                // The authenticated target contains the structural verifier; the
                // predecessor schema is identical for this pair.
                if (null === $this->runtimeVerification || true !== $this->runtimeVerification->verifyRuntime(true)['runtime_verified']) {
                    throw new \RuntimeException('Recovered source failed structural runtime verification.');
                }
            }
            if (is_dir($this->releasesDirectory.'/'.$targetVersion)) {
                $this->assertInstalledReleaseMatchesManifest($this->releasesDirectory.'/'.$targetVersion, $manifest, $targetVersion);
                $this->removeFailedRelease($this->releasesDirectory.'/'.$targetVersion);
            }
            $this->recordFailedOperation($operationId, $archive, $manifest, $restore ? 'verified_source_recovered' : 'interrupted_before_mutation');
            $journal['phase'] = 'recovered';
            $this->nativeJournal($directory, $journal);
            $fence->restore($account);
            $journal['fence_restored'] = true;
            $this->nativeJournal($directory, $journal);
        } catch (\Throwable $failure) {
            EditionRecoverySet::write($directory.'/native-recovery-failure.json', json_encode(['exception_class' => $failure::class, 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR));
            throw new \RuntimeException('Native recovery did not complete. Keep delivery and source/target processes quiesced; protected operation diagnostics identify the failed proof.');
        } finally {
            try {
                if ($databaseLockAcquired) {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->nativeLockName()]);
                }
            } finally {
                $lock->release();
                $this->maintenance->release();
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function nativeJournal(string $directory, array $journal): void
    {
        EditionRecoverySet::write($directory.'/native-operation.json', json_encode($journal, JSON_THROW_ON_ERROR));
    }

    private function completeNativePair(string $source, string $target): bool
    {
        return ('1.0.5' === $source && '1.0.6' === $target) || ('1.0.6' === $source && '1.1.0' === $target) || (\in_array($source, ['1.0.6', '1.1.0'], true) && '1.2.0' === $target);
    }

    private function nativeDatabaseBinding(): string
    {
        $p = $this->connection->getParams();

        return hash('sha256', serialize([$p['host'] ?? '', $p['port'] ?? 3306, $p['unix_socket'] ?? '', $this->connection->getDatabase()]));
    }

    private function nativeLockName(): string
    {
        return 'emailer-native-release-'.substr(hash('sha256', EditionData::text($this->connection->getDatabase())), 0, 40);
    }

    private function assertNativeOperationOwnership(?string $selected = null): void
    {
        if (!\in_array($this->currentVersion, ['1.0.5', '1.0.6', '1.1.0'], true) || null === $this->completeRecovery || '' === $this->privateDirectory) {
            return;
        }
        $files = glob(\dirname($this->privateDirectory).'/native-update-recovery/*/native-operation.json');
        if (false === $files) {
            throw new \RuntimeException('Native operation custody cannot be inspected.');
        }
        foreach ($files as $file) {
            EditionRecoverySet::safe($file, false);
            $other = EditionData::read($file);
            if (($other['private_directory'] ?? null) !== $this->privateDirectory || ($other['database_binding'] ?? null) !== $this->nativeDatabaseBinding() || 'emailer-native-update-recovery-v1' !== ($other['format'] ?? null)) {
                throw new \RuntimeException('Retained native operation belongs to a different installation or database.');
            }
            if (($other['operation_id'] ?? null) === $selected) {
                continue;
            }
            if (!\in_array($other['phase'] ?? null, ['completed', 'recovered'], true)) {
                if ($this->connection->createSchemaManager()->tablesExist(['delivery_runtime'])) {
                    $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
                }
                throw new \RuntimeException('Another retained native operation owns recovery.');
            }
            if (null !== $selected && ($other['previous_operation_id'] ?? null) === $selected) {
                throw new \RuntimeException('Another retained native operation owns recovery, or a later operation supersedes the selected checkpoint.');
            }
        }
    }

    private function nativeDataFingerprint(string $directory): string
    {
        EditionRecoverySet::safe($directory.'/recovery.key', false);
        $checkpointKey = EditionData::text(file_get_contents($directory.'/recovery.key'));
        $key = hash_hkdf('sha256', $checkpointKey, 32, 'emailer-ripe/native-update-data-fingerprint-v1');
        sodium_memzero($checkpointKey);
        $hash = hash_init('sha256', HASH_HMAC, $key);
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $primary = $this->connection->createSchemaManager()->introspectTable($table)->getPrimaryKey();
            if (null === $primary) {
                throw new \RuntimeException('Native checkpoint table has no stable identity.');
            }
            hash_update($hash, $table."\0");
            $order = array_map($this->connection->quoteSingleIdentifier(...), $primary->getColumns());
            foreach ($this->connection->iterateAssociative('SELECT * FROM '.$this->connection->quoteSingleIdentifier($table).' ORDER BY '.implode(',', $order)) as $row) {
                $values = [];
                foreach ($row as $column => $value) {
                    if (null !== $value && !\is_scalar($value)) {
                        throw new \RuntimeException('Native recovery value cannot be fingerprinted safely.');
                    }
                    $values[$column] = null === $value ? null : (string) $value;
                }
                hash_update($hash, serialize($values));
            }
        }
        sodium_memzero($key);

        return hash_final($hash);
    }

    private function schemaFingerprint(): string
    {
        // Application backups restore rows and assets, not MariaDB DDL or its migration ledger.
        // Never switch older code back merely because that data restore succeeded.
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);
        $definitions = [];
        foreach ($tables as $table) {
            $definition = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteSingleIdentifier($table));
            if (false === $definition) {
                throw new \RuntimeException('Database schema recovery verification is unavailable.');
            }
            foreach ($definition as $name => $value) {
                if (!\is_string($value)) {
                    throw new \RuntimeException('Database schema recovery verification is invalid.');
                }
                // Restoring rows changes AUTO_INCREMENT counters, not the table definition.
                $definition[$name] = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $value);
            }
            $definitions[$table] = $definition;
        }
        $versions = $this->connection->fetchAllAssociative('SELECT version, executed_at, execution_time FROM doctrine_migration_versions ORDER BY version');

        return hash('sha256', serialize([$definitions, $versions]));
    }

    /** @param array<string, mixed> $manifest */
    private function recordFailedOperation(string $id, string $archive, array $manifest, string $detail): void
    {
        $target = \is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
        $hash = hash_file('sha256', $archive);
        $this->connection->executeStatement("INSERT INTO release_operation (id, operation, source_version, target_version, package_sha256, status, detail_code, started_at, finished_at) VALUES (?, 'update', ?, ?, ?, 'failed', ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'failed', detail_code = VALUES(detail_code), finished_at = VALUES(finished_at)", [$id, $this->currentVersion, $target, false === $hash ? null : $hash, $detail, Identity::now(), Identity::now()]);
    }

    /** @param array<string, mixed> $manifest */
    private function assertPlatformAndSchemaCompatibility(array $manifest): void
    {
        $minimumSchema = $manifest['minimum_schema_version'] ?? null;
        $targetSchema = $manifest['schema_version'] ?? null;
        $currentSchema = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
        $currentSchema = \is_string($currentSchema) ? substr($currentSchema, -14) : null;
        if (!\is_string($minimumSchema) || !\is_string($targetSchema) || !\is_string($currentSchema) || 1 !== preg_match('/^[0-9]{14}$/', $minimumSchema) || 1 !== preg_match('/^[0-9]{14}$/', $targetSchema) || $currentSchema < $minimumSchema || $targetSchema < $currentSchema) {
            throw new \RuntimeException('Release database schema compatibility check failed.');
        }
        $platform = $manifest['platform'] ?? null;
        $mariaDb = \is_array($platform) ? ($platform['mariadb'] ?? null) : null;
        $server = $this->connection->fetchOne('SELECT VERSION()');
        if (!\is_array($mariaDb) || ['10.11', '11.8'] !== array_values($mariaDb) || !\is_string($server)) {
            throw new \RuntimeException('Release database platform compatibility is unavailable.');
        }
        if (1 !== preg_match('/^(\d+\.\d+)/', $server, $matches) || !\in_array($matches[1], $mariaDb, true)) {
            throw new \RuntimeException('Release MariaDB compatibility check failed.');
        }
    }

    /** @param array<string, string> $files */
    private function assertExtracted(string $root, array $files): void
    {
        foreach ($files as $name => $digest) {
            $path = $root.'/'.$name;
            $stat = @lstat($path);
            if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || !hash_equals($digest, (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('Extracted release verification failed.');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertInstalledReleaseMatchesManifest(string $release, array $manifest, ?string $expectedVersion = null): void
    {
        $version = $manifest['version'] ?? null;
        $files = $manifest['files'] ?? null;
        if (!\is_string($version) || !hash_equals($expectedVersion ?? $this->currentVersion, $version) || !\is_array($files)) {
            throw new \RuntimeException('Signed predecessor package identity does not match the recorded update source.');
        }
        $expected = $this->stringMap($files);
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Installed predecessor differs from its signed package.');
            }
            if ($entry->isDir()) {
                continue;
            }
            $path = $entry->getPathname();
            $relative = substr($path, \strlen(rtrim($release, '/')) + 1);
            $metadata = @lstat($path);
            $digest = hash_file('sha256', $path);
            if (!\is_array($metadata)
                || 0o100000 !== ($metadata['mode'] & 0o170000)
                || 1 !== $metadata['nlink']
                || !\is_string($digest)
                || !\is_string($expected[$relative] ?? null)
                || !hash_equals($expected[$relative], $digest)
            ) {
                throw new \RuntimeException('Installed predecessor differs from its signed package.');
            }
            $seen[$relative] = true;
        }
        if (\count($seen) !== \count($expected) || [] !== array_diff_key($expected, $seen)) {
            throw new \RuntimeException('Installed predecessor differs from its signed package.');
        }
    }

    private function switchPointer(string $target): void
    {
        $temporary = $this->releasesDirectory.'/.current-'.Identity::uuidV7();
        if (!symlink($target, $temporary) || !rename($temporary, $this->releasesDirectory.'/current')) {
            @unlink($temporary);
            throw new \RuntimeException('Atomic release pointer switch failed.');
        }
    }

    /** @param list<string> $arguments
     * @param list<int> $acceptedStatuses
     */
    private function runConsole(string $release, array $arguments, array $acceptedStatuses, string $runtime = ''): void
    {
        $command = array_merge([PHP_BINARY, $release.'/bin/console'], $arguments);
        $pipes = [];
        $environment = LegacyDatabaseEnvironment::forRelease($release, '' === $runtime ? getenv() : array_replace(getenv(), ['EMAILER_RUNTIME_DIR' => $runtime]));
        $process = proc_open(LegacyDatabaseEnvironment::command($command, $environment), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $release, $environment);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Release command could not start.');
        }
        $status = proc_close($process);
        if (!\in_array($status, $acceptedStatuses, true)) {
            throw new \RuntimeException('Release command failed with redacted status '.$status.'.');
        }
    }

    private function ensureReleaseRoot(): void
    {
        if ('' === $this->releasesDirectory || !str_starts_with($this->releasesDirectory, '/') || str_contains($this->releasesDirectory, '/public/')) {
            throw new \RuntimeException('Release directory configuration is unsafe.');
        }
        if (!is_dir($this->releasesDirectory) && !mkdir($this->releasesDirectory, 0o755, true)) {
            throw new \RuntimeException('Release directory cannot be created.');
        }
    }

    private function freeze(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Extracted release contains an unsafe object.');
            }
            if (!chmod($entry->getPathname(), $entry->isDir() ? 0o555 : 0o444)) {
                throw new \RuntimeException('Immutable release permissions cannot be applied.');
            }
        }
        if (!chmod($directory, 0o555)) {
            throw new \RuntimeException('Immutable release root permissions cannot be applied.');
        }
    }

    private function assertPublishedOwnership(string $directory): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        if (!\function_exists('posix_geteuid') || 0 !== posix_geteuid()) {
            throw new \RuntimeException('Production release publication requires the root-controlled updater identity.');
        }

        $paths = [$directory];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Immutable release ownership validation found an unsafe object.');
            }
            $paths[] = $entry->getPathname();
        }
        foreach ($paths as $path) {
            $metadata = @lstat($path);
            if (!\is_array($metadata) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o222)) {
                throw new \RuntimeException('Production release is not root-owned and immutable.');
            }
        }
    }

    private function transferSafetyBackupToServiceOwner(string $path): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        $before = @lstat($path);
        $parent = @lstat(\dirname($path));
        if (!\is_array($before)
            || !\is_array($parent)
            || 0o100000 !== ($before['mode'] & 0o170000)
            || 0 !== $before['uid']
            || 0o600 !== ($before['mode'] & 0o777)
            || 1 !== $before['nlink']
            || 0o040000 !== ($parent['mode'] & 0o170000)
            || 0 === $parent['uid']
            || 0o700 !== ($parent['mode'] & 0o777)
            || !\function_exists('lchown')
            || !\function_exists('lchgrp')
            || !@lchown($path, $parent['uid'])
            || !@lchgrp($path, $parent['gid'])
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership cannot be returned to the service identity.');
        }
        $after = @lstat($path);
        if ($before['ino'] !== $after['ino']
            || $before['dev'] !== $after['dev']
            || $parent['uid'] !== $after['uid']
            || $parent['gid'] !== $after['gid']
            || 0o600 !== ($after['mode'] & 0o777)
            || 1 !== $after['nlink']
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership changed unsafely.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertDiskCapacity(string $archive, array $manifest): void
    {
        $this->ensureReleaseRoot();
        $size = filesize($archive);
        $uncompressed = $manifest['_verified_uncompressed_bytes'] ?? null;
        $free = disk_free_space($this->releasesDirectory);
        if (false === $size || !\is_int($uncompressed) || false === $free || $free < $size + (2 * $uncompressed) + $this->rollbackReserveBytes) {
            throw new \RuntimeException('Insufficient disk space for a staged update and rollback reserve.');
        }
    }

    private function removeStaging(string $directory): void
    {
        if (!str_starts_with($directory, rtrim($this->releasesDirectory, '/').'/.staging-') || !is_dir($directory)) {
            return;
        }
        @chmod($directory, 0o700);
        $permissions = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($permissions as $entry) {
            if ($entry instanceof \SplFileInfo && !$entry->isLink()) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }

    private function removeFailedRelease(string $directory): void
    {
        $parent = realpath($this->releasesDirectory);
        $current = realpath($this->releasesDirectory.'/current');
        if (false === $parent || $directory === $current || \dirname($directory) !== $parent || 1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', basename($directory)) || !is_dir($directory) || is_link($directory)) {
            return;
        }
        @chmod($directory, 0o700);
        $permissions = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($permissions as $entry) {
            if ($entry instanceof \SplFileInfo && !$entry->isLink()) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private function stringMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                throw new \RuntimeException('Release file manifest is invalid.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
