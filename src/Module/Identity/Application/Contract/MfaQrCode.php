<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface MfaQrCode
{
    public function dataUri(#[\SensitiveParameter] string $provisioningUri): string;
}
