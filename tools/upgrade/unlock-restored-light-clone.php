<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Clone acceptance is isolated-only.');
}
require '/tools/vendor/autoload.php';
$e = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/runtime.env'));
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($e['DATABASE_URL']);
if ('database' !== ($p['host'] ?? null) || !str_ends_with($p['dbname'] ?? '', '_legacy') || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Clone acceptance refuses any active installation.');
}
$c = Doctrine\DBAL\DriverManager::getConnection([...$p, 'user' => 'root', 'password' => 'local-root-only']);
if (0 !== (int) $c->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('Clone is not durably quiesced.');
}
$c->executeStatement('ALTER USER '.$c->quote($p['user'])."@'%' ACCOUNT UNLOCK");
echo "Isolated restored Light clone accepted; delivery remains off.\n";
