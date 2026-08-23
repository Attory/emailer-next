<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class IdentityBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['app_user', 'mfa_credential', 'user_session', 'login_throttle', 'password_reset_token'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
