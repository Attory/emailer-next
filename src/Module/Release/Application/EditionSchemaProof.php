<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

final class EditionSchemaProof
{
    /** @return array{tables: array<string, string>, triggers: array<string, string>} */
    public static function capture(Connection $connection): array
    {
        $tables = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $row = $connection->fetchAssociative('SHOW CREATE TABLE '.$connection->quoteSingleIdentifier($table));
            if (false === $row) {
                throw new \RuntimeException('Edition schema proof is unavailable.');
            }
            $tables[$table] = self::normalize(EditionData::text($row['Create Table']));
        }
        ksort($tables);
        $triggers = [];
        foreach (EditionData::list($connection->fetchFirstColumn('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')) as $name) {
            $row = $connection->fetchAssociative('SHOW CREATE TRIGGER '.$connection->quoteSingleIdentifier($name));
            if (false === $row) {
                throw new \RuntimeException('Edition trigger proof is unavailable.');
            }
            $triggers[$name] = self::normalize(EditionData::text($row['SQL Original Statement']));
        }

        return ['tables' => $tables, 'triggers' => $triggers];
    }

    private static function normalize(string $sql): string
    {
        $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+` | AUTO_INCREMENT=[0-9]+/', '', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }
}
