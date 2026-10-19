<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface PublicDnsResolver
{
    /** @return list<string> */
    public function resolve(string $host, float $deadline): array;
}
