<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['native', 'light'], true)) {
    throw new RuntimeException('Recovered-account fault is isolated-only.');
}
require '/tools/vendor/autoload.php';
$runtime = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/updater.env'));
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($runtime['DATABASE_URL']);
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Recovered-account fault refuses non-harness access.');
}
if ('native' === $argv[1]) {
    $files = glob('/rehearsal/native-update-recovery/*/native-operation.json');
    if (1 !== count($files)) {
        throw new RuntimeException('Recovered native operation is not unique.');
    }
    $file = $files[0];
} else {
    $file = '/rehearsal/private/edition-delivery-hold.json';
}
$journal = App\Module\Release\Application\EditionData::read($file);
if ('recovered' !== ($journal['phase'] ?? null)) {
    throw new RuntimeException('Only a durably recovered operation may simulate pending unlock.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
(new App\Module\Release\Application\SourceDatabaseFence($c, '/rehearsal/runtime.env'))->lock(App\Module\Release\Application\SourceDatabaseFence::account($journal['database_account']));
echo "Recovered receipt with pending account unlock injected; business state unchanged.\n";
