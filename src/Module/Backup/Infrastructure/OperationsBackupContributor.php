<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class OperationsBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['health_check_run', 'backup_record', 'release_operation', 'housekeeping_run'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
