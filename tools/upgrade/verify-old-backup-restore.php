<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Retained-backup verification is isolated-only.');
}
require '/rehearsal/source-releases/current/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Legacy verification refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
// Use the original edition signing identity and its bounded version profiles;
// the new incomplete-draft source has two migrations and a nullable template.
require_once '/tools/src/Module/Release/Application/LightSourcePackage.php';
$sourceVersion = getenv('EMAILER_REHEARSAL_LIGHT_VERSION') ?: '1.0.0';
$sourceSchema = App\Module\Release\Application\LightSourcePackage::schemaFor($sourceVersion);
if ($sourceVersion !== App\Module\Release\Application\LightSourcePackage::authenticate('/published-light/emailer-light-'.$sourceVersion.'.zip')) {
    throw new RuntimeException('Retained-backup source does not match its authenticated release.');
}
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
if (['product_id' => 'emailer-light', 'product_version' => $sourceVersion, 'schema_version' => $sourceSchema] !== $identity) {
    throw new RuntimeException('Legacy restore did not use the exact Light schema/edition.');
}
if (App\Module\Release\Application\LightSourcePackage::migrationsFor($sourceVersion) !== $c->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version')
    || (in_array($sourceVersion, ['1.2.6', '1.2.7'], true) ? 'YES' : 'NO') !== $c->fetchOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaign' AND COLUMN_NAME='template_version_id'")) {
    throw new RuntimeException('Restored Light ledger or nullable-template profile differs from its authenticated source.');
}
if (!hash_equals((string) hash_file('sha256', '/rehearsal/keys/master.key'), (string) hash_file('sha256', '/old-export/keys/master.key'))) {
    throw new RuntimeException('Legacy recovery key did not preserve original source material.');
}
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
$baselinePath = '/rehearsal/old-backup-messages-before';
if (!is_file($baselinePath) || is_link($baselinePath) || filesize($baselinePath) > 32) {
    throw new RuntimeException('Legacy restore has no trustworthy pre-restore mail count.');
}
$baseline = filter_var(trim((string) file_get_contents($baselinePath)), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if (false === $baseline || !is_array($mail) || !isset($mail['total']) || !is_int($mail['total']) || $mail['total'] < 0) {
    throw new RuntimeException('Legacy restore mail-count evidence is invalid.');
}
if ($baseline !== $mail['total']) {
    throw new RuntimeException('Legacy restore emitted a new message.');
}
echo json_encode(['retained_light_backup_authenticated_and_restored' => true, 'unchanged_light_runtime_and_schema' => true, 'retained_original_key' => true, 'delivery_quiesced' => true, 'original_archive_unchanged' => true, 'accepted_ripe_unchanged' => true, 'new_emitted_messages' => 0], JSON_THROW_ON_ERROR)."\n";
