<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface RipeRecoveryKeyProvisioning
{
    public function prepareRipeRecovery(string $passphrase): void;
}
