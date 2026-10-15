<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface LightSecretInspection
{
    /** Authenticate every retained Light envelope without returning plaintext. */
    public function verifyLight(string $installationId): int;
}
