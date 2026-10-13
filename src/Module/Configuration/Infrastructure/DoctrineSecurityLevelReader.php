<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Identity\Application\Contract\SecurityLevelReader;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSecurityLevelReader implements SecurityLevelReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): string
    {
        try {
            $value = $this->connection->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'");
        } catch (\Throwable) {
            return 'high';
        }

        return 'normal' === $value ? 'normal' : 'high';
    }

    public function requiresRecentAuthentication(): bool
    {
        return 'high' === $this->current();
    }
}
