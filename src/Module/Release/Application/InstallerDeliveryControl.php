<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Shared\DeliveryControlFiles;
use App\Shared\EditionDeliveryHold;
use Doctrine\DBAL\Connection;

/** Privileged completion bridge. Never clears independent owner intent. */
final readonly class InstallerDeliveryControl
{
    private DeliveryControlFiles $files;
    private string $installation;

    public function __construct(private Connection $connection, private string $privateDirectory)
    {
        if (0 !== posix_geteuid()) {
            throw new \RuntimeException('Installer delivery completion requires the protected privileged context.');
        }
        $id = $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (!\is_string($id)) {
            throw new \RuntimeException('Installer delivery identity is unavailable.');
        }
        $this->installation = $id;
        $this->files = new DeliveryControlFiles($privateDirectory, $id);
    }

    public function beginFresh(string $operation, string $proof, int $uid, int $gid): void
    {
        $record = $this->rootProof($proof);
        if ($operation !== ($record['control_operation_id'] ?? null) || $this->installation !== ($record['control_installation_uuid'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || !\is_array($record['completed'] ?? null) || !\in_array('Creating your administrator account', $record['completed'], true)) {
            throw new \RuntimeException('Fresh installation delivery binding is invalid.');
        }
        $this->files->prepare($uid, $gid, 'enabled');
        $this->files->hold($operation, 'host', ['proof_path' => $proof, 'fresh' => true]);
        if ('released' !== $this->files->operationState($operation)['phase']) {
            $this->connection->transactional(function (): void {
                $active = $this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
                if (!is_numeric($active) || 0 !== (int) $active) {
                    throw new \RuntimeException('Fresh installation has unexpected active delivery claims.');
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            });
        }
    }

    public function completeFresh(string $operation, string $proof): void
    {
        $record = $this->rootProof($proof);
        $hold = $this->files->operationState($operation);
        if ($operation !== ($record['control_operation_id'] ?? null) || $this->installation !== ($record['control_installation_uuid'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || true !== ($record['control_runtime_verified'] ?? null) || !\is_array($record['completed'] ?? null) || !\in_array('Checking application health and initial backup', $record['completed'], true) || 'host' !== $hold['kind'] || ['proof_path' => $proof, 'fresh' => true] !== $hold['evidence']) {
            throw new \RuntimeException('Fresh installation runtime completion is not verified.');
        }
        $lease = $this->files->projectionLease();
        try {
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    public function beginHost(string $operation, string $proof, int $uid, int $gid, ?string $invocation = null, bool $legacyAccessBlocked = false): void
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || true !== ($record['delivery_source_verified'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null)) {
            throw new \RuntimeException('Installer delivery source binding is invalid.');
        }
        $prior = $this->connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'");
        if ((!\is_string($prior) && !\is_int($prior)) || !\in_array((string) $prior, ['0', '1'], true)) {
            throw new \RuntimeException('Earlier delivery intent is unavailable.');
        }
        $this->files->prepare($uid, $gid, '1' === (string) $prior ? 'enabled' : 'unknown');
        foreach ($this->files->status()['holds'] as $other) {
            $evidence = $other['evidence'];
            if ($operation !== ($other['operation_id'] ?? null) && \in_array($other['kind'] ?? null, ['host', 'native', 'edition'], true) && !('native' === $other['kind'] && \is_array($evidence) && $operation === ($evidence['parent_operation'] ?? null))) {
                throw new \RuntimeException('A separate installer operation requires its original Finish or Recover flow before another host operation. Independent holds were preserved.');
            }
        }
        $this->files->hold($operation, 'host', ['proof_path' => $proof]);
        if ('released' !== $this->files->operationState($operation)['phase'] && true === ($record['control_legacy_source'] ?? null) && $invocation !== ($record['control_capture_invocation'] ?? null) && !$legacyAccessBlocked) {
            $this->files->invalidateUnprovenLegacyIntent();
        }
        if ('1' === ($record['control_requested_action'] ?? null) && 'recovered' === $this->files->operationState($operation)['phase']) {
            $this->files->phase($operation, 'active');
        }
    }

    public function completeHost(string $operation, string $proof): void
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        $hold = $this->files->operationState($operation);
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || 'complete' !== ($host['phase'] ?? null) || true !== ($host['control_runtime_verified'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || 'host' !== $hold['kind'] || ['proof_path' => $proof] !== $hold['evidence']) {
            throw new \RuntimeException('Only the exact fully verified host operation may retire its hold.');
        }
        if ('released' === $hold['phase']) {
            return; // Terminal completion must not project over later owner actions or claims.
        }
        $lease = $this->files->projectionLease();
        try {
            $children = [];
            $current = null;
            foreach ($this->files->status()['holds'] as $child) {
                $evidence = $child['evidence'];
                if ('native' === $child['kind'] && \is_array($evidence) && $operation === ($evidence['parent_operation'] ?? null)) {
                    $id = $this->text($child, 'operation_id');
                    $native = $this->nativeProof($id, $this->text($evidence, 'proof_path'));
                    if ('completed' === ($native['phase'] ?? null)) {
                        $this->verifyNativeProof($id, $this->text($evidence, 'proof_path'));
                        $current = $native;
                    } elseif ('recovered' !== ($native['phase'] ?? null) || true !== ($native['fence_restored'] ?? null)) {
                        throw new \RuntimeException('A child operation still requires recovery.');
                    }
                    $children[$id] = $native;
                }
            }
            foreach ($children as $id => $native) {
                if ('recovered' === $native['phase'] && (!\is_array($current) || ($current['source_archive_sha256'] ?? null) !== ($native['source_archive_sha256'] ?? null) || ($current['source_root'] ?? null) !== ($native['source_root'] ?? null) || ($current['source_version'] ?? null) !== ($native['source_version'] ?? null))) {
                    throw new \RuntimeException('A recovered child has no verified succeeding operation in this handoff.');
                }
            }
            foreach (array_keys($children) as $id) {
                $this->files->phase($id, 'released');
            }
            if ('light-to-ripe' === ($record['mode'] ?? null)) {
                $transition = $this->rootProof(\dirname($proof).'/edition-operation.json');
                if ($this->installation !== ($transition['installation_uuid'] ?? null) || 'accepted' !== ($transition['phase'] ?? null)) {
                    throw new \RuntimeException('Edition acceptance is not verified.');
                }
                $receipt = $this->privateDirectory.'/edition-delivery-hold.json';
                if (file_exists($receipt) || is_link($receipt)) {
                    $metadata = lstat($receipt);
                    $bytes = file_get_contents($receipt);
                    $value = false === $bytes ? null : json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
                    if (false === $metadata || 0o100000 !== ($metadata['mode'] & 0o170000) || 1 !== $metadata['nlink'] || 0 !== ($metadata['mode'] & 0o077) || !\is_array($value) || $this->installation !== ($value['installation_uuid'] ?? null) || ($transition['operation_id'] ?? null) !== ($value['operation_id'] ?? null) || 'accepted' !== ($value['phase'] ?? null)) {
                        throw new \RuntimeException('Edition hold ownership is unverified; preserve it for review.');
                    }
                    if (!unlink($receipt)) {
                        throw new \RuntimeException('The verified accepted transition hold could not be retired.');
                    }
                    $this->sync(\dirname($receipt));
                }
            }
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    /** Only called after the exact native Finish has independently verified live target services. */
    public function completeNative(string $operation, string $proof): void
    {
        $this->verifyNativeProof($operation, $proof);
        $hold = $this->files->operationState($operation);
        $evidence = $hold['evidence'];
        if ('native' !== $hold['kind'] || !\is_array($evidence) || $proof !== ($evidence['proof_path'] ?? null) || '' !== ($evidence['parent_operation'] ?? null)) {
            throw new \RuntimeException('The native operation belongs to a separate host completion.');
        }
        if ('released' === $hold['phase']) {
            return;
        }
        $current = $this->nativeProof($operation, $proof);
        if (true !== ($current['delivery_control_source_aware'] ?? null) && true !== ($current['retain_source_fence'] ?? null)) {
            // The old writer was reopened before Finish. A legacy Pause can
            // acknowledge only a DB flag, indistinguishable from our own gate.
            // Retire the installer hold, but require explicit intent review.
            $this->files->invalidateUnprovenLegacyIntent();
        }
        $lease = $this->files->projectionLease();
        try {
            $holds = [];
            foreach ($this->files->status()['holds'] as $record) {
                $holds[$this->text($record, 'operation_id')] = $record;
            }
            $previous = $current['previous_operation_id'] ?? null;
            $seen = [$operation => true];
            $retire = [];
            while (\is_string($previous) && isset($holds[$previous])) {
                if (isset($seen[$previous]) || 64 < \count($seen)) {
                    throw new \RuntimeException('Native recovery lineage is cyclic or exceeds its bound.');
                }
                $seen[$previous] = true;
                $priorHold = $holds[$previous];
                $priorEvidence = $priorHold['evidence'];
                if ('native' !== $priorHold['kind'] || 'recovered' !== $priorHold['phase'] || !\is_array($priorEvidence) || '' !== ($priorEvidence['parent_operation'] ?? null)) {
                    throw new \RuntimeException('A separate native operation still owns its hold.');
                }
                $prior = $this->nativeProof($previous, $this->text($priorEvidence, 'proof_path'));
                if ('recovered' !== ($prior['phase'] ?? null) || true !== ($prior['fence_restored'] ?? null) || ($current['source_archive_sha256'] ?? null) !== ($prior['source_archive_sha256'] ?? null) || ($current['source_root'] ?? null) !== ($prior['source_root'] ?? null) || ($current['source_version'] ?? null) !== ($prior['source_version'] ?? null)) {
                    throw new \RuntimeException('Recovered native predecessor provenance is not verified.');
                }
                $retire[] = $previous;
                $previous = $prior['previous_operation_id'] ?? null;
            }
            foreach ($retire as $predecessor) {
                $this->files->phase($predecessor, 'released');
            }
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    public function retainDeliveryReview(string $operation, string $proof): void
    {
        $record = $this->reviewProof($operation, $proof);
        if (!\is_int($record['delivery_review_count'] ?? null) || 0 >= $record['delivery_review_count']) {
            throw new \RuntimeException('The retained delivery review has no verified uncertain outcome.');
        }
        $this->files->hold($this->reviewOperation($record), 'recovery', ['reason' => 'uncertain_delivery', 'parent_operation' => $operation, 'proof_path' => $proof]);
    }

    public function acknowledgeDeliveryReview(string $operation, string $proof): void
    {
        $record = $this->reviewProof($operation, $proof);
        if (true !== ($record['delivery_review_acknowledged'] ?? null) || true !== ($record['control_release_completed'] ?? null)) {
            throw new \RuntimeException('Delivery outcome review requires an explicit operator acknowledgement after maintenance completion.');
        }
        $hold = $this->files->operationState($this->reviewOperation($record));
        if ('recovery' !== $hold['kind'] || ['reason' => 'uncertain_delivery', 'parent_operation' => $operation, 'proof_path' => $proof] !== $hold['evidence']) {
            throw new \RuntimeException('Delivery outcome review belongs to another operation.');
        }
        // Keep the DB gate held. Only the separate normal Resume action may
        // enable delivery after every other hold and readiness requirement passes.
        $this->files->phase($this->reviewOperation($record), 'released');
    }

    /** @return array<string, mixed> */
    private function reviewProof(string $operation, string $proof): array
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || 'complete' !== ($host['phase'] ?? null) || true !== ($host['control_runtime_verified'] ?? null) || !\is_string($record['delivery_review_operation_id'] ?? null)) {
            throw new \RuntimeException('Delivery outcome review identity is invalid.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function reviewOperation(array $record): string
    {
        $value = $record['delivery_review_operation_id'] ?? null;
        if (!\is_string($value)) {
            throw new \RuntimeException('Delivery outcome review operation is unavailable.');
        }

        return $value;
    }

    private function publishProjection(string $operation): void
    {
        if ('released' === $this->files->operationState($operation)['phase']) {
            return;
        }
        $state = $this->files->status();
        $others = array_filter($state['holds'], static fn (array $hold): bool => $operation !== ($hold['operation_id'] ?? null));
        // Commit the database projection first. The durable operation file still
        // blocks claims until its final fsynced release; interruption fails closed.
        $enabled = 'enabled' === $state['owner'] && [] === $others && !(new EditionDeliveryHold($this->privateDirectory))->active();
        $this->connection->transactional(function () use ($enabled): void {
            $runtime = $this->connection->fetchAssociative("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
            if (false === $runtime || !is_numeric($runtime['active_claims']) || 0 !== (int) $runtime['active_claims']) {
                throw new \RuntimeException('Active delivery claims prevent maintenance completion.');
            }
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=?,updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'", [$enabled ? 1 : 0]);
        });
        $this->files->phase($operation, 'released');
    }

    private function verifyNativeProof(string $operation, string $path): void
    {
        $proof = $this->nativeProof($operation, $path);
        $latest = $this->connection->fetchAssociative("SELECT id,status FROM release_operation WHERE operation='update' ORDER BY started_at DESC,id DESC LIMIT 1");
        if (!\is_array($latest) || $operation !== $latest['id'] || 'succeeded' !== $latest['status'] || $operation !== ($proof['operation_id'] ?? null) || $this->installation !== ($proof['installation_uuid'] ?? null) || $this->privateDirectory !== ($proof['private_directory'] ?? null) || 'completed' !== ($proof['phase'] ?? null) || true !== ($proof['fence_restored'] ?? null)) {
            throw new \RuntimeException('Native completion or writer handoff is not verified.');
        }
    }

    /** @return array<string, mixed> */
    private function nativeProof(string $operation, string $path): array
    {
        $expected = \dirname($this->privateDirectory).'/native-update-recovery/'.$operation.'/native-operation.json';
        if ($path !== $expected) {
            throw new \RuntimeException('Native delivery proof belongs to another operation.');
        }
        $proof = $this->rootProof($path);
        if ('emailer-native-update-recovery-v1' !== ($proof['format'] ?? null) || $operation !== ($proof['operation_id'] ?? null) || $this->installation !== ($proof['installation_uuid'] ?? null) || $this->privateDirectory !== ($proof['private_directory'] ?? null) || !\is_string($proof['source_archive_sha256'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $proof['source_archive_sha256']) || !\is_string($proof['source_root'] ?? null) || !str_starts_with($proof['source_root'], '/') || !\is_string($proof['source_version'] ?? null) || !\is_string($proof['target_version'] ?? null)) {
            throw new \RuntimeException('Native delivery proof identity is invalid.');
        }

        return $proof;
    }

    /** @return array<string, mixed> */
    private function rootProof(string $path): array
    {
        for ($parent = \dirname($path);; $parent = \dirname($parent)) {
            $metadata = @lstat($parent);
            if (!\is_array($metadata) || 0o040000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022)) {
                throw new \RuntimeException('Installer delivery proof directory is unsafe.');
            }
            if ('/' === $parent) {
                break;
            }
        }
        $metadata = @lstat($path);
        if (!\is_array($metadata) || 0o100000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022) || 1 !== $metadata['nlink'] || 2097152 < $metadata['size']) {
            throw new \RuntimeException('Installer delivery proof is unsafe.');
        }
        $bytes = file_get_contents($path);
        $proof = false === $bytes ? null : json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($proof)) {
            throw new \RuntimeException('Installer delivery proof is invalid.');
        }

        $record = [];
        foreach ($proof as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Installer delivery proof keys are invalid.');
            }
            $record[$key] = $value;
        }

        return $record;
    }

    private function sync(string $path): void
    {
        $stream = fopen($path, 'r');
        if (false === $stream) {
            throw new \RuntimeException('Edition hold directory sync is unavailable.');
        }
        try {
            if (!fsync($stream)) {
                throw new \RuntimeException('Edition hold directory sync failed.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<array-key, mixed> $value */
    private function text(array $value, string $key): string
    {
        if (!\is_string($value[$key] ?? null)) {
            throw new \RuntimeException('Installer delivery proof text is invalid.');
        }

        return $value[$key];
    }
}
