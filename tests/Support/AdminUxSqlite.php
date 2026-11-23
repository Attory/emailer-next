<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

/** In-process fixture only. Does not claim to test MariaDB locking/concurrency. */
final class AdminUxSqlite extends Connection
{
    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return parent::executeQuery($this->sql($sql), $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return parent::executeStatement($this->sql($sql), $params, $types);
    }

    private function sql(string $sql): string
    {
        return str_replace([' FOR UPDATE', 'INSERT IGNORE', 'UTC_TIMESTAMP(6)'], ['', 'INSERT OR IGNORE', "strftime('%Y-%m-%d %H:%M:%f', 'now')"], $sql);
    }
}
