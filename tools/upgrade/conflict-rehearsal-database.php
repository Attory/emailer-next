<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['prepare', 'cleanup'], true)) {
    throw new RuntimeException('Preparation fault injection is isolated-only.');
}
require '/rehearsal/target/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('EMAILER_TRANSITION_RECOVERY_DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_') || !str_ends_with($p['dbname'], '_edition_recovery')) {
    throw new RuntimeException('Preparation fault refuses non-harness access.');
}
$database = $p['dbname'];
$server = $p;
unset($server['dbname']);
$admin = Doctrine\DBAL\DriverManager::getConnection($server);
if ('prepare' === $argv[1]) {
    $admin->executeStatement('CREATE DATABASE IF NOT EXISTS '.$admin->quoteSingleIdentifier($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
}
$scratch = Doctrine\DBAL\DriverManager::getConnection($p);
if ('prepare' === $argv[1]) {
    if ([] !== $scratch->createSchemaManager()->listTableNames()) {
        throw new RuntimeException('Fault injector refuses an existing rehearsal copy.');
    }
    $scratch->executeStatement('CREATE TABLE unrelated_rehearsal_marker (id INT PRIMARY KEY)');
    exit;
}
if (['unrelated_rehearsal_marker'] !== $scratch->createSchemaManager()->listTableNames()) {
    throw new RuntimeException('Unowned rehearsal database was changed by the installer.');
}
$receipt = json_decode((string) file_get_contents('/rehearsal/private/edition-delivery-hold.json'), true, 512, JSON_THROW_ON_ERROR);
if ('recovered' !== ($receipt['phase'] ?? null) || ('/rehearsal/source-releases/'.(getenv('EMAILER_REHEARSAL_LIGHT_VERSION') ?: '1.0.0')) !== realpath('/rehearsal/source-releases/current')) {
    throw new RuntimeException('Pre-mutation failure did not recover unchanged Light.');
}
$normal = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/runtime.env'));
$app = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($normal['DATABASE_URL']);
$locked = $admin->fetchOne("SELECT JSON_UNQUOTE(JSON_EXTRACT(Priv, '$.account_locked')) FROM mysql.global_priv WHERE User=? AND Host='%'", [$app['user']]);
if (in_array((string) $locked, ['1', 'true'], true)) {
    throw new RuntimeException('Pre-mutation recovery left the original application account fenced.');
}
$scratch->executeStatement('DROP TABLE unrelated_rehearsal_marker');
echo json_encode(['pre_mutation_preparation_failure_recovered' => true, 'unowned_scratch_database_unchanged' => true, 'original_account_restored' => true, 'delivery_hold_retained' => true], JSON_THROW_ON_ERROR)."\n";
