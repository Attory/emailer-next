<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\IdentityUtil;
use App\Module\Identity\Application\SensitiveValueHasher;
use Doctrine\DBAL\Connection;

final readonly class DatabaseSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private const ABSOLUTE_SECONDS = 43_200;
    private const IDLE_SECONDS = 1_800;

    public function __construct(
        private Connection $connection,
        private SensitiveValueHasher $hasher,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $record = $this->connection->fetchAssociative(
            'SELECT payload, idle_expires_at, absolute_expires_at, revoked_at FROM user_session WHERE id_hash = ?',
            [$this->digest($id)],
        );
        if (false === $record || null !== ($record['revoked_at'] ?? null)) {
            return '';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$this->future($record['idle_expires_at'] ?? null, $now) || !$this->future($record['absolute_expires_at'] ?? null, $now)) {
            $this->destroy($id);

            return '';
        }

        return \is_string($record['payload'] ?? null) ? $record['payload'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $formattedNow = $now->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO user_session (
    id_hash, user_id, payload, created_at, last_seen_at, idle_expires_at, absolute_expires_at,
    revoked_at, source_hash, user_agent_hash
) VALUES (?, NULL, ?, ?, ?, ?, ?, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE
    payload = VALUES(payload),
    last_seen_at = VALUES(last_seen_at),
    idle_expires_at = VALUES(idle_expires_at)
SQL, [
            $this->digest($id),
            $data,
            $formattedNow,
            $formattedNow,
            $now->modify('+'.self::IDLE_SECONDS.' seconds')->format('Y-m-d H:i:s.u'),
            $now->modify('+'.self::ABSOLUTE_SECONDS.' seconds')->format('Y-m-d H:i:s.u'),
        ]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->connection->delete('user_session', ['id_hash' => $this->digest($id)]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        $affected = $this->connection->executeStatement(
            'DELETE FROM user_session WHERE idle_expires_at <= ? OR absolute_expires_at <= ? OR (revoked_at IS NOT NULL AND revoked_at <= ?)',
            [IdentityUtil::now(), IdentityUtil::now(), (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
        );

        return is_numeric($affected) ? (int) $affected : 0;
    }

    public function validateId(string $id): bool
    {
        $record = $this->connection->fetchAssociative(
            'SELECT idle_expires_at, absolute_expires_at, revoked_at FROM user_session WHERE id_hash = ?',
            [$this->digest($id)],
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return false !== $record
            && null === ($record['revoked_at'] ?? null)
            && $this->future($record['idle_expires_at'] ?? null, $now)
            && $this->future($record['absolute_expires_at'] ?? null, $now);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private function digest(string $id): string
    {
        return $this->hasher->hash('session-id', $id);
    }

    private function future(mixed $value, \DateTimeImmutable $now): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) > $now;
        } catch (\Throwable) {
            return false;
        }
    }
}
