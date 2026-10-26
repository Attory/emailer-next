<?php

declare(strict_types=1);

namespace Emailer\RehearsalObserver;

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !\in_array($argv[1], ['quiescing', 'mutating', 'recovering', 'activated'], true)) {
    throw new \RuntimeException('A bounded isolated operation phase is required.');
}
$root = getenv('EMAILER_REHEARSAL_ROOT') ?: '/rehearsal';
if (!str_starts_with($root, '/') || !is_dir($root)) {
    throw new \RuntimeException('An isolated rehearsal directory is required.');
}
$deadline = hrtime(true) + 45000000000;
$connection = null;
while (hrtime(true) < $deadline) {
    $r = json_decode((string) @file_get_contents($root.'/private/edition-delivery-hold.json'), true);
    if (\is_array($r) && $argv[1] === ($r['phase'] ?? null)) {
        if ('mutating' === $argv[1] || 'recovering' === $argv[1]) {
            // Observe the actual DDL boundary within the same deadline. The
            // recovery-key KDF and earlier DDL need not finish in three seconds.
            if (null === $connection) {
                require \dirname(__DIR__, 2).'/vendor/autoload.php';
                $env = (new \Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents($root.'/updater.env'));
                $p = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($env['DATABASE_URL']);
                if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
                    throw new \RuntimeException('Phase observer refuses non-harness database access.');
                }
                $p['driverOptions'][\PDO::ATTR_TIMEOUT] = 2;
                $connection = \Doctrine\DBAL\DriverManager::getConnection($p);
                $connection->executeStatement('SET SESSION max_statement_time = 1');
            }
            if ('mutating' === $argv[1]) {
                $role = $connection->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user' AND COLUMN_NAME = 'role'");
                $cipher = $connection->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'encrypted_secret' AND COLUMN_NAME = 'cipher_edition'");
                if (false !== $cipher) {
                    throw new \RuntimeException('The fault did not interrupt actual partial schema conversion.');
                }
                if ('role' !== $role) {
                    usleep(10000);
                    continue;
                }
                echo "Committed partial target schema independently observed.\n";
            } else {
                if (false !== $connection->fetchOne("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user'")) {
                    usleep(10000);
                    continue;
                }
                echo "Partial source schema restoration independently observed.\n";
            }
        }
        if (hrtime(true) >= $deadline) {
            break;
        }
        echo "Expected isolated operation phase reached.\n";
        exit;
    }
    usleep(10000);
}
throw new \RuntimeException('Expected isolated fault phase was not reached.');
