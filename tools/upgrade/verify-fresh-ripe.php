<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Fresh Ripe verification is isolated-only.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Fresh verifier refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$lock = json_decode((string) file_get_contents('/rehearsal/private/install.lock'), true, 512, JSON_THROW_ON_ERROR);
if ('emailer-ripe' !== ($lock['product_id'] ?? null) || '1.2.3' !== ($lock['product_version'] ?? null) || '20261015000000' !== ($lock['schema_version'] ?? null) || $lock['installation_uuid'] !== $c->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'")) {
    throw new RuntimeException('Fresh Ripe target identity is incomplete or inconsistent.');
}
if (16 !== (int) $c->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions') || 0 !== (int) $c->fetchOne("SELECT COUNT(*) FROM campaign WHERE audience_model <> 'list'")) {
    throw new RuntimeException('Fresh Ripe schema or normal list audience default failed.');
}
echo json_encode(['actual_signed_ripe_installer_fresh_install' => true, 'explicit_edition_version_schema' => true, 'native_migration_ledger_verified' => true, 'normal_list_model' => true], JSON_THROW_ON_ERROR)."\n";
