<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['prepare', 'verify'], true)) {
    throw new RuntimeException('Active-claim refusal fixture is isolated-only.');
}
require '/rehearsal/target/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Active-claim fixture refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
if ('prepare' === $argv[1]) {
    if (0 !== (int) $c->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'") || 0 !== (int) $c->fetchOne("SELECT COUNT(*) FROM delivery_attempt WHERE state='started'")) {
        throw new RuntimeException('A real claim cannot be replaced by a test marker.');
    }
    // An explicitly synthetic busy counter, with no worker/attempt behind it.
    // The installer must refuse it and must not reset it to appear idle.
    $c->executeStatement("UPDATE delivery_runtime SET active_claims=1 WHERE singleton_key='1'");
    exit;
}
$receipt = json_decode((string) file_get_contents('/rehearsal/private/edition-delivery-hold.json'), true, 512, JSON_THROW_ON_ERROR);
$runtime = $c->fetchAssociative("SELECT accepting_work,active_claims FROM delivery_runtime WHERE singleton_key='1'");
if ('recovered' !== ($receipt['phase'] ?? null) || true === ($receipt['services_stopped'] ?? false) || 0 !== (int) $runtime['accepting_work'] || 1 !== (int) $runtime['active_claims'] || ('/rehearsal/source-releases/'.(getenv('EMAILER_REHEARSAL_LIGHT_VERSION') ?: '1.0.0')) !== realpath('/rehearsal/source-releases/current')) {
    throw new RuntimeException('Active refusal stopped services, erased a claim or changed the source.');
}
echo json_encode(['active_claim_refused_and_preserved' => true, 'source_services_not_stopped' => true, 'unchanged_light_recovered_without_idle_fabrication' => true, 'delivery_quiesced' => true], JSON_THROW_ON_ERROR)."\n";
// Dispose only of this helper's synthetic counter after proving preservation;
// this is not an installer/recovery action and no real claim or attempt exists.
$c->executeStatement("UPDATE delivery_runtime SET active_claims=0 WHERE singleton_key='1'");
