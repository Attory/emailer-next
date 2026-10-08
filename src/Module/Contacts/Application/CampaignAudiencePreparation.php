<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Contacts\Application\Contract\CampaignAudiencePreparation as PreparationContract;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Doctrine\DBAL\Connection;

final readonly class CampaignAudiencePreparation implements PreparationContract
{
    public function __construct(private ContactManager $contacts, private ImportManager $imports, private Connection $connection)
    {
    }

    public function prepareImport(AdministrativeActor $actor, string $name, string $source, string $mode, array $mapping): array
    {
        return $this->connection->transactional(function () use ($actor, $name, $source, $mode, $mapping): array {
            $listId = $this->contacts->createList($actor, $name);
            $importId = $this->imports->upload($actor, $listId, $mode, $source);
            try {
                $this->imports->preview($actor, $importId, $mapping);

                return $this->imports->details($actor, $importId);
            } catch (\Throwable $exception) {
                $this->imports->discardPreparedFiles($actor, $importId);
                throw $exception;
            }
        });
    }

    public function discardPreparedFiles(AdministrativeActor $actor, string $id): void
    {
        $this->imports->discardPreparedFiles($actor, $id);
    }

    public function importSummary(AdministrativeActor $actor, string $id): array
    {
        return $this->imports->details($actor, $id);
    }

    public function commitImport(AdministrativeActor $actor, string $id): void
    {
        $this->imports->commit($actor, $id);
    }
}
