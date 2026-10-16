<?php

declare(strict_types=1);

// Isolated acceptance fixture only; never called by the application or operator installer.
if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Synthetic fixture seeding requires the isolated rehearsal harness.');
}
require '/rehearsal/source-releases/current/vendor/autoload.php';
$url = getenv('DATABASE_URL');
if (!is_string($url)) {
    throw new RuntimeException('Isolated source URL is required.');
}
$parameters = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if ('database' !== ($parameters['host'] ?? null) || !str_starts_with($parameters['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Fixture database must be the disposable harness database.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($parameters);
$f = json_decode(file_get_contents('/tools/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
$u = $f['administrator']['id'];
$i = $f['installation']['installation_uuid'];
$n = '2026-01-01 00:00:00.000000';
$old = $c->fetchOne('SELECT id FROM app_user');
$c->executeStatement('SET FOREIGN_KEY_CHECKS=0');
$c->executeStatement('DROP TRIGGER audit_event_no_update');
$c->update('app_user', ['id' => $u], ['id' => $old]);
$c->update('audit_event', ['actor_user_id' => $u], ['actor_user_id' => $old]);
$c->update('setup_installation_event', ['administrator_id' => $u], ['administrator_id' => $old]);
foreach (['installation_state', 'organisation_settings', 'product_identity'] as $t) {
    $c->update($t, ['installation_uuid' => $i], ['singleton_key' => '1']);
}$c->executeStatement("CREATE TRIGGER audit_event_no_update BEFORE UPDATE ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
$c->executeStatement('SET FOREIGN_KEY_CHECKS=1');
$l = json_decode(file_get_contents('/rehearsal/private/install.lock'), true, 512, JSON_THROW_ON_ERROR);
$l['installation_uuid'] = $i;
file_put_contents('/rehearsal/private/install.lock', json_encode($l, JSON_THROW_ON_ERROR));
chmod('/rehearsal/private/install.lock', 0600);
$t = $f['templates'][0]['id'];
$v = '01990101-0000-7000-8000-000000000010';
$rv = '01990101-0000-7000-8000-000000000011';
$c->insert('email_template', ['id' => $t, 'name' => 'Published synthetic fixture', 'status' => 'published', 'published_version_id' => null, 'created_at' => $n, 'updated_at' => $n, 'archived_at' => null]);
foreach ([1 => $v, 2 => $rv] as $num => $id) {
    $html = $f['templates'][0]['html'].(2 === $num ? ' <p>{{ recipient.first_name }} / {{ recipient.display_name }}</p><a href="{{ rsvp_url }}">RSVP</a>' : '');
    $c->insert('template_version', ['id' => $id, 'template_id' => $t, 'version_number' => $num, 'subject' => $f['templates'][0]['subject'], 'preheader' => null, 'html_body' => $html, 'text_body' => 'Synthetic fixture', 'merge_fields_json' => json_encode(2 === $num ? ['recipient.first_name', 'recipient.display_name', 'rsvp_url'] : []), 'link_manifest_json' => '[]', 'content_sha256' => hash('sha256', $html), 'created_by' => $u, 'created_at' => $n, 'published_at' => $n]);
}$c->update('email_template', ['published_version_id' => $rv], ['id' => $t]);
foreach ($f['campaigns'] as $x) {
    $a = 'draft' !== $x['state'];
    $c->insert('campaign', ['id' => $x['id'], 'name' => 'Synthetic '.$x['state'], 'template_version_id' => 'paused' === $x['state'] ? $rv : $v, 'state' => $x['state'], 'scheduled_at' => 'scheduled' === $x['state'] ? '2026-01-01 00:00:00.000000' : null, 'approved_at' => $a ? $n : null, 'approved_by' => $a ? $u : null, 'approval_waived_test' => $a ? 1 : 0, 'rsvp_enabled' => 'paused' === $x['state'] ? 1 : 0, 'operation_version' => 1, 'created_by' => $u, 'created_at' => $n, 'updated_at' => $n, 'messages_per_minute' => null, 'rate_window_started_at' => null, 'rate_window_count' => 0]);
}
$f['recipients'][] = ['id' => '01990101-0000-7000-8000-000000000025', 'campaign_id' => '01990101-0000-7000-8000-000000000006', 'email' => 'retry-person@example.test', 'profile' => ['first_name' => 'Retry', 'display_name' => 'Synthetic Retry']];
foreach ($f['recipients'] as $x) {
    $c->insert('campaign_audience_recipient', ['campaign_id' => $x['campaign_id'], 'recipient_id' => $x['id'], 'email_canonical' => $x['email'], 'profile_json' => json_encode($x['profile'], JSON_THROW_ON_ERROR)]);
}
foreach ($f['campaigns'] as $x) {
    if ('draft' === $x['state']) {
        continue;
    }$rows = $c->fetchAllAssociative('SELECT recipient_id,email_canonical,profile_json FROM campaign_audience_recipient WHERE campaign_id=? ORDER BY email_canonical', [$x['id']]);
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = $r['recipient_id']."\0".$r['email_canonical']."\0".$r['profile_json'];
    }$c->insert('campaign_audience_snapshot', ['campaign_id' => $x['id'], 'recipient_count' => count($rows), 'audience_sha256' => hash('sha256', implode("\n", $parts)), 'created_at' => $n]);
    $r = $c->fetchAssociative('SELECT * FROM template_version WHERE id=?', ['paused' === $x['state'] ? $rv : $v]);
    $c->insert('campaign_content_snapshot', ['campaign_id' => $x['id'], 'template_version_id' => $r['id'], 'subject' => $r['subject'], 'preheader' => null, 'html_body' => $r['html_body'], 'text_body' => $r['text_body'], 'unsubscribe_footer' => 'Synthetic unsubscribe', 'one_click_dkim_confirmed' => 0, 'merge_fields_json' => $r['merge_fields_json'], 'link_manifest_json' => '[]', 'open_tracking' => 1, 'click_tracking' => 0, 'rsvp_enabled' => 'paused' === $x['state'] ? 1 : 0, 'rsvp_repeat_policy' => 'replace', 'rsvp_form_schema_json' => json_encode(['fields' => ['response', 'guests', 'note'], 'choices' => ['yes', 'no', 'maybe'], 'max_guests' => 20, 'max_note_length' => 500]), 'content_sha256' => $r['content_sha256'], 'sender_address' => 'sender@example.test', 'sender_name' => 'Synthetic fixture', 'reply_to_address' => null, 'created_at' => $n]);
    foreach ($x['delivery_ids'] as $id) {
        $c->insert('campaign_recipient', ['id' => $id, 'campaign_id' => $x['id'], 'recipient_id' => $x['recipient_ids'][0], 'state' => 'failed' === $x['state'] ? 'permanent_failure' : 'accepted', 'attempt_count' => 1, 'next_attempt_at' => null, 'claimed_at' => null, 'accepted_at' => 'failed' === $x['state'] ? null : $n, 'terminal_at' => $n, 'message_id' => $id.'@emailer-light.invalid', 'operation_version' => 1, 'last_failure_class' => 'failed' === $x['state'] ? 'permanent' : null, 'updated_at' => $n]);
    }
}
foreach ($f['delivery_attempts'] as $x) {
    $c->insert('delivery_attempt', ['id' => $x['id'], 'campaign_recipient_id' => $x['delivery_id'], 'attempt_number' => 1, 'state' => 'permanent_failure' === $x['state'] ? 'permanent' : $x['state'], 'failure_class' => 'accepted' === $x['state'] ? null : 'permanent', 'provider_reference_hash' => 'accepted' === $x['state'] ? hash('sha256', 'synthetic-message-id') : null, 'started_at' => $n, 'finished_at' => $n]);
}
$retryDelivery = '01990101-0000-7000-8000-000000000035';
$c->insert('campaign_recipient', ['id' => $retryDelivery, 'campaign_id' => '01990101-0000-7000-8000-000000000006', 'recipient_id' => '01990101-0000-7000-8000-000000000025', 'state' => 'retry', 'attempt_count' => 1, 'next_attempt_at' => '2100-01-01 00:00:00.000000', 'claimed_at' => null, 'accepted_at' => null, 'terminal_at' => null, 'message_id' => $retryDelivery.'@emailer-light.invalid', 'operation_version' => 1, 'last_failure_class' => 'transient', 'updated_at' => $n]);
$c->insert('delivery_attempt', ['id' => '01990101-0000-7000-8000-000000000036', 'campaign_recipient_id' => $retryDelivery, 'attempt_number' => 1, 'state' => 'transient', 'failure_class' => 'transient', 'provider_reference_hash' => null, 'started_at' => $n, 'finished_at' => $n]);
$serializer = new Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
foreach (['01990101-0000-7000-8000-000000000033', $retryDelivery] as $deliveryId) {
    $encoded = $serializer->encode(new Symfony\Component\Messenger\Envelope(new App\Module\Delivery\Application\Message\DeliverCampaignRecipient($deliveryId, 1)));
    $c->insert('messenger_messages', ['body' => $encoded['body'], 'headers' => json_encode($encoded['headers'], JSON_THROW_ON_ERROR), 'queue_name' => 'async', 'created_at' => $n, 'available_at' => '2100-01-01 00:00:00', 'delivered_at' => null]);
}
foreach ($f['tracking'] as $x) {
    $c->insert('analytics_aggregate', ['campaign_recipient_id' => $x['delivery_id'], 'event_type' => $x['event'], 'link_index' => 0, 'event_count' => $x['count'], 'first_seen_at' => $n, 'last_seen_at' => $n]);
}foreach ($f['suppressions'] as $k => $x) {
    $c->insert('suppression', ['id' => sprintf('01990101-0000-7000-8000-%012d', 80 + $k), 'email_canonical' => $x['email'], 'reason' => $x['reason'], 'source' => 'synthetic-fixture', 'created_at' => $n]);
}foreach ($f['audit'] as $x) {
    $c->insert('audit_event', ['id' => $x['id'], 'actor_user_id' => null, 'action' => $x['action'], 'target_id' => null, 'occurred_at' => $n, 'source_hash' => null, 'result' => 'success', 'summary_json' => '{}']);
}$c->insert('rsvp_response', ['campaign_recipient_id' => '01990101-0000-7000-8000-000000000032', 'response_choice' => 'yes', 'guest_count' => 2, 'note' => 'Synthetic historical RSVP', 'submission_count' => 1, 'first_submitted_at' => $n, 'last_submitted_at' => $n]);
$a = '01990101-0000-7000-8000-000000000012';
$assetFilename = substr(hash('sha256', $a), 0, 48).'.png';
$b = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXwAAAAASUVORK5CYII=');
$d = '/rehearsal/private/template-assets';
if (!is_dir($d)) {
    mkdir($d, 0700);
}file_put_contents($d.'/'.$assetFilename, $b);
chmod($d.'/'.$assetFilename, 0600);
chown($d, 1000);
chgrp($d, 1000);
chown($d.'/'.$assetFilename, 1000);
chgrp($d.'/'.$assetFilename, 1000);
$c->insert('template_asset', ['id' => $a, 'filename' => $assetFilename, 'media_type' => 'image/png', 'byte_size' => strlen($b), 'width' => 1, 'height' => 1, 'content_sha256' => hash('sha256', $b), 'created_by' => $u, 'created_at' => $n]);
$m = new App\Module\Secrets\Infrastructure\FilesystemMasterKey('/rehearsal/keys/master.key', '/rehearsal/source-releases/current/public', '/rehearsal/source-releases/current', 'dev');
$vault = new App\Module\Secrets\Infrastructure\DoctrineSecretVault($c, $m, new App\Module\Secrets\Application\SecretCipher());
$s = $vault->store('smtp.credential', $f['smtp']['password'], $i);
$c->update('sender_identity', ['smtp_host' => $f['smtp']['host'], 'smtp_port' => 587, 'encryption_mode' => 'starttls', 'smtp_username' => $f['smtp']['username'], 'credential_secret_id' => $s, 'tls_peer_verification' => 1], ['singleton_key' => '1']);
$key = random_bytes(32);
$s = $vault->store('recipient.token.key', sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $i);
$c->insert('recipient_token_key', ['key_version' => 1, 'secret_id' => $s, 'active' => 1, 'verify_until' => null, 'created_at' => $n]);
$c->update('recipient_token_key_state', ['current_version' => 1], ['singleton_key' => '1']);
$codec = new App\Module\RecipientActions\Application\RecipientTokenCodec();
$links = [];
foreach (['unsubscribe', 'open', 'view', 'rsvp'] as $k => $purpose) {
    $id = sprintf('01990101-0000-7000-8000-%012d', 60 + $k);
    $h = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $e = '2027-10-01 00:00:00.000000';
    $cam = '01990101-0000-7000-8000-000000000006';
    $del = '01990101-0000-7000-8000-000000000032';
    $rec = '01990101-0000-7000-8000-000000000022';
    $c->insert('recipient_token', ['id' => $id, 'handle' => $h, 'campaign_id' => $cam, 'campaign_recipient_id' => $del, 'recipient_id' => $rec, 'email_canonical' => 'unsubscribe' === $purpose ? 'person3@example.test' : null, 'purpose' => $purpose, 'key_version' => 1, 'issued_at' => $n, 'expires_at' => $e, 'revoked_at' => null, 'last_used_at' => null, 'use_count' => 0]);
    $links[$purpose] = $codec->encode(1, $h, $purpose, $cam, $del, $rec, $e, $key);
}
foreach ([['expired_click', 'click', '01990101-0000-7000-8000-000000000032', $n, '2026-02-01 00:00:00.000000', null], ['revoked_view', 'view', '01990101-0000-7000-8000-000000000033', $n, $e, $n]] as $extraIndex => [$label, $purpose, $delivery, $issued, $expires, $revoked]) {
    $scope = $c->fetchAssociative('SELECT campaign_id, recipient_id FROM campaign_recipient WHERE id = ?', [$delivery]);
    $handle = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $c->insert('recipient_token', ['id' => sprintf('01990101-0000-7000-8000-%012d', 64 + $extraIndex), 'handle' => $handle, 'campaign_id' => $scope['campaign_id'], 'campaign_recipient_id' => $delivery, 'recipient_id' => $scope['recipient_id'], 'email_canonical' => null, 'purpose' => $purpose, 'key_version' => 1, 'issued_at' => $issued, 'expires_at' => $expires, 'revoked_at' => $revoked, 'last_used_at' => null, 'use_count' => 0]);
    $links[$label] = $codec->encode(1, $handle, $purpose, $scope['campaign_id'], $delivery, $scope['recipient_id'], $expires, $key);
}
sodium_memzero($key);
file_put_contents('/rehearsal/private/synthetic-old-tokens.json', json_encode($links, JSON_THROW_ON_ERROR));
chmod('/rehearsal/private/synthetic-old-tokens.json', 0600);
$c->insert('user_session', ['id_hash' => hash('sha256', 'synthetic-old-session'), 'user_id' => $u, 'payload' => 'synthetic historical session payload', 'created_at' => $n, 'last_seen_at' => $n, 'idle_expires_at' => '2027-01-01 00:00:00.000000', 'absolute_expires_at' => '2027-01-01 00:00:00.000000', 'revoked_at' => null, 'source_hash' => null, 'user_agent_hash' => null]);
$c->update('delivery_runtime', ['accepting_work' => 1], ['singleton_key' => '1']);
echo "Published fixture, real Light envelopes and old real-codec links seeded; no messages sent.\n";
