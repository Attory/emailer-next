<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class OwnerPasswordSubject implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
