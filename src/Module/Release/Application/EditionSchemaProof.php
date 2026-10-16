<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

final class EditionSchemaProof
{
    /** @return array{tables: array<string, string>, triggers: array<string, string>} */
    public static function capture(Connection $connection, bool $canonicalMetadata = false): array
    {
        $tables = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $row = $connection->fetchAssociative('SHOW CREATE TABLE '.$connection->quoteSingleIdentifier($table));
            if (false === $row) {
                throw new \RuntimeException('Edition schema proof is unavailable.');
            }
            $tables[$table] = $canonicalMetadata ? self::baselineTable($table, EditionData::text($row['Create Table'])) : self::normalize(EditionData::text($row['Create Table']));
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

    /** Only Doctrine's ASCII version ledger may inherit a tested database default. */
    public static function baselineTable(string $table, string $sql): string
    {
        $sql = self::normalize($sql);
        if ('doctrine_migration_versions' === $table) {
            $sql = preg_replace('/COLLATE=utf8mb4_(?:general_ci|unicode_ci|bin)\\b/', 'COLLATE=utf8mb4_bin', $sql) ?? $sql;
        }

        return $sql;
    }

    private static function normalize(string $sql): string
    {
        $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+` | AUTO_INCREMENT=[0-9]+/', '', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }
}
