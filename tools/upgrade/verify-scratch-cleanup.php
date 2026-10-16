<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Rehearsal cleanup verification is isolated-only.');
}
require '/rehearsal/source-releases/ripe-1.1.0/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('EMAILER_TRANSITION_RECOVERY_DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_') || !str_ends_with($p['dbname'], '_edition_recovery')) {
    throw new RuntimeException('Cleanup evidence refuses non-harness access.');
}
$scratch = Doctrine\DBAL\DriverManager::getConnection($p);
if ([] !== $scratch->createSchemaManager()->listTableNames() || false !== $scratch->fetchOne('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() LIMIT 1')) {
    throw new RuntimeException('Accepted transition retained its unencrypted rehearsal copy.');
}
echo json_encode(['accepted_rehearsal_database_copy_removed' => true, 'protected_recovery_assets_retained' => true], JSON_THROW_ON_ERROR)."\n";
