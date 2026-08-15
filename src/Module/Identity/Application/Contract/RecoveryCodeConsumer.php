<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface RecoveryCodeConsumer
{
    public function consume(string $ownerId, string $code): bool;
}
