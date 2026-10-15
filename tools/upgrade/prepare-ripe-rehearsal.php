<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 5 !== $argc) {
    throw new RuntimeException('Isolated prepare expects a disposable database, source archive and pinned Ripe public key.');
}
[$script, $database, $sourceArchive, $ripePublicKey, $version] = $argv;
if (1 !== preg_match('/^ripe106_[a-z0-9_]{1,40}$/D', $database) || !in_array($version, ['1.0.5', '1.0.6'], true)) {
    throw new RuntimeException('Disposable native identity is invalid.');
}
require '/tools/vendor/autoload.php';
$serverVersion = getenv('MARIADB_SERVER_VERSION') ?: '10.11.19-MariaDB';
$admin = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => 'database', 'user' => 'root', 'password' => 'local-root-only']);
if (false !== $admin->fetchOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database])) {
    throw new RuntimeException('Refusing to reuse an existing rehearsal database.');
}
$admin->executeStatement('CREATE DATABASE '.$admin->quoteSingleIdentifier($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
$service = $database.'_app';
$admin->executeStatement('CREATE USER '.$admin->quote($service)."@'%' IDENTIFIED BY 'isolated-source-only'");
$admin->executeStatement('GRANT ALL PRIVILEGES ON '.$admin->quoteSingleIdentifier($database).'.* TO '.$admin->quote($service)."@'%'");
chown('/rehearsal', 0);
foreach (['private', 'keys', 'source-runtime', 'updater-runtime', 'source-releases', 'source-releases/'.$version] as $relative) {
    if (!mkdir('/rehearsal/'.$relative, 0o700, true)) {
        throw new RuntimeException('Disposable protected state could not be prepared.');
    }
    if (in_array($relative, ['private', 'keys', 'source-runtime'], true)) {
        chown('/rehearsal/'.$relative, 1000);
        chgrp('/rehearsal/'.$relative, 1000);
    } else {
        chmod('/rehearsal/'.$relative, 0o755);
    }
}
$zip = new ZipArchive();
if (true !== $zip->open($sourceArchive) || !$zip->extractTo('/rehearsal/source-releases/'.$version)) {
    throw new RuntimeException('Published source could not be staged.');
}
$zip->close();
symlink('/rehearsal/source-releases/'.$version, '/rehearsal/source-releases/current');
$normalUrl = 'mysql://'.$service.':isolated-source-only@database:3306/'.$database.'?serverVersion='.$serverVersion.'&charset=utf8mb4';
$operatorUrl = 'mysql://root:local-root-only@database:3306/'.$database.'?serverVersion='.$serverVersion.'&charset=utf8mb4';
$common = [
    'APP_ENV' => 'dev', 'APP_DEBUG' => '0', 'APP_SECRET' => 'isolated-rehearsal-only',
    'DEFAULT_URI' => 'https://localhost', 'TRUSTED_HOSTS' => '^localhost$',
    'EMAILER_PRIVATE_DIR' => '/rehearsal/private', 'EMAILER_SECRET_KEY_FILE' => '/rehearsal/keys/master.key',
    'EMAILER_RELEASES_DIR' => '/rehearsal/source-releases', 'EMAILER_RUNTIME_DIR' => '/rehearsal/source-runtime',
    'LOCK_DSN' => 'flock', 'MAILER_DSN' => 'smtp://mailpit:1025', 'MESSENGER_TRANSPORT_DSN' => 'doctrine://default',
];
$source = [...$common, 'DATABASE_URL' => $normalUrl, 'EMAILER_RELEASE_PUBLIC_KEY_FILE' => $ripePublicKey, 'EMAILER_RECOVERY_KEY_FILE' => '/rehearsal/keys/ripe-recovery.key'];
$target = [...$common, 'DATABASE_URL' => $operatorUrl, 'EMAILER_RUNTIME_DIR' => '/rehearsal/updater-runtime', 'EMAILER_RELEASE_PUBLIC_KEY_FILE' => $ripePublicKey, 'EMAILER_RECOVERY_KEY_FILE' => '/rehearsal/keys/ripe-recovery.key', 'EMAILER_RUNTIME_CONFIGURATION_FILE' => '/rehearsal/runtime.env', 'EMAILER_TRANSITION_RECOVERY_DATABASE_URL' => str_replace('/'.$database.'?', '/'.$database.'_edition_recovery?', $operatorUrl)];
foreach (['runtime.env' => $source, 'updater.env' => $target] as $name => $values) {
    $text = '';
    foreach ($values as $key => $value) {
        if (str_contains($value, "'") || str_contains($value, "\n")) {
            throw new RuntimeException('Synthetic launcher input is invalid.');
        }
        $text .= $key."='".$value."'\n";
    }
    file_put_contents('/rehearsal/'.$name, $text);
    chmod('/rehearsal/'.$name, 'runtime.env' === $name ? 0o640 : 0o600);
    if ('runtime.env' === $name) {
        chgrp('/rehearsal/'.$name, 1000);
    }
}
$fixture = json_decode((string) file_get_contents('/tools/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
$password = $fixture['administrator']['password'];
$inputs = ['database', '3306', $database, $service, 'isolated-source-only', 'Synthetic Upgrade Rehearsal', 'https://localhost', 'en_AU', 'Australia/Sydney', 'admin@example.test', $password, $password, 'mailpit', '1025', 'sender@example.test', 'Synthetic Fixture', 'supervised', 'yes'];
file_put_contents('/rehearsal/install-input', implode("\n", $inputs)."\n");
chmod('/rehearsal/install-input', 0o640);
chgrp('/rehearsal/install-input', 1000);
echo "Disposable Ripe installer prerequisites prepared.\n";
