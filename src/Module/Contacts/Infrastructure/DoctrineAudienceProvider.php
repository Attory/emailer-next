<?php

declare(strict_types=1);

namespace App\Module\Contacts\Infrastructure;

use App\Module\Contacts\Application\Contract\AudienceProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DoctrineAudienceProvider implements AudienceProvider
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $listIds
     *
     * @return list<array{contact_id: string, email: string, profile: array<string, bool|float|int|string|null>}>
     */
    public function snapshot(array $listIds): array
    {
        $listIds = array_values(array_unique($listIds));
        if ([] === $listIds || \count($listIds) > 100 || [] !== array_filter($listIds, static fn (string $id): bool => 1 !== preg_match('/^[0-9a-f-]{36}$/', $id))) {
            throw new \InvalidArgumentException('Campaign audience selection is invalid.');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT c.id, c.email_canonical, c.display_name, c.first_name, c.last_name, c.locale, c.time_zone FROM contact c INNER JOIN list_membership m ON m.contact_id = c.id INNER JOIN contact_list l ON l.id = m.list_id WHERE m.list_id IN (?) AND c.status = ? AND c.archived_at IS NULL AND l.archived_at IS NULL ORDER BY c.id',
            [$listIds, 'active'],
            [ArrayParameterType::STRING, ParameterType::STRING],
        );
        $result = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id']) || !\is_string($row['email_canonical'])) {
                throw new \UnexpectedValueException('Campaign audience row is invalid.');
            }
            $result[] = [
                'contact_id' => $row['id'],
                'email' => $row['email_canonical'],
                'profile' => [
                    'display_name' => \is_string($row['display_name']) ? $row['display_name'] : null,
                    'first_name' => \is_string($row['first_name']) ? $row['first_name'] : null,
                    'last_name' => \is_string($row['last_name']) ? $row['last_name'] : null,
                    'locale' => \is_string($row['locale']) ? $row['locale'] : null,
                    'time_zone' => \is_string($row['time_zone']) ? $row['time_zone'] : null,
                ],
            ];
        }

        return $result;
    }
}
