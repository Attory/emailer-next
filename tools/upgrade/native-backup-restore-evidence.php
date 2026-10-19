<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['before', 'after'], true)) {
    throw new RuntimeException('Native backup evidence is isolated-only.');
}
require '/rehearsal/source-releases/ripe-1.2.3/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Native backup evidence refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
$attempts = $c->fetchAllAssociative('SELECT * FROM delivery_attempt ORDER BY id');
$file = '/rehearsal/native-backup-restore-before.json';
if ('before' === $argv[1]) {
    $filename = $c->fetchOne("SELECT filename FROM backup_record WHERE source_version='1.2.3' AND source_edition='emailer-ripe' ORDER BY created_at LIMIT 1");
    if (!is_string($filename) || basename($filename) !== $filename) {
        throw new RuntimeException('A conversion-created native Ripe backup is required.');
    }
    $backup = '/rehearsal/private/backups/'.$filename;
    file_put_contents('/rehearsal/native-backup-path', $backup);
    chmod('/rehearsal/native-backup-path', 0o600);
    file_put_contents($file, json_encode(['attempts' => $attempts, 'messages' => $mail['total'], 'path' => $backup, 'sha256' => hash_file('sha256', $backup)], JSON_THROW_ON_ERROR));
    chmod($file, 0o600);
    exit;
}
$before = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if ($before['attempts'] !== $attempts || $before['messages'] !== $mail['total'] || !hash_equals($before['sha256'], (string) hash_file('sha256', $before['path'])) || 0 !== (int) $c->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'") || false !== $c->fetchOne('SELECT 1 FROM campaign_recipient WHERE contact_id IS NOT NULL') || false !== $c->fetchOne('SELECT 1 FROM contact')) {
    throw new RuntimeException('Native backup recovery changed recipient provenance, attempts, archive or delivery isolation.');
}
echo json_encode(['conversion_created_native_ripe_backup_restored_and_drill_verified' => true, 'campaign_owned_recipients_retained' => true, 'original_attempt_fingerprints_unchanged' => true, 'archive_unchanged' => true, 'new_emitted_messages' => 0, 'delivery_quiesced' => true], JSON_THROW_ON_ERROR)."\n";
