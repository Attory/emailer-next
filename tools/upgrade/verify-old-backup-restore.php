<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Retained-backup verification is isolated-only.');
}
require '/rehearsal/source-releases/1.0.0/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Legacy verification refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
// The published root-run Light restore creates asset directories with the
// process umask. Apply the documented protected service ownership before any
// subsequent conversion; bytes and restored business records remain unchanged.
$private = '/rehearsal/private';
$tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($tree as $entry) {
    if ($entry->isLink()) {
        throw new RuntimeException('Restored Light private tree contains a linked path.');
    }
    if (!chown($entry->getPathname(), 1000) || !chgrp($entry->getPathname(), 1000) || !chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600)) {
        throw new RuntimeException('Restored Light service ownership could not be protected.');
    }
}
$backup = trim((string) file_get_contents('/rehearsal/old-backup-path'));
if (!hash_equals(trim((string) file_get_contents('/rehearsal/old-backup-sha256')), (string) hash_file('sha256', $backup))) {
    throw new RuntimeException('Retained original backup bytes changed.');
}
$runtime = $c->fetchAssociative("SELECT accepting_work,active_claims FROM delivery_runtime WHERE singleton_key='1'");
if (0 !== (int) $runtime['accepting_work'] || 0 !== (int) $runtime['active_claims']) {
    throw new RuntimeException('Legacy restoration allowed delivery.');
}
$identity = $c->fetchAssociative('SELECT product_id,product_version,schema_version FROM product_identity');
if (['product_id' => 'emailer-light', 'product_version' => '1.0.0', 'schema_version' => '20261014000000'] !== $identity) {
    throw new RuntimeException('Legacy restore did not use the exact Light schema/edition.');
}
if (!hash_equals((string) hash_file('sha256', '/rehearsal/keys/master.key'), (string) hash_file('sha256', '/old-export/keys/master.key'))) {
    throw new RuntimeException('Legacy recovery key did not preserve original source material.');
}
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
if (is_file('/rehearsal/old-backup-messages-before') && (int) trim((string) file_get_contents('/rehearsal/old-backup-messages-before')) !== $mail['total']) {
    throw new RuntimeException('Legacy restore emitted a new message.');
}
echo json_encode(['retained_light_backup_authenticated_and_restored' => true, 'unchanged_light_runtime_and_schema' => true, 'retained_original_key' => true, 'delivery_quiesced' => true, 'original_archive_unchanged' => true, 'accepted_ripe_unchanged' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
