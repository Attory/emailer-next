<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Doctrine\DBAL\Connection;

final readonly class LoginThrottle
{
    public function __construct(
        private Connection $connection,
        private SensitiveValueHasher $hasher,
    ) {
    }

    /** @return array{count: int, last: string} */
    public function reserveLogin(string $login, string $source): array
    {
        $accountHash = $this->accountHash($login);
        $reservations = $this->reserve([
            [$accountHash, 'account', 5, 300],
            [$this->sourceHash($source), 'source', 20, 900],
        ]);

        return $reservations[$accountHash];
    }

    /** @param array{count: int, last: string} $reservation */
    public function loginSucceeded(string $login, array $reservation): void
    {
        $this->connection->executeStatement(
            'DELETE FROM login_throttle WHERE scope_hash = ? AND failure_count = ? AND last_failed_at = ?',
            [$this->accountHash($login), $reservation['count'], $reservation['last']],
        );
    }

    /** @return array<string, array{count: int, last: string}> */
    public function reserveReauthentication(string $userId, string $source, string $sessionId): array
    {
        return $this->reserve([
            [$this->hasher->hash('reauth-account', $userId), 'reauth_account', 5, 300],
            [$this->hasher->hash('reauth-context', $userId."\0".$source."\0".$sessionId), 'reauth_context', 8, 300],
        ]);
    }

    /** @param array<string, array{count: int, last: string}> $reservations */
    public function reauthenticationSucceeded(array $reservations): void
    {
        foreach ($reservations as $hash => $reservation) {
            $this->connection->executeStatement(
                'DELETE FROM login_throttle WHERE scope_hash = ? AND failure_count = ? AND last_failed_at = ?',
                [$hash, $reservation['count'], $reservation['last']],
            );
        }
    }

    public function sourceHash(string $source): string
    {
        return $this->hasher->hash('login-source', $source);
    }

    public function accountHash(string $login): string
    {
        return $this->hasher->hash('login-account', mb_strtolower(trim($login), 'UTF-8'));
    }

    /**
     * @param list<array{string, string, int, int}> $scopes
     *
     * @return array<string, array{count: int, last: string}>
     */
    private function reserve(array $scopes): array
    {
        usort($scopes, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $this->connection->transactional(function (Connection $connection) use ($scopes): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $formattedNow = $now->format('Y-m-d H:i:s.u');
            foreach ($scopes as [$hash, $type]) {
                $connection->executeStatement(<<<'SQL'
INSERT INTO login_throttle (
    scope_hash, scope_type, failure_count, first_failed_at, last_failed_at, not_before, locked_until
) VALUES (?, ?, 0, ?, ?, ?, NULL)
ON DUPLICATE KEY UPDATE scope_hash = VALUES(scope_hash)
SQL, [$hash, $type, $formattedNow, $formattedNow, $formattedNow]);
            }

            $reservations = [];
            foreach ($scopes as [$hash, $type, $threshold, $lockSeconds]) {
                $record = $connection->fetchAssociative(
                    'SELECT failure_count, first_failed_at, last_failed_at, not_before, locked_until FROM login_throttle WHERE scope_hash = ? FOR UPDATE',
                    [$hash],
                );
                if (false === $record) {
                    throw new \RuntimeException('Authentication throttle state is unavailable.');
                }
                $expired = $this->expiredWindow($record['last_failed_at'] ?? null, $now);
                $count = $expired ? 0 : (is_numeric($record['failure_count'] ?? null) ? (int) $record['failure_count'] : 0);
                if (!$expired && ($this->after($record['not_before'] ?? null, $now) || $this->after($record['locked_until'] ?? null, $now) || $count >= $threshold)) {
                    throw new LoginThrottled('Authentication is temporarily unavailable.');
                }

                ++$count;
                $first = 1 === $count || $expired || !\is_string($record['first_failed_at'] ?? null)
                    ? $formattedNow
                    : $record['first_failed_at'];
                $delay = min(8, max(1, 2 ** min($count - 1, 3)));
                $connection->update('login_throttle', [
                    'scope_type' => $type,
                    'failure_count' => $count,
                    'first_failed_at' => $first,
                    'last_failed_at' => $formattedNow,
                    'not_before' => $now->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u'),
                    'locked_until' => $count >= $threshold ? $now->modify('+'.$lockSeconds.' seconds')->format('Y-m-d H:i:s.u') : null,
                ], ['scope_hash' => $hash]);
                $reservations[$hash] = ['count' => $count, 'last' => $formattedNow];
            }

            return $reservations;
        });
    }

    private function after(mixed $value, \DateTimeImmutable $now): bool
    {
        if (!\is_string($value) || '' === $value) {
            return false;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) > $now;
        } catch (\Throwable) {
            return true;
        }
    }

    private function expiredWindow(mixed $value, \DateTimeImmutable $now): bool
    {
        if (!\is_string($value)) {
            return true;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) <= $now->modify('-15 minutes');
        } catch (\Throwable) {
            return true;
        }
    }
}
