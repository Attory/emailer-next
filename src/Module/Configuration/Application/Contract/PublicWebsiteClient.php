<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface PublicWebsiteClient
{
    /** @return array{url: string, body: string, type: string} */
    public function get(string $url): array;
}
