<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['before', 'after', 'restored'], true)) {
    throw new RuntimeException('Isolated preservation observer requires an explicit phase.');
}
require '/tools/vendor/autoload.php';
$url = getenv('DATABASE_URL');
if (!is_string($url)) {
    throw new RuntimeException('Disposable source URL is required.');
}
$parameters = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if ('database' !== ($parameters['host'] ?? null) || !str_starts_with($parameters['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Observer refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($parameters);
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
$path = '/rehearsal/preservation-before.json';
if ('before' === $argv[1]) {
    $baseline = ['tables' => [], 'private_files' => [], 'messages' => $mail['total']];
    foreach ($c->createSchemaManager()->listTableNames() as $table) {
        $key = $c->createSchemaManager()->introspectTable($table)->getPrimaryKey();
        if (null === $key) {
            throw new RuntimeException('Source table lacks a stable primary key.');
        }
        $baseline['tables'][$table] = ['primary' => $key->getColumns(), 'rows' => $c->fetchAllAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table))];
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/rehearsal/private', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $baseline['private_files'][substr($file->getPathname(), strlen('/rehearsal/private/'))] = hash_file('sha256', $file->getPathname());
        }
    }
    $baseline['master_key_sha256'] = hash_file('sha256', '/rehearsal/keys/master.key');
    file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));
    chmod($path, 0o600);
    echo "Protected historical baseline captured before the operation.\n";
    exit;
}
$before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$verifiedRows = 0;
foreach ($before['tables'] as $table => $evidence) {
    $expectedAdditions = 'after' === $argv[1] ? ['audit_event' => 1, 'backup_record' => 1] : [];
    if (!in_array($table, ['product_identity', 'doctrine_migration_versions'], true) && count($evidence['rows']) + ($expectedAdditions[$table] ?? 0) !== (int) $c->fetchOne('SELECT COUNT(*) FROM '.$c->quoteSingleIdentifier($table))) {
        throw new RuntimeException('Unexpected additions or deletions in '.$table.'.');
    }
    if ('after' === $argv[1] && in_array($table, ['product_identity', 'doctrine_migration_versions'], true)) {
        continue;
    }
    foreach ($evidence['rows'] as $original) {
        $where = [];
        $values = [];
        foreach ($evidence['primary'] as $column) {
            $where[] = $c->quoteSingleIdentifier($column).' = ?';
            $values[] = $original[$column];
        }
        $actual = $c->fetchAssociative('SELECT * FROM '.$c->quoteSingleIdentifier($table).' WHERE '.implode(' AND ', $where), $values);
        if (false === $actual) {
            throw new RuntimeException('Historical identity disappeared from '.$table.'.');
        }
        foreach ($original as $column => $value) {
            if ('delivery_runtime' === $table && 'accepting_work' === $column) {
                if ('0' !== (string) $actual[$column]) {
                    throw new RuntimeException('Recovery or transition released delivery unexpectedly.');
                }
                continue;
            }
            if ('after' === $argv[1] && 'app_user' === $table && 'singleton_key' === $column) {
                continue;
            }
            if ('after' === $argv[1] && 'user_session' === $table && 'revoked_at' === $column && null === $value) {
                if (null === $actual[$column]) {
                    throw new RuntimeException('A Light login session retained Ripe authority.');
                }
                continue;
            }
            if (!array_key_exists($column, $actual) || (null === $value) !== (null === $actual[$column]) || (null !== $value && (string) $value !== (string) $actual[$column])) {
                throw new RuntimeException('Historical field preservation failed in '.$table.'.'.$column.'.');
            }
        }
        ++$verifiedRows;
    }
}
foreach ($before['private_files'] as $relative => $digest) {
    if ('after' === $argv[1] && 'install.lock' === $relative) {
        continue;
    }
    if (!is_file('/rehearsal/private/'.$relative) || !hash_equals($digest, (string) hash_file('sha256', '/rehearsal/private/'.$relative))) {
        throw new RuntimeException('Historical private-state fingerprint changed.');
    }
}
if (!hash_equals($before['master_key_sha256'], (string) hash_file('sha256', '/rehearsal/keys/master.key')) || $before['messages'] !== $mail['total']) {
    throw new RuntimeException('Master-key preservation or zero-message evidence failed.');
}
$attempts = $before['tables']['delivery_attempt']['rows'];
if (count($attempts) !== (int) $c->fetchOne('SELECT COUNT(*) FROM delivery_attempt')) {
    throw new RuntimeException('An operation created a delivery attempt.');
}
if ('after' === $argv[1]) {
    foreach (['contact', 'contact_attribute', 'contact_field_definition', 'contact_import', 'contact_import_candidate', 'contact_list', 'list_membership', 'campaign_list_selection', 'mfa_credential', 'password_reset_token'] as $table) {
        if (0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM '.$table)) {
            throw new RuntimeException('A Ripe-only structure was manufactured.');
        }
    }
    $identity = $c->fetchAssociative("SELECT product_id, product_version, schema_version FROM product_identity WHERE singleton_key = '1'");
    if (['product_id' => 'emailer-ripe', 'product_version' => '1.2.1', 'schema_version' => '20261015000000'] !== $identity) {
        throw new RuntimeException('Target edition identity is not explicit.');
    }
    $receipt = $c->fetchAssociative('SELECT source_identity_json, source_migration_json FROM ripe_edition_transition');
    if (false === $receipt || json_decode($receipt['source_identity_json'], true, 512, JSON_THROW_ON_ERROR) !== $before['tables']['product_identity']['rows'][0] || json_decode($receipt['source_migration_json'], true, 512, JSON_THROW_ON_ERROR) !== $before['tables']['doctrine_migration_versions']['rows'][0]) {
        throw new RuntimeException('Original migration or identity evidence was not retained.');
    }
    if (false !== $c->fetchOne("SELECT 1 FROM app_user WHERE role <> 'ROLE_OWNER' OR active <> 1 OR disabled_at IS NOT NULL OR mfa_changed_at IS NOT NULL") || 'high' !== $c->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'")) {
        throw new RuntimeException('Owner/High/unconfigured MFA mapping failed.');
    }
}
echo json_encode(['phase' => $argv[1], 'verified_source_tables' => count($before['tables']), 'verified_historical_rows' => $verifiedRows, 'historical_private_files' => count($before['private_files']), 'new_delivery_attempts' => 0, 'new_emitted_messages' => 0, 'master_key_preserved' => true], JSON_THROW_ON_ERROR)."\n";
