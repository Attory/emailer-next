<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || !in_array(getenv('EMAILER_REHEARSAL_NATIVE_SOURCE'), ['1.0.5', '1.3.3', '1.3.4', '1.3.5'], true)) {
    throw new RuntimeException('Explicit native production-shaped fixture required.');
}
require '/tools/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Production-shaped fixture refuses non-harness database access.');
}
$db = Doctrine\DBAL\DriverManager::getConnection($p);
if (0 !== (int) $db->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('Fixture has active claims.');
}
if ('1.0.5' === getenv('EMAILER_REHEARSAL_NATIVE_SOURCE')) {
    $db->executeStatement("UPDATE organisation_settings SET security_level='normal' WHERE singleton_key='1'");
}
if ('1' !== getenv('EMAILER_REHEARSAL_NATIVE_POSTCORE_PAUSE')) {
    $db->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
} elseif ('1.3.4' !== getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') || 1 !== (int) $db->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('Post-core Pause requires the initially enabled signed134 source.');
}
$failed = (new Symfony\Component\Messenger\Transport\Serialization\PhpSerializer())->encode(new Symfony\Component\Messenger\Envelope(new App\Module\Delivery\Application\Message\DeliverCampaignRecipient('01990606-0000-7000-8000-000000000010', 3)));
$db->insert('messenger_messages', ['body' => $failed['body'], 'headers' => json_encode($failed['headers'] ?? [], JSON_THROW_ON_ERROR), 'queue_name' => 'failed', 'created_at' => '2026-01-01 00:00:00', 'available_at' => '2026-01-01 00:00:00', 'delivered_at' => null]);
// No worker or scheduler process was started by the source setup fixture. Do
// not fabricate a heartbeat. Production verification must see their absence.
foreach (['/rehearsal/runtime.env', '/rehearsal/updater.env'] as $path) {
    $text = (string) file_get_contents($path);
    if (!str_contains($text, "APP_ENV='dev'")) {
        throw new RuntimeException('Unexpected synthetic source environment.');
    }
    file_put_contents($path, str_replace("APP_ENV='dev'", "APP_ENV='prod'", $text));
}
echo "Synthetic production verification selected; source services absent; case-specific source delivery intent selected.\n";
