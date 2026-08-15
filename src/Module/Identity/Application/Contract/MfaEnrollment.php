<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

final readonly class MfaEnrollment
{
    /** @param list<string> $recoveryCodes */
    public function __construct(
        public string $seed,
        public array $recoveryCodes,
        public string $provisioningUri,
    ) {
    }
}
