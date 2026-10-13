<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

interface SensitiveActionAuthorizer
{
    public function assertRecentUnderHigh(SessionInterface $session, string $purpose): void;

    public function expiresAt(SessionInterface $session, string $purpose): int;

    public function isRecent(SessionInterface $session, string $purpose): bool;

    public function confirm(SessionInterface $session, AdministrativeActor $actor, string $password, string $code, string $purpose, ?string $source, ?string $userAgent): bool;
}
