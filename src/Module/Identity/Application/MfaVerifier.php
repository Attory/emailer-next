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

    public function verify(string $userId, string $code, bool $consume = true): bool
    {
        if (!$this->required($userId)) {
            return '' === trim($code);
        }

        $normalized = strtoupper(trim($code));
        if (1 === preg_match('/^[0-9]{6}$/', $normalized)) {
            return $this->connection->transactional(function (Connection $connection) use ($userId, $normalized, $consume): bool {
                $credential = $connection->fetchAssociative(
                    "SELECT encrypted_secret_id, last_accepted_counter FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 1 FOR UPDATE",
                    [$userId],
                );
                $installationId = $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'");
                if (false === $credential || !\is_string($credential['encrypted_secret_id'] ?? null) || !\is_string($installationId)) {
                    return false;
                }
                $seed = $this->secretVault->reveal($credential['encrypted_secret_id'], 'identity.totp-seed', $installationId);
                try {
                    $counter = $this->totp->matchingCounter($seed, $normalized);
                } finally {
                    sodium_memzero($seed);
                }
                $last = is_numeric($credential['last_accepted_counter'] ?? null) ? (int) $credential['last_accepted_counter'] : null;
                if (null === $counter || (null !== $last && $counter <= $last)) {
                    return false;
                }
                if ($consume) {
                    $connection->executeStatement(
                        "UPDATE mfa_credential SET last_accepted_counter = ? WHERE user_id = ? AND credential_type = 'totp' AND active = 1 AND (last_accepted_counter IS NULL OR last_accepted_counter < ?)",
                        [$counter, $userId, $counter],
                    );
                }

                return true;
            });
        }

        if (1 === preg_match('/^[A-F0-9]{8}-[A-F0-9]{8}$/', $normalized)) {
            return $this->recoveryCodes->verify($userId, $normalized, $consume);
        }

        return false;
    }
}
