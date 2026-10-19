<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Backup\Application\Contract\PrivateBackupAssets;

final readonly class AppearanceBackupContributor implements PrivateBackupAssets
{
    public function __construct(private string $appearanceDirectory)
    {
    }

    public function tables(): array
    {
        return [];
    }

    public function assetRoots(): array
    {
        return ['appearance' => $this->appearanceDirectory];
    }

    public function privateAssetRoots(): array
    {
        return ['appearance'];
    }
}
