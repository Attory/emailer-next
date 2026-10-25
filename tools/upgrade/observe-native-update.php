<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['before', 'after', 'restored'], true)) {
    throw new RuntimeException('Explicit isolated native observation phase is required.');
}
$sourceVersion = getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') ?: '1.0.6';
$sourceName = getenv('EMAILER_REHEARSAL_NATIVE_SOURCE_ROOT') ?: $sourceVersion;
$converted = '1' === getenv('EMAILER_REHEARSAL_NATIVE_CONVERTED');
if (!in_array($sourceVersion, ['1.0.6', '1.1.0', '1.2.1', '1.2.2', '1.3.0', '1.3.1'], true) || !in_array($sourceName, [$sourceVersion, 'ripe-'.$sourceVersion], true)) {
    throw new RuntimeException('Unsupported isolated native source.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Native observer refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
$file = '/rehearsal/native-before.json';
if ('before' === $argv[1]) {
    if (realpath('/rehearsal/source-releases/current') !== '/rehearsal/source-releases/'.$sourceName) {
        throw new RuntimeException('Native source root does not match its real installation.');
    }
    if ($converted && (!$c->createSchemaManager()->tablesExist(['ripe_edition_transition', 'product_identity']) || 0 === (int) $c->fetchOne("SELECT COUNT(*) FROM campaign WHERE audience_model='campaign_owned'") || 0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM contact') || 0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM list_membership'))) {
        throw new RuntimeException('Converted-source fixture must preserve genuine campaign-owned recipients without manufactured contacts.');
    }
    $e = ['tables' => [], 'messages' => $mail['total'], 'source_version' => $sourceVersion, 'source_root' => $sourceName, 'converted' => $converted];
    foreach ($c->createSchemaManager()->listTableNames() as $table) {
        $primary = $c->createSchemaManager()->introspectTable($table)->getPrimaryKey();
        if (null === $primary) {
            throw new RuntimeException('Native fixture table has no stable row identity.');
        }
        $rows = [];
        foreach ($c->fetchAllAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table)) as $row) {
            $encoded = [];
            foreach ($row as $key => $value) {
                $encoded[$key] = null === $value ? null : base64_encode((string) $value);
            }
            $rows[] = $encoded;
        }
        $e['tables'][$table] = ['primary' => $primary->getColumns(), 'rows' => $rows];
    }
    $e['master_key_sha256'] = hash_file('sha256', '/rehearsal/keys/master.key');
    $e['schema'] = [];
    foreach ($c->createSchemaManager()->listTableNames() as $table) {
        $ddl = $c->fetchAssociative('SHOW CREATE TABLE '.$c->quoteSingleIdentifier($table));
        $e['schema'][$table] = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', array_values($ddl)[1]);
    }
    file_put_contents($file, json_encode($e, JSON_THROW_ON_ERROR));
    chmod($file, 0o600);
    echo "Protected native business, audit, recovery and crypto baseline captured.\n";
    exit;
}
$e = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if ($e['source_version'] !== $sourceVersion || $e['source_root'] !== $sourceName || $e['converted'] !== $converted) {
    throw new RuntimeException('Native preservation evidence belongs to another fixture.');
}
$verified = 0;
foreach ($e['tables'] as $table => $evidence) {
    $count = (int) $c->fetchOne('SELECT COUNT(*) FROM '.$c->quoteSingleIdentifier($table));
    $appendOnly = in_array($table, ['release_operation', 'backup_record', 'audit_event'], true) || ('after' === $argv[1] && 'doctrine_migration_versions' === $table);
    if (($appendOnly && $count < count($evidence['rows'])) || (!$appendOnly && $count !== count($evidence['rows']))) {
        throw new RuntimeException('Native historical row count changed in '.$table.'.');
    }
    foreach ($evidence['rows'] as $original) {
        $where = [];
        $values = [];
        foreach ($evidence['primary'] as $key) {
            $where[] = $c->quoteSingleIdentifier($key).' = ?';
            $values[] = base64_decode($original[$key], true);
        }
        $actual = $c->fetchAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table).' WHERE '.implode(' AND ', $where), $values);
        if (false === $actual) {
            throw new RuntimeException('Native historical identity disappeared.');
        }
        foreach ($original as $key => $encoded) {
            $value = null === $encoded ? null : base64_decode($encoded, true);
            if ('delivery_runtime' === $table && 'accepting_work' === $key) {
                if ('0' !== (string) $actual[$key]) {
                    throw new RuntimeException('Native update or recovery lifted its delivery hold.');
                }
                continue;
            }
            if ('delivery_runtime' === $table && 'updated_at' === $key) {
                if ((string) $actual[$key] < $value) {
                    throw new RuntimeException('Native delivery metadata time moved backward.');
                }
                continue;
            }
            if ('product_identity' === $table && 'schema_version' === $key) {
                if (('after' === $argv[1] ? '20261025000000' : $value) !== $actual[$key]) {
                    throw new RuntimeException('Native product identity schema is inconsistent.');
                }
                continue;
            }
            if ('product_identity' === $table && 'product_version' === $key) {
                if (('after' === $argv[1] ? '1.3.3' : $sourceVersion) !== $actual[$key]) {
                    throw new RuntimeException('Native product identity version is inconsistent.');
                }
                continue;
            }
            if ((null === $value) !== (null === $actual[$key]) || (null !== $value && (string) $value !== (string) $actual[$key])) {
                throw new RuntimeException('Native field changed in '.$table.'.'.$key.'.');
            }
        }
        ++$verified;
    }
}
if (array_keys($e['schema']) !== $c->createSchemaManager()->listTableNames()) {
    throw new RuntimeException('Native table inventory changed.');
}
foreach ($e['schema'] as $table => $expected) {
    $ddl = $c->fetchAssociative('SHOW CREATE TABLE '.$c->quoteSingleIdentifier($table));
    if ('after' === $argv[1] && 'campaign' === $table) {
        $expected = str_replace('`template_version_id` char(36) NOT NULL,', '`template_version_id` char(36) DEFAULT NULL,', $expected, $changes);
        if (1 !== $changes) {
            throw new RuntimeException('Source campaign nullable migration proof is not exact.');
        }
    }
    if ($expected !== preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', array_values($ddl)[1])) {
        throw new RuntimeException('Native schema changed in '.$table.'.');
    }
}
if ($e['messages'] !== $mail['total'] || !hash_equals($e['master_key_sha256'], (string) hash_file('sha256', '/rehearsal/keys/master.key')) || 0 !== (int) $c->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('Native zero-send, key preservation or delivery hold proof failed.');
}
if ('restored' === $argv[1]) {
    if ('/rehearsal/source-releases/'.$sourceName !== realpath('/rehearsal/source-releases/current')) {
        throw new RuntimeException('Native recovery did not retain its actual original root.');
    }
    echo json_encode(['native_post_activation_recovery' => true, 'exact_source_schema_restored' => true, 'original_rows_verified' => $verified, 'source_root' => $sourceName, 'delivery_quiesced' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($converted) {
    if (0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM contact') || 0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM list_membership') || 0 === (int) $c->fetchOne("SELECT COUNT(*) FROM campaign WHERE audience_model='campaign_owned'")) {
        throw new RuntimeException('Converted native update manufactured reusable contacts or lost its audience model.');
    }
} else {
    foreach (['campaign_audience_recipient', 'campaign_recipient', 'recipient_token'] as $table) {
        if (false !== $c->fetchOne('SELECT 1 FROM '.$table.' WHERE contact_id IS NULL OR recipient_id <> contact_id LIMIT 1')) {
            throw new RuntimeException('Native contact provenance changed.');
        }
    }
    if (false !== $c->fetchOne("SELECT 1 FROM campaign WHERE audience_model <> 'list'")) {
        throw new RuntimeException('Native list workflow changed.');
    }
}
if (17 !== (int) $c->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions') || '/rehearsal/source-releases/1.3.3' !== realpath('/rehearsal/source-releases/current')) {
    throw new RuntimeException('Native target schema or active root is inconsistent.');
}
echo json_encode(['actual_native_update' => true, 'source_version' => $sourceVersion, 'target_version' => '1.3.3', 'converted_source' => $converted, 'original_business_audit_and_backup_rows_verified' => $verified, 'original_crypto_unchanged' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
