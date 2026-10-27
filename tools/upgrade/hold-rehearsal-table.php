<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['encrypted_secret', 'backup_record', 'release_operation', 'doctrine_migration_versions'], true)) {
    throw new RuntimeException('Fault injection is restricted to isolated rehearsal tables.');
}
require '/tools/vendor/autoload.php';
$url = getenv('DATABASE_URL');
if (!is_string($url)) {
    throw new RuntimeException('Disposable URL is required.');
}
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Fault injector refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$c->executeStatement('LOCK TABLES '.$c->quoteSingleIdentifier($argv[1]).('backup_record' === $argv[1] ? ' WRITE' : ' READ'));
file_put_contents('/rehearsal/fault-lock-ready', 'locked');
chmod('/rehearsal/fault-lock-ready', 0o600);
while (!file_exists('/rehearsal/release-fault-lock')) {
    usleep(10000);
}
$c->executeStatement('UNLOCK TABLES');
echo "Disposable fault lock released.\n";
