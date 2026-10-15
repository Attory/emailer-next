<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['journal', 'preparing', 'migrating', 'recovering', 'operation'], true)) {
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
        if ('journal' === $argv[1] && 'preparing' === ($journal['phase'] ?? null) && false === $c->fetchOne('SELECT id FROM release_operation WHERE id=?', [$journal['operation_id']])) {
            echo "Journal-only native interruption independently observed.\n";
            exit;
        }
        if ('recovering' === $argv[1] && 'recovering' === ($journal['phase'] ?? null) && !$c->createSchemaManager()->tablesExist(['delivery_runtime'])) {
            echo "Committed partial native source restoration independently observed.\n";
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
            $account = $journal['database_account'];
            $locked = $c->fetchOne("SELECT JSON_VALUE(Priv, '$.account_locked') FROM mysql.global_priv WHERE User=? AND Host=?", [$account['name'], $account['host']]);
            if (!in_array($locked, ['true', '1', 1], true)) {
                usleep(10000);
                continue;
            }
            $runtime = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/runtime.env'));
            $app = Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($runtime['DATABASE_URL']));
            try {
                $app->fetchOne('SELECT 1');
                throw new RuntimeException('Concurrent application writer bypassed native account fence.');
            } catch (Doctrine\DBAL\Exception) {
                echo "Concurrent application connection refused by persistent native fence.\n";
            } finally {
                $app->close();
            }
            echo "Actual native interruption phase independently observed.\n";
            exit;
        }
    }
    usleep(10000);
}
throw new RuntimeException('Actual native interruption phase was not observed.');
