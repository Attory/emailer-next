<?php

declare(strict_types=1);

$targetVersion = require __DIR__.'/rehearsal-target.php';

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Old-link verification is isolated-only.');
}
$sourceRoot = realpath('/rehearsal/source-releases/current');
if (!in_array($sourceRoot, ['/rehearsal/source-releases/ripe-'.$targetVersion, '/rehearsal/source-releases/'.$targetVersion], true)) {
    throw new RuntimeException('Only the isolated accepted target root is permitted.');
}
require $sourceRoot.'/vendor/autoload.php';
// Load only browser test helpers from the approved image. Application classes
// and all production services remain from the actual staged signed package.
$browserLoader = new Composer\Autoload\ClassLoader();
foreach (['BrowserKit', 'DomCrawler', 'CssSelector'] as $component) {
    $browserLoader->addPsr4('Symfony\\Component\\'.$component.'\\', '/opt/emailer-vendor/symfony/'.strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $component)));
}
$browserLoader->register(false);
$kernel = new App\Kernel('dev', false);
$client = new Symfony\Component\HttpKernel\HttpKernelBrowser($kernel);
$client->setServerParameters(['HTTPS' => 'on', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
$links = json_decode((string) file_get_contents('/rehearsal/private/synthetic-old-tokens.json'), true, 512, JSON_THROW_ON_ERROR);
$parameters = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($parameters['host'] ?? null) || !str_starts_with($parameters['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('HTTP acceptance refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($parameters);
$attempts = $c->fetchOne('SELECT COUNT(*) FROM delivery_attempt');
$asset = $c->fetchAssociative('SELECT filename,content_sha256 FROM template_asset');
$client->request('GET', 'https://localhost/template-assets/'.$asset['filename']);
if (200 !== $client->getResponse()->getStatusCode() || $asset['content_sha256'] !== hash('sha256', $client->getInternalResponse()->getContent())) {
    throw new RuntimeException('Original uploaded asset was not served unchanged.');
}
$beforeOpen = $c->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = '01990101-0000-7000-8000-000000000032' AND event_type = 'open'");
foreach (['unsubscribe' => 'unsubscribe', 'view' => 'view', 'open' => 'open.gif', 'rsvp' => 'rsvp'] as $purpose => $suffix) {
    $client->request('GET', 'https://localhost/r/'.$links[$purpose].'/'.$suffix);
    if (200 !== $client->getResponse()->getStatusCode()) {
        throw new RuntimeException('An original Light '.$purpose.' URL failed after conversion.');
    }
    if ('view' === $purpose && (!str_contains($client->getInternalResponse()->getContent(), 'Synthetic3') || str_contains($client->getInternalResponse()->getContent(), '{{ recipient.'))) {
        throw new RuntimeException('Original Light recipient profile merge fields were not rendered.');
    }
}
$afterOpen = $c->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = '01990101-0000-7000-8000-000000000032' AND event_type = 'open'");
if ((int) $beforeOpen + 1 !== (int) $afterOpen) {
    throw new RuntimeException('The original Light tracking MAC was not accepted.');
}
$client->request('POST', 'https://localhost/r/'.$links['unsubscribe'].'/unsubscribe', ['List-Unsubscribe' => 'One-Click'], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
if (200 !== $client->getResponse()->getStatusCode() || false === $c->fetchOne("SELECT 1 FROM suppression_match WHERE email_match_key = LOWER('PERSON3@EXAMPLE.TEST')")) {
    throw new RuntimeException('Original unsubscribe link or case-insensitive suppression failed.');
}
// These two URLs were already expired/revoked in published Light before
// conversion; their original MACs, timestamps and history are retained.
foreach (['revoked_view' => 'view', 'expired_click' => 'click/0'] as $label => $suffix) {
    $client->request('GET', 'https://localhost/r/'.$links[$label].'/'.$suffix);
    if (404 !== $client->getResponse()->getStatusCode()) {
        throw new RuntimeException('An original inactive Light URL retained authority.');
    }
}
if ($attempts !== $c->fetchOne('SELECT COUNT(*) FROM delivery_attempt')) {
    throw new RuntimeException('Public link verification created a delivery attempt.');
}
echo json_encode(['original_asset_bytes_served' => true, 'original_unsubscribe_url' => true, 'original_tracking_url_and_aggregate' => true, 'original_view_and_rsvp_urls' => true, 'original_revocation_and_expiry_enforced' => true, 'case_insensitive_suppression' => true, 'new_delivery_attempts' => 0], JSON_THROW_ON_ERROR)."\n";
