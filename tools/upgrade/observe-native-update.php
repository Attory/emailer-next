<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['before', 'after', 'restored'], true)) {
    throw new RuntimeException('Explicit isolated native observation phase is required.');
}
$sourceVersion = getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') ?: '1.0.6';
if (!in_array($sourceVersion, ['1.0.6', '1.1.0'], true)) {
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
    $e = ['tables' => [], 'messages' => $mail['total']];
    foreach (['app_user', 'mfa_credential', 'contact', 'contact_list', 'list_membership', 'campaign', 'campaign_list_selection', 'campaign_audience_snapshot', 'campaign_audience_recipient', 'campaign_content_snapshot', 'campaign_recipient', 'delivery_attempt', 'suppression', 'encrypted_secret', 'recipient_token', 'recipient_token_key', 'recipient_token_key_state'] as $table) {
        $e['tables'][$table] = ['primary' => $c->createSchemaManager()->introspectTable($table)->getPrimaryKey()->getColumns(), 'rows' => $c->fetchAllAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table))];
    }
    $e['master_key_sha256'] = hash_file('sha256', '/rehearsal/keys/master.key');
    $e['schema'] = [];
    foreach ($c->createSchemaManager()->listTableNames() as $table) {
        $ddl = $c->fetchAssociative('SHOW CREATE TABLE '.$c->quoteSingleIdentifier($table));
        $e['schema'][$table] = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', array_values($ddl)[1]);
    }
    file_put_contents($file, json_encode($e, JSON_THROW_ON_ERROR));
    chmod($file, 0o600);
    echo "Protected native 1.0.6 list/history/crypto baseline captured.\n";
    exit;
}
$e = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$verified = 0;
foreach ($e['tables'] as $table => $evidence) {
    if (count($evidence['rows']) !== (int) $c->fetchOne('SELECT COUNT(*) FROM '.$c->quoteSingleIdentifier($table))) {
        throw new RuntimeException('Native historical row count changed in '.$table.'.');
    }
    foreach ($evidence['rows'] as $original) {
        $where = [];
        $values = [];
        foreach ($evidence['primary'] as $key) {
            $where[] = $c->quoteSingleIdentifier($key).' = ?';
            $values[] = $original[$key];
        }
        $actual = $c->fetchAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table).' WHERE '.implode(' AND ', $where), $values);
        if (false === $actual) {
            throw new RuntimeException('Native historical identity disappeared.');
        }
        foreach ($original as $key => $value) {
            if ((null === $value) !== (null === $actual[$key]) || (null !== $value && (string) $value !== (string) $actual[$key])) {
                throw new RuntimeException('Native field changed in '.$table.'.'.$key.'.');
            }
        }
        ++$verified;
    }
}
if ('restored' === $argv[1]) {
    if (array_keys($e['schema']) !== $c->createSchemaManager()->listTableNames()) {
        throw new RuntimeException('Native recovery table inventory changed.');
    }
    foreach ($e['schema'] as $table => $expected) {
        $ddl = $c->fetchAssociative('SHOW CREATE TABLE '.$c->quoteSingleIdentifier($table));
        if ($expected !== preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', array_values($ddl)[1])) {
            throw new RuntimeException('Native recovery schema changed in '.$table.'.');
        }
    }
    if ('/rehearsal/source-releases/'.$sourceVersion !== realpath('/rehearsal/source-releases/current') || 0 !== (int) $c->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'") || $e['messages'] !== $mail['total'] || !hash_equals($e['master_key_sha256'], (string) hash_file('sha256', '/rehearsal/keys/master.key'))) {
        throw new RuntimeException('Native recovery pointer, delivery hold, key or zero-send proof failed.');
    }
    echo json_encode(['native_post_activation_recovery' => true, 'exact_source_schema_restored' => true, 'original_native_rows_verified' => $verified, 'original_key_and_pointer_restored' => true, 'delivery_quiesced' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
    exit;
}
foreach (['campaign_audience_recipient', 'campaign_recipient', 'recipient_token'] as $table) {
    if (false !== $c->fetchOne('SELECT 1 FROM '.$table.' WHERE contact_id IS NULL OR recipient_id <> contact_id LIMIT 1')) {
        throw new RuntimeException('Native contact provenance changed.');
    }
}
if (16 !== (int) $c->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions') || false !== $c->fetchOne("SELECT 1 FROM campaign WHERE audience_model <> 'list'") || $e['messages'] !== $mail['total'] || !hash_equals($e['master_key_sha256'], (string) hash_file('sha256', '/rehearsal/keys/master.key'))) {
    throw new RuntimeException('Native target schema, list workflow, zero-send or key preservation failed.');
}
echo json_encode(['actual_native_update' => true, 'source_version' => $sourceVersion, 'target_version' => '1.2.0', 'verified_original_native_rows' => $verified, 'list_contact_provenance_retained' => true, 'accepted_and_retry_history_unchanged' => true, 'native_crypto_and_mfa_unchanged' => true, 'suppression_case_aliases_preserved' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
