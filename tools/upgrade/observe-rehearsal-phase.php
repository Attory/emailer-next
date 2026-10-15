<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['quiescing', 'mutating', 'recovering', 'activated'], true)) {
    throw new RuntimeException('A bounded isolated operation phase is required.');
}
$deadline = microtime(true) + 45;
while (microtime(true) < $deadline) {
    $r = json_decode((string) @file_get_contents('/rehearsal/private/edition-delivery-hold.json'), true);
    if (is_array($r) && $argv[1] === ($r['phase'] ?? null)) {
        if ('mutating' === $argv[1] || 'recovering' === $argv[1]) {
            // Give the installer time to reach the injected locked DDL boundary.
            usleep(3000000);
            require '/tools/vendor/autoload.php';
            $env = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/updater.env'));
            $p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($env['DATABASE_URL']);
            if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
                throw new RuntimeException('Phase observer refuses non-harness database access.');
            }
            $c = Doctrine\DBAL\DriverManager::getConnection($p);
            if ('mutating' === $argv[1]) {
                $role = $c->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user' AND COLUMN_NAME = 'role'");
                $cipher = $c->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'encrypted_secret' AND COLUMN_NAME = 'cipher_edition'");
                if ('role' !== $role || false !== $cipher) {
                    throw new RuntimeException('The fault did not interrupt actual partial schema conversion.');
                }
                echo "Committed partial target schema independently observed.\n";
            } else {
                if (false !== $c->fetchOne("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user'")) {
                    throw new RuntimeException('The recovery fault did not interrupt an actual partial schema restore.');
                }
                echo "Partial source schema restoration independently observed.\n";
            }
        }
        echo "Expected isolated operation phase reached.\n";
        exit;
    }
    usleep(10000);
}
throw new RuntimeException('Expected isolated fault phase was not reached.');
