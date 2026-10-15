<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Cleanup interruption observation is isolated-only.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('EMAILER_TRANSITION_RECOVERY_DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_') || !str_ends_with($p['dbname'], '_edition_recovery')) {
    throw new RuntimeException('Cleanup interruption refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$deadline = microtime(true) + 45;
while (microtime(true) < $deadline) {
    $tables = $c->createSchemaManager()->listTableNames();
    $receipt = json_decode((string) file_get_contents('/rehearsal/private/edition-delivery-hold.json'), true, 512, JSON_THROW_ON_ERROR);
    if ('activated' === ($receipt['phase'] ?? null) && !in_array('app_user', $tables, true) && in_array('encrypted_secret', $tables, true)) {
        echo "Committed partial acceptance cleanup independently observed; target remains held.\n";
        exit;
    }
    usleep(10000);
}
throw new RuntimeException('Actual partial scratch cleanup was not observed.');
