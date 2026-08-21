<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

enum AdministrativeAccessLevel: int
{
    case Auditor = 10;
    case Operator = 20;
    case Administrator = 30;
    case Owner = 40;
}
