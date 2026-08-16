<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\MfaEnrollment;
use App\Module\Secrets\Application\Contract\SecretVault;
use Doctrine\DBAL\Connection;

final readonly class MfaManagement
{
    public function __construct(
        private Connection $connection,
        private SecretVault $secretVault,
        private Totp $totp,
        private SessionRegistry $sessions,
        private AuditSink $audit,
        private AuthorizationService $authorization,
    ) {
    }

    /** @return array{enabled: bool, recovery_remaining: int, pending: bool} */
    public function status(AuthenticatedUser $user): array
    {
        $this->authorization->assertAtLeast($user, AdministrativeRole::Auditor, 'identity.mfa.view');

        return [
            'enabled' => 0 < $this->count("SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 1", [$user->id()]),
            'recovery_remaining' => $this->count("SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code' AND active = 1 AND consumed_at IS NULL", [$user->id()]),
            'pending' => 0 < $this->count('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND active = 0', [$user->id()]),
        ];
    }

    public function start(AuthenticatedUser $user): MfaEnrollment
    {
        $this->authorization->assertAtLeast($user, AdministrativeRole::Auditor, 'identity.mfa.start');
        if ($this->status($user)['enabled']) {
            throw new \RuntimeException('MFA is already enabled.');
        }
        $seed = $this->totp->generateSeed();
        $codes = $this->generateRecoveryCodes();
        $installationId = $this->installationId();

        $this->connection->transactional(function (Connection $connection) use ($user, $seed, $codes, $installationId): void {
            $this->deleteCredentials($connection, $user->id(), false);
            $secretId = $this->secretVault->store('identity.totp-seed', $seed, $installationId);
            $connection->insert('mfa_credential', [
                'id' => IdentityUtil::uuidV7(),
                'user_id' => $user->id(),
                'credential_type' => 'totp',
                'encrypted_secret_id' => $secretId,
                'value_hash' => null,
                'active' => 0,
                'consumed_at' => null,
                'created_at' => IdentityUtil::now(),
            ]);
            $this->insertRecoveryCodes($connection, $user->id(), $codes, false);
            $this->audit->append(new AuditRecord(
                $user->id(),
                'identity.mfa.enrollment_started',
                $user->id(),
                'success',
                ['one_time_count' => \count($codes)],
            ));
        });

        $label = rawurlencode('Emailer Next:'.$user->getUserIdentifier());

        return new MfaEnrollment(
            $seed,
            $codes,
            \sprintf('otpauth://totp/%s?secret=%s&issuer=Emailer%%20Next&algorithm=SHA1&digits=6&period=30', $label, $seed),
        );
    }

    public function confirm(AuthenticatedUser $user, string $code, bool $recoverySaved, string $currentSessionId): void
    {
        $this->authorization->assertAtLeast($user, AdministrativeRole::Auditor, 'identity.mfa.confirm');
        if (!$recoverySaved) {
            throw new \InvalidArgumentException('Recovery-code acknowledgement is required.');
        }

        $this->connection->transactional(function (Connection $connection) use ($user, $code, $currentSessionId): void {
            if ($this->status($user)['enabled']) {
                throw new \RuntimeException('MFA is already enabled.');
            }
            $pending = $connection->fetchFirstColumn(
                "SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 0 FOR UPDATE",
                [$user->id()],
            );
            if (1 !== \count($pending) || !\is_string($pending[0] ?? null)
                || 10 !== $this->count("SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code' AND active = 0", [$user->id()])
            ) {
                throw new \RuntimeException('Pending MFA enrollment is unavailable.');
            }
            $seed = $this->secretVault->reveal($pending[0], 'identity.totp-seed', $this->installationId());
            try {
                $counter = $this->totp->matchingCounter($seed, $code);
                if (null === $counter) {
                    throw new \RuntimeException('MFA confirmation failed.');
                }
            } finally {
                sodium_memzero($seed);
            }

            $connection->executeStatement(
                "UPDATE mfa_credential SET active = 1, last_accepted_counter = IF(credential_type = 'totp', ?, last_accepted_counter) WHERE user_id = ? AND active = 0",
                [$counter, $user->id()],
            );
            $connection->update('app_user', ['mfa_changed_at' => IdentityUtil::now(), 'updated_at' => IdentityUtil::now()], ['id' => $user->id()]);
            $this->sessions->revokeAll($user->id(), $currentSessionId);
            $this->audit->append(new AuditRecord(
                $user->id(),
                'identity.mfa.enabled',
                $user->id(),
                'success',
                ['authority_invalidated' => true],
            ));
        });
    }

    public function disable(AuthenticatedUser $user, string $currentSessionId): void
    {
        $this->authorization->assertAtLeast($user, AdministrativeRole::Auditor, 'identity.mfa.disable');
        $this->connection->transactional(function (Connection $connection) use ($user, $currentSessionId): void {
            $this->deleteCredentials($connection, $user->id(), true);
            $connection->update('app_user', ['mfa_changed_at' => IdentityUtil::now(), 'updated_at' => IdentityUtil::now()], ['id' => $user->id()]);
            $this->sessions->revokeAll($user->id(), $currentSessionId);
            $this->audit->append(new AuditRecord(
                $user->id(),
                'identity.mfa.disabled',
                $user->id(),
                'success',
                ['authority_invalidated' => true],
            ));
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(AuthenticatedUser $user, string $currentSessionId): array
    {
        $this->authorization->assertAtLeast($user, AdministrativeRole::Auditor, 'identity.mfa.recovery_regenerate');
        if (!$this->status($user)['enabled']) {
            throw new \RuntimeException('MFA is not enabled.');
        }
        $codes = $this->generateRecoveryCodes();
        $this->connection->transactional(function (Connection $connection) use ($user, $currentSessionId, $codes): void {
            $connection->executeStatement("DELETE FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code'", [$user->id()]);
            $this->insertRecoveryCodes($connection, $user->id(), $codes, true);
            $connection->update('app_user', ['mfa_changed_at' => IdentityUtil::now(), 'updated_at' => IdentityUtil::now()], ['id' => $user->id()]);
            $this->sessions->revokeAll($user->id(), $currentSessionId);
            $this->audit->append(new AuditRecord(
                $user->id(),
                'identity.mfa.recovery_regenerated',
                $user->id(),
                'success',
                ['one_time_count' => \count($codes), 'authority_invalidated' => true],
            ));
        });

        return $codes;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8).'-'.substr(bin2hex(random_bytes(8)), 0, 8));
        }

        return $codes;
    }

    /** @param list<string> $codes */
    private function insertRecoveryCodes(Connection $connection, string $userId, array $codes, bool $active): void
    {
        foreach ($codes as $code) {
            $connection->insert('mfa_credential', [
                'id' => IdentityUtil::uuidV7(),
                'user_id' => $userId,
                'credential_type' => 'recovery_code',
                'encrypted_secret_id' => null,
                'value_hash' => password_hash($code, PASSWORD_ARGON2ID, ['memory_cost' => 8_192, 'time_cost' => 2, 'threads' => 1]),
                'active' => $active ? 1 : 0,
                'consumed_at' => null,
                'created_at' => IdentityUtil::now(),
            ]);
        }
    }

    private function deleteCredentials(Connection $connection, string $userId, bool $all): void
    {
        $condition = $all ? '' : ' AND active = 0';
        $secrets = $connection->fetchFirstColumn(
            'SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND encrypted_secret_id IS NOT NULL'.$condition.' FOR UPDATE',
            [$userId],
        );
        $connection->executeStatement('DELETE FROM mfa_credential WHERE user_id = ?'.$condition, [$userId]);
        foreach ($secrets as $secretId) {
            if (\is_string($secretId)) {
                $connection->delete('encrypted_secret', ['id' => $secretId]);
            }
        }
    }

    private function installationId(): string
    {
        $value = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'");
        if (!\is_string($value)) {
            throw new \RuntimeException('Installation identity is unavailable.');
        }

        return $value;
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }
}
