<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

final class EditionIntegrity
{
    public static function assertForeignKeys(Connection $connection): void
    {
        $constraints = [];
        foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION') as $row) {
            $table = EditionData::text($row['TABLE_NAME']);
            $name = EditionData::text($row['CONSTRAINT_NAME']);
            $parent = EditionData::text($row['REFERENCED_TABLE_NAME']);
            $column = EditionData::text($row['COLUMN_NAME']);
            $reference = EditionData::text($row['REFERENCED_COLUMN_NAME']);
            $constraints[$table.'-'.$name]['table'] = $table;
            $constraints[$table.'-'.$name]['parent'] = $parent;
            $constraints[$table.'-'.$name]['joins'][] = 'c.'.$connection->quoteSingleIdentifier($column).' = p.'.$connection->quoteSingleIdentifier($reference);
            $constraints[$table.'-'.$name]['nonnull'][] = 'c.'.$connection->quoteSingleIdentifier($column).' IS NOT NULL';
            $constraints[$table.'-'.$name]['missing'] = 'p.'.$connection->quoteSingleIdentifier($reference).' IS NULL';
        }
        foreach ($constraints as $constraint) {
            $sql = 'SELECT 1 FROM '.$connection->quoteSingleIdentifier($constraint['table']).' c LEFT JOIN '.$connection->quoteSingleIdentifier($constraint['parent']).' p ON '.implode(' AND ', $constraint['joins']).' WHERE '.implode(' AND ', $constraint['nonnull']).' AND '.$constraint['missing'].' LIMIT 1';
            if (false !== $connection->fetchOne($sql)) {
                throw new \RuntimeException('Edition referential-integrity verification failed.');
            }
        }
    }
}
