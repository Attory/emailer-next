<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

/** Private configuration roots retain restrictive metadata when restored. */
interface PrivateBackupAssets extends BackupContributor
{
    /** @return list<string> Logical names declared by this contributor's assetRoots(). */
    public function privateAssetRoots(): array;
}
