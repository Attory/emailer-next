<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Application\Contract\RecoveryCodeConsumer;
use App\Module\Secrets\Application\Contract\SecretVault;
use Doctrine\DBAL\Connection;

final readonly class MfaVerifier
{
    public function __construct(
        private Connection $connection,
        private SecretVault $secretVault,
        private RecoveryCodeConsumer $recoveryCodes,
        private Totp $totp,
    ) {
    }

    public function required(string $userId): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 1",
            [$userId],
        );
    }

    public function verify(string $userId, string $code): bool
    {
        if (!$this->required($userId)) {
            return '' === trim($code);
        }

        $normalized = strtoupper(trim($code));
        if (1 === preg_match('/^[0-9]{6}$/', $normalized)) {
            $secretId = $this->connection->fetchOne(
                "SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 1",
                [$userId],
            );
            $installationId = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'");
            if (!\is_string($secretId) || !\is_string($installationId)) {
                return false;
            }
            $seed = $this->secretVault->reveal($secretId, 'identity.totp-seed', $installationId);
            try {
                return $this->totp->verify($seed, $normalized);
            } finally {
                sodium_memzero($seed);
            }
        }

        if (1 === preg_match('/^[A-F0-9]{8}-[A-F0-9]{8}$/', $normalized)) {
            return $this->recoveryCodes->consume($userId, $normalized);
        }

        return false;
    }
}
