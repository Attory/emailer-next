<?php

declare(strict_types=1);

namespace App\Module\Suppression\Application\Contract;

use App\Module\Identity\Application\Contract\AdministrativeActor;

interface AddressCorrectionSuppression
{
    public function preserve(AdministrativeActor $actor, string $previousAddress, string $newAddress): void;
}
