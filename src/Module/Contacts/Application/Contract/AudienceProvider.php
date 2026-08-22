<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application\Contract;

interface AudienceProvider
{
    /**
     * @param list<string> $listIds
     *
     * @return list<array{contact_id: string, email: string, profile: array<string, bool|float|int|string|null>}>
     */
    public function snapshot(array $listIds): array;
}
