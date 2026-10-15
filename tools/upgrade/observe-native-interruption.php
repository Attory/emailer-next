<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['preparing', 'migrating', 'operation'], true)) {
    throw new RuntimeException('Native interruption observation is isolated-only.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Native interruption refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$deadline = microtime(true) + 45;
while (microtime(true) < $deadline) {
    $files = glob('/rehearsal/native-update-recovery/*/native-operation.json');
    if (1 === count($files)) {
        $journal = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        if ('operation' === $argv[1]) {
            echo $journal['operation_id'];
            exit;
        }
        if ($argv[1] === ($journal['phase'] ?? null) && 'started' === $c->fetchOne('SELECT status FROM release_operation WHERE id=?', [$journal['operation_id']])) {
            if ('migrating' === $argv[1]) {
                $audience = $c->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaign' AND COLUMN_NAME='audience_model'");
                $cipher = $c->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='encrypted_secret' AND COLUMN_NAME='cipher_edition'");
                if ('audience_model' !== $audience || false !== $cipher) {
                    usleep(10000);
                    continue;
                }
            }
            echo "Actual native interruption phase independently observed.\n";
            exit;
        }
    }
    usleep(10000);
}
throw new RuntimeException('Actual native interruption phase was not observed.');
