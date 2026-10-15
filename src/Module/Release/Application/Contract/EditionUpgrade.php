<?php

declare(strict_types=1);

namespace App\Module\Release\Application\Contract;

interface EditionUpgrade
{
    /** @return array<string, mixed> */
    public function run(string $action, string $targetPackage, string $sourcePackage, string $recoveryDirectory, ?string $recoveryPassphrase = null, ?string $exportDirectory = null): array;
}
