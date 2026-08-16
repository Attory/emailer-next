<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\Contract\RecoveryCodeConsumer;
use Doctrine\DBAL\Connection;

final readonly class DoctrineRecoveryCodeConsumer implements RecoveryCodeConsumer
{
    public function __construct(private Connection $connection)
    {
    }

    public function verify(string $ownerId, string $code, bool $consume): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($ownerId, $code, $consume): bool {
            $records = $connection->fetchAllAssociative(
                "SELECT id, value_hash FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code' AND active = 1 AND consumed_at IS NULL FOR UPDATE",
                [$ownerId],
            );

            $matchingId = null;
            foreach ($records as $record) {
                if (!\is_string($record['id'] ?? null) || !\is_string($record['value_hash'] ?? null)) {
                    continue;
                }
                if (password_verify($code, $record['value_hash'])) {
                    $matchingId = $record['id'];
                }
            }
            if (null === $matchingId) {
                return false;
            }
            if (!$consume) {
                return true;
            }

            return 1 === $connection->executeStatement(
                'UPDATE mfa_credential SET active = 0, consumed_at = ? WHERE id = ? AND active = 1 AND consumed_at IS NULL',
                [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $matchingId],
            );
        });
    }

    public function consume(string $ownerId, string $code): bool
    {
        return $this->verify($ownerId, $code, true);
    }
}
