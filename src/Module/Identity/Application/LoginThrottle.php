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

    public function assertAllowed(string $login, string $source): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->scopes($login, $source) as [$hash]) {
            $record = $this->connection->fetchAssociative(
                'SELECT not_before, locked_until, last_failed_at FROM login_throttle WHERE scope_hash = ?',
                [$hash],
            );
            if (false === $record) {
                continue;
            }
            if ($this->expiredWindow($record['last_failed_at'] ?? null, $now)) {
                $this->connection->delete('login_throttle', ['scope_hash' => $hash]);
                continue;
            }
            if ($this->after($record['not_before'] ?? null, $now) || $this->after($record['locked_until'] ?? null, $now)) {
                throw new LoginThrottled('Authentication is temporarily unavailable.');
            }
        }
    }

    public function recordFailure(string $login, string $source): void
    {
        foreach ($this->scopes($login, $source) as [$hash, $type, $threshold, $lockSeconds]) {
            $this->connection->transactional(function (Connection $connection) use ($hash, $type, $threshold, $lockSeconds): void {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $record = $connection->fetchAssociative(
                    'SELECT failure_count, first_failed_at, last_failed_at, locked_until FROM login_throttle WHERE scope_hash = ? FOR UPDATE',
                    [$hash],
                );
                if (false !== $record && $this->after($record['locked_until'] ?? null, $now)) {
                    return;
                }

                $storedCount = $record['failure_count'] ?? 0;
                $count = false === $record || $this->expiredWindow($record['last_failed_at'] ?? null, $now)
                    ? 1
                    : (is_numeric($storedCount) ? (int) $storedCount : 0) + 1;
                $first = 1 === $count || false === $record
                    ? $now->format('Y-m-d H:i:s.u')
                    : (\is_string($record['first_failed_at'] ?? null) ? $record['first_failed_at'] : $now->format('Y-m-d H:i:s.u'));
                $delay = min(8, max(1, 2 ** min($count - 1, 3)));
                $lockedUntil = $count >= $threshold
                    ? $now->modify('+'.$lockSeconds.' seconds')->format('Y-m-d H:i:s.u')
                    : null;
                $values = [
                    'scope_type' => $type,
                    'failure_count' => $count,
                    'first_failed_at' => $first,
                    'last_failed_at' => $now->format('Y-m-d H:i:s.u'),
                    'not_before' => $now->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u'),
                    'locked_until' => $lockedUntil,
                ];
                if (false === $record) {
                    $connection->insert('login_throttle', ['scope_hash' => $hash] + $values);
                } else {
                    $connection->update('login_throttle', $values, ['scope_hash' => $hash]);
                }
            });
        }
    }

    public function clear(string $login): void
    {
        $this->connection->delete('login_throttle', ['scope_hash' => $this->accountHash($login)]);
    }

    public function sourceHash(string $source): string
    {
        return $this->hasher->hash('login-source', $source);
    }

    public function accountHash(string $login): string
    {
        return $this->hasher->hash('login-account', mb_strtolower(trim($login), 'UTF-8'));
    }

    /** @return list<array{string, string, int, int}> */
    private function scopes(string $login, string $source): array
    {
        return [
            [$this->accountHash($login), 'account', 5, 300],
            [$this->hasher->hash('login-source', $source), 'source', 20, 900],
        ];
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
