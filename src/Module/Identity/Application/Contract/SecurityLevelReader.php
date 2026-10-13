<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface SecurityLevelReader
{
    public function current(): string;

    public function requiresRecentAuthentication(): bool;
}
