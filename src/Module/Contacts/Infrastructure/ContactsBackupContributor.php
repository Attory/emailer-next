<?php

declare(strict_types=1);

namespace App\Module\Contacts\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class ContactsBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['contact', 'contact_field_definition', 'contact_attribute', 'contact_list', 'list_membership', 'suppression', 'contact_import', 'contact_import_candidate'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
