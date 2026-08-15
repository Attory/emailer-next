<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface OwnerSetup
{
    public function create(string $loginIdentifier, string $password): string;

    public function startMfa(string $ownerId, string $installationId): MfaEnrollment;

    public function confirmMfa(string $ownerId, string $installationId, string $code, bool $recoverySaved): void;

    public function skipMfa(string $ownerId): void;
}
