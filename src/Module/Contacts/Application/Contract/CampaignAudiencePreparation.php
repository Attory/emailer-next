<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application\Contract;

use App\Module\Identity\Application\Contract\AdministrativeActor;

interface CampaignAudiencePreparation
{
    /** @param array<string, int> $mapping
     * @return array<string, mixed>
     */
    public function prepareImport(AdministrativeActor $actor, string $name, string $source, string $mode, array $mapping): array;

    /** @return array<string, mixed> */
    public function importSummary(AdministrativeActor $actor, string $id): array;

    public function discardPreparedFiles(AdministrativeActor $actor, string $id): void;

    public function commitImport(AdministrativeActor $actor, string $id): void;
}
