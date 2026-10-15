<?php

declare(strict_types=1);

namespace App\Module\Contacts\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class ContactsBackupContributor implements BackupContributor
{
    public function __construct(private \Doctrine\DBAL\Connection $connection)
    {
    }

    public function tables(): array
    {
        $tables = ['contact', 'contact_field_definition', 'contact_attribute', 'contact_list', 'list_membership', 'suppression', 'contact_import', 'contact_import_candidate'];
        if ($this->connection->createSchemaManager()->tablesExist(['suppression_match'])) {
            $tables[] = 'suppression_match';
        }

        return $tables;
    }

    public function assetRoots(): array
    {
        return [];
    }
}
