<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface AppearanceInitialization
{
    public function initializeFresh(): void;
}
