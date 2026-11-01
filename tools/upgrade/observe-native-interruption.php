<?php

declare(strict_types=1);

$targetVersion = require __DIR__.'/rehearsal-target.php';

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
    if ([] !== $files) {
        sort($files, SORT_STRING);
        $journal = json_decode((string) file_get_contents($files[array_key_last($files)]), true, 512, JSON_THROW_ON_ERROR);
        if (($journal['source_version'] ?? null) !== (getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') ?: '1.0.6') || $targetVersion !== ($journal['target_version'] ?? null)) {
            throw new RuntimeException('Native journal belongs to another release pair.');
        }
        if ('operation' === $argv[1]) {
            echo $journal['operation_id'];
            exit;
        }
        if ('journal' === $argv[1] && 'preparing' === ($journal['phase'] ?? null) && false === $c->fetchOne('SELECT id FROM release_operation WHERE id=?', [$journal['operation_id']])) {
            file_put_contents('/rehearsal/native-journal-only-observed', 'observed');
            chmod('/rehearsal/native-journal-only-observed', 0600);
            echo "Journal-only native interruption independently observed.\n";
            exit;
        }
        if ('recovering' === $argv[1] && 'recovering' === ($journal['phase'] ?? null) && !$c->createSchemaManager()->tablesExist(['delivery_runtime'])) {
            $account = $journal['database_account'];
            if (!in_array($c->fetchOne("SELECT JSON_VALUE(Priv, '$.account_locked') FROM mysql.global_priv WHERE User=? AND Host=?", [$account['name'], $account['host']]), ['true', '1', 1], true)) {
                throw new RuntimeException('Native partial restoration lost its persistent account fence.');
            }
            echo "Committed partial native source restoration independently observed with account fenced.\n";
            exit;
        }
        if ('recovering' !== $argv[1] && $argv[1] === ($journal['phase'] ?? null) && 'started' === $c->fetchOne('SELECT status FROM release_operation WHERE id=?', [$journal['operation_id']])) {
            if ('migrating' === $argv[1]) {
                $nullable = $c->fetchOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaign' AND COLUMN_NAME='template_version_id'");
                $baseline = json_decode((string) file_get_contents('/rehearsal/native-before.json'), true, 512, JSON_THROW_ON_ERROR);
                $sourceLedgerCount = count($baseline['tables']['doctrine_migration_versions']['rows']);
                $legacy105 = '1.0.5' === getenv('EMAILER_REHEARSAL_NATIVE_SOURCE');
                $ddlCommitted = $legacy105
                    ? false !== $c->fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaign_recipient' AND COLUMN_NAME='recipient_id'")
                    : 'YES' === $nullable;
                $actualLedger = $c->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
                $sourceLedger = array_map(static fn (array $row): string => (string) base64_decode($row['version'], true), $baseline['tables']['doctrine_migration_versions']['rows']);
                sort($sourceLedger, SORT_STRING);
                $ledgerMatches = count($actualLedger) === $sourceLedgerCount;
                if ($legacy105) {
                    // This source crosses the separate recipient-identity migration
                    // before the nullable-draft migration. Its committed intermediate
                    // ledger is also a genuine interrupted forward migration.
                    $ledgerMatches = $actualLedger === $sourceLedger || $actualLedger === [...$sourceLedger, 'DoctrineMigrations\\Version20261015000000'];
                }
                if (!$ddlCommitted || !$ledgerMatches || false !== $c->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version=?', ['DoctrineMigrations\\Version20261025000000'])) {
                    usleep(10000);
                    continue;
                }
                echo "Reviewed native DDL committed before its migration ledger write.\n";
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
