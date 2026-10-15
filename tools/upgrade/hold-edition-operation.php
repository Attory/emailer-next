<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Operation conflict fixture is isolated-only.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Operation fixture refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$name = 'emailer-edition-'.substr(hash('sha256', $p['dbname']), 0, 48);
if (1 !== (int) $c->fetchOne('SELECT GET_LOCK(?, 0)', [$name])) {
    throw new RuntimeException('Synthetic operation lock could not be acquired.');
}
file_put_contents('/rehearsal/conflicting-operation-ready', 'held');
chmod('/rehearsal/conflicting-operation-ready', 0o600);
while (!file_exists('/rehearsal/release-conflicting-operation')) {
    usleep(10000);
}
$c->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
echo "Synthetic conflicting operation released.\n";
