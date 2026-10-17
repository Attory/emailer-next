<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Native fixture is isolated-only.');
}
$sourceVersion = getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') ?: '1.0.6';
if (!in_array($sourceVersion, ['1.0.6', '1.1.0'], true)) {
    throw new RuntimeException('Unsupported isolated native source.');
}
require '/rehearsal/source-releases/'.$sourceVersion.'/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Native fixture refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$owner = $c->fetchOne('SELECT id FROM app_user');
$n = '2026-01-01 00:00:00.000000';
$list = '01990606-0000-7000-8000-000000000001';
$contact = '01990606-0000-7000-8000-000000000002';
$other = '01990606-0000-7000-8000-000000000003';
$template = '01990606-0000-7000-8000-000000000004';
$version = '01990606-0000-7000-8000-000000000005';
$campaign = '01990606-0000-7000-8000-000000000006';
$c->insert('contact_list', ['id' => $list, 'name' => 'Synthetic native list', 'created_at' => $n, 'updated_at' => $n, 'archived_at' => null]);
foreach ([$contact, $other] as $i => $id) {
    $c->insert('contact', ['id' => $id, 'email_entered' => 'native'.($i + 1).'@example.test', 'consent_source' => 'synthetic-fixture', 'consent_at' => $n, 'email_canonical' => 'native'.($i + 1).'@example.test', 'display_name' => 'Synthetic Native', 'first_name' => 'Native', 'last_name' => 'Fixture', 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'created_at' => $n, 'updated_at' => $n, 'archived_at' => null]);
    $c->insert('list_membership', ['contact_id' => $id, 'list_id' => $list, 'source' => 'manual', 'created_at' => $n]);
}
$c->insert('email_template', ['id' => $template, 'name' => 'Native preserved template', 'status' => 'published', 'published_version_id' => null, 'created_at' => $n, 'updated_at' => $n, 'archived_at' => null]);
$c->insert('template_version', ['id' => $version, 'template_id' => $template, 'version_number' => 1, 'subject' => 'Native {{ contact.first_name }}', 'preheader' => null, 'html_body' => '<p>Native {{ contact.first_name }}</p>', 'text_body' => 'Native {{ contact.first_name }}', 'merge_fields_json' => '["contact.first_name"]', 'link_manifest_json' => '[]', 'content_sha256' => hash('sha256', 'synthetic-native-content'), 'created_by' => $owner, 'created_at' => $n, 'published_at' => $n]);
$c->update('email_template', ['published_version_id' => $version], ['id' => $template]);
$c->insert('campaign', ['id' => $campaign, 'name' => 'Native paused audience', 'template_version_id' => $version, 'state' => 'paused', 'scheduled_at' => null, 'approved_at' => $n, 'approved_by' => $owner, 'approval_waived_test' => 1, 'rsvp_enabled' => 0, 'operation_version' => 3, 'created_by' => $owner, 'created_at' => $n, 'updated_at' => $n, 'messages_per_minute' => null, 'rate_window_started_at' => null, 'rate_window_count' => 0]);
$c->insert('campaign_list_selection', ['campaign_id' => $campaign, 'list_id' => $list]);
foreach ([$contact, $other] as $i => $id) {
    $c->insert('campaign_audience_recipient', ['campaign_id' => $campaign, 'contact_id' => $id, 'email_canonical' => 'native'.($i + 1).'@example.test', 'profile_json' => '{"first_name":"Native","display_name":"Synthetic Native"}']);
}
$c->insert('campaign_audience_snapshot', ['campaign_id' => $campaign, 'recipient_count' => 2, 'audience_sha256' => hash('sha256', $contact.'\0native1@example.test'."\n".$other.'\0native2@example.test'), 'created_at' => $n]);
$c->insert('campaign_content_snapshot', ['campaign_id' => $campaign, 'template_version_id' => $version, 'subject' => 'Native {{ contact.first_name }}', 'preheader' => null, 'html_body' => '<p>Native {{ contact.first_name }}</p>', 'text_body' => 'Native {{ contact.first_name }}', 'unsubscribe_footer' => 'Synthetic native unsubscribe', 'one_click_dkim_confirmed' => 0, 'merge_fields_json' => '["contact.first_name"]', 'link_manifest_json' => '[]', 'open_tracking' => 0, 'click_tracking' => 0, 'rsvp_enabled' => 0, 'rsvp_repeat_policy' => 'replace', 'rsvp_form_schema_json' => '{}', 'content_sha256' => hash('sha256', 'synthetic-native-content'), 'sender_address' => 'sender@example.test', 'sender_name' => 'Synthetic Native', 'reply_to_address' => null, 'created_at' => $n]);
foreach ([$contact, $other] as $i => $id) {
    $delivery = '01990606-0000-7000-8000-0000000000'.(10 + $i);
    $c->insert('campaign_recipient', ['id' => $delivery, 'campaign_id' => $campaign, 'contact_id' => $id, 'state' => 0 === $i ? 'accepted' : 'retry', 'attempt_count' => 1, 'next_attempt_at' => 0 === $i ? null : '2100-01-01 00:00:00.000000', 'claimed_at' => null, 'accepted_at' => 0 === $i ? $n : null, 'terminal_at' => 0 === $i ? $n : null, 'message_id' => $delivery.'@emailer-next.invalid', 'operation_version' => 3, 'last_failure_class' => 0 === $i ? null : 'transient', 'updated_at' => $n]);
    $c->insert('delivery_attempt', ['id' => '01990606-0000-7000-8000-0000000000'.(12 + $i), 'campaign_recipient_id' => $delivery, 'attempt_number' => 1, 'state' => 0 === $i ? 'accepted' : 'transient', 'failure_class' => 0 === $i ? null : 'transient', 'provider_reference_hash' => 0 === $i ? hash('sha256', 'synthetic-native-message') : null, 'started_at' => $n, 'finished_at' => $n]);
}
foreach (['USER@example.test', 'user@example.test'] as $i => $email) {
    $c->insert('suppression', ['id' => '01990606-0000-7000-8000-0000000000'.(20 + $i), 'email_canonical' => $email, 'reason' => 'manual', 'source' => 'synthetic-native-fixture', 'created_at' => $n]);
}
$installation = $c->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'");
$master = new App\Module\Secrets\Infrastructure\FilesystemMasterKey('/rehearsal/keys/master.key', '/rehearsal/source-releases/'.$sourceVersion.'/public', '/rehearsal/source-releases/'.$sourceVersion, 'dev');
$vault = new App\Module\Secrets\Infrastructure\DoctrineSecretVault($c, $master, new App\Module\Secrets\Application\SecretCipher());
$credential = $vault->store('smtp.credential', 'synthetic-credential-only', $installation);
$c->update('sender_identity', ['smtp_host' => 'smtp.invalid', 'smtp_port' => 587, 'encryption_mode' => 'starttls', 'smtp_username' => 'synthetic-user', 'credential_secret_id' => $credential, 'tls_peer_verification' => 1], ['singleton_key' => '1']);
$key = random_bytes(32);
$secret = $vault->store('recipient.token.key', sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $installation);
$c->insert('recipient_token_key', ['key_version' => 1, 'secret_id' => $secret, 'active' => 1, 'verify_until' => null, 'created_at' => $n]);
$c->update('recipient_token_key_state', ['current_version' => 1], ['singleton_key' => '1']);
$handle = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$c->insert('release_operation', ['id' => '01990606-0000-7000-8000-000000000099', 'operation' => 'update', 'source_version' => '1.0.4', 'target_version' => '1.0.5', 'package_sha256' => str_repeat('a', 64), 'status' => 'succeeded', 'detail_code' => 'synthetic_historical_update', 'started_at' => $n, 'finished_at' => $n]);
$expires = '2027-10-01 00:00:00.000000';
$delivery = '01990606-0000-7000-8000-000000000010';
$c->insert('recipient_token', ['id' => '01990606-0000-7000-8000-000000000030', 'handle' => $handle, 'campaign_id' => $campaign, 'campaign_recipient_id' => $delivery, 'contact_id' => $contact, 'email_canonical' => 'native1@example.test', 'purpose' => 'unsubscribe', 'key_version' => 1, 'issued_at' => $n, 'expires_at' => $expires, 'revoked_at' => null, 'last_used_at' => null, 'use_count' => 0]);
$token = (new App\Module\RecipientActions\Application\RecipientTokenCodec())->encode(1, $handle, 'unsubscribe', $campaign, $delivery, $contact, $expires, $key);
file_put_contents('/rehearsal/original-native-token', $token);
chmod('/rehearsal/original-native-token', 0o600);
sodium_memzero($key);
echo "Native list/contact/audience, accepted/retry delivery history and suppression case aliases seeded.\n";
