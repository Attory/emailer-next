<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Fault release is isolated-only.');
}
require '/tools/vendor/autoload.php';
$runtime = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/updater.env'));
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($runtime['DATABASE_URL']);
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Fault cleanup refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
foreach (['emailer-native-release-'.substr(hash('sha256', $p['dbname']), 0, 40), 'emailer-edition-'.substr(hash('sha256', $p['dbname']), 0, 48)] as $name) {
    $owner = $c->fetchOne('SELECT IS_USED_LOCK(?)', [$name]);
    if (null !== $owner && false !== $owner) {
        $c->executeStatement('KILL CONNECTION '.(int) $owner);
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            if (null === $c->fetchOne('SELECT IS_USED_LOCK(?)', [$name])) {
                break;
            }
            usleep(100000);
        }
        if (null !== $c->fetchOne('SELECT IS_USED_LOCK(?)', [$name])) {
            throw new RuntimeException('Killed updater session still owns the operation; retain the fault lock.');
        }
    }
}
if (is_file('/rehearsal/native-journal-only-observed')) {
    $files = glob('/rehearsal/native-update-recovery/*/native-operation.json');
    if (1 !== count($files)) {
        throw new RuntimeException('Journal-only fault lost its exact operation.');
    }
    $journal = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
    if (false !== $c->fetchOne('SELECT id FROM release_operation WHERE id=?', [$journal['operation_id']])) {
        throw new RuntimeException('Journal-only fault unexpectedly committed an operation row.');
    }
    echo "Journal-only operation row remains absent after killed server-session cleanup.\n";
}
file_put_contents('/rehearsal/release-fault-lock', 'release');
chmod('/rehearsal/release-fault-lock', 0o600);
