<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface RecoveryCodeConsumer
{
    public function verify(string $ownerId, string $code, bool $consume): bool;
}
