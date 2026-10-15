<?php

declare(strict_types=1);

namespace App\Module\Release\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class ReleaseBackupContributor implements BackupContributor
{
    public function __construct(private \Doctrine\DBAL\Connection $connection)
    {
    }

    public function tables(): array
    {
        $tables = ['release_operation'];
        foreach (['ripe_edition_transition', 'product_identity'] as $table) {
            if ($this->connection->createSchemaManager()->tablesExist([$table])) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    public function assetRoots(): array
    {
        return [];
    }
}
