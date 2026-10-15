<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('SMTP compatibility verification is isolated-only.');
}
require '/rehearsal/source-releases/1.0.6/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('SMTP verification refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
if (file_exists('/rehearsal/private/edition-delivery-hold.json') || 1 !== (int) $c->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('SMTP verification requires explicit accepted hold release first.');
}
$sender = $c->fetchAssociative("SELECT smtp_username,credential_secret_id FROM sender_identity WHERE singleton_key='1'");
$installation = $c->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
$master = new App\Module\Secrets\Infrastructure\FilesystemMasterKey('/rehearsal/keys/master.key', '/rehearsal/source-releases/1.0.6/public', '/rehearsal/source-releases/1.0.6', 'dev');
$vault = new App\Module\Secrets\Infrastructure\DoctrineSecretVault($c, $master, new App\Module\Secrets\Application\SecretCipher());
$envelopeBefore = $c->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$sender['credential_secret_id']]);
$password = $vault->reveal($sender['credential_secret_id'], 'smtp.credential', $installation);
$fixture = json_decode((string) file_get_contents('/tools/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
if (!hash_equals($fixture['smtp']['password'], $password)) {
    throw new RuntimeException('Original SMTP credential did not decrypt correctly.');
}
$attempts = $c->fetchOne('SELECT COUNT(*) FROM delivery_attempt');
$before = json_decode((string) file_get_contents('http://auth-mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
$transport = new Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport('auth-mailpit', 1025, false);
$transport->setAutoTls(false);
$transport->setUsername($sender['smtp_username']);
$transport->setPassword($password);
try {
    $message = (new Symfony\Component\Mime\Email())->from('synthetic-verifier@example.test')->to('synthetic-verification@example.test')->subject('Controlled post-acceptance credential verification')->text('Synthetic isolated verification after explicit delivery hold release.');
    $transport->send($message);
    $transport->stop();
} finally {
    sodium_memzero($password);
}
$after = json_decode((string) file_get_contents('http://auth-mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
if ($before['total'] + 1 !== $after['total'] || $attempts !== $c->fetchOne('SELECT COUNT(*) FROM delivery_attempt') || $envelopeBefore !== $c->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$sender['credential_secret_id']])) {
    throw new RuntimeException('Controlled SMTP isolation, history or original envelope preservation failed.');
}
echo json_encode(['original_smtp_secret_decrypted' => true, 'original_credentials_authenticated_to_synthetic_sink' => true, 'original_envelope_unchanged' => true, 'explicitly_controlled_post_hold_release_messages' => 1, 'historical_recipient_attempts_added' => 0], JSON_THROW_ON_ERROR)."\n";
