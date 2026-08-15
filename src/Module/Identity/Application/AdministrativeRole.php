<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

enum AdministrativeRole: string
{
    case Owner = 'ROLE_OWNER';
    case Administrator = 'ROLE_ADMINISTRATOR';
    case Operator = 'ROLE_OPERATOR';
    case Auditor = 'ROLE_AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Administrator => 'Administrator',
            self::Operator => 'Operator',
            self::Auditor => 'Auditor',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Administrator => 30,
            self::Operator => 20,
            self::Auditor => 10,
        };
    }
}
