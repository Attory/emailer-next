<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Owner acceptance is isolated-only.');
}
$version = $argv[1] ?? 'ripe-1.3.1';
if (!in_array($version, ['1.0.5', '1.0.6', '1.1.0', '1.2.1', '1.2.2', '1.3.0', 'ripe-1.3.1'], true)) {
    throw new RuntimeException('Unsupported isolated Owner acceptance version.');
}
require '/rehearsal/source-releases/'.$version.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (['BrowserKit', 'DomCrawler', 'CssSelector'] as $component) {
    $loader->addPsr4('Symfony\\Component\\'.$component.'\\', '/opt/emailer-vendor/symfony/'.strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $component)));
}
$loader->register(false);
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Owner acceptance refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$f = json_decode((string) file_get_contents('/tools/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
$originalOwner = $c->fetchAssociative('SELECT id,password_hash,role FROM app_user');
if (false === $originalOwner || 'ROLE_OWNER' !== $originalOwner['role']) {
    throw new RuntimeException('Original administrator was not mapped to Owner.');
}
$ownerId = $originalOwner['id'];
$hash = $originalOwner['password_hash'];
if (0 !== (int) $c->fetchOne('SELECT COUNT(*) FROM mfa_credential') || 'high' !== $c->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'")) {
    throw new RuntimeException('Upgrade did not leave High with unconfigured MFA.');
}
$client = new Symfony\Component\HttpKernel\HttpKernelBrowser(new App\Kernel('dev', false));
$client->setServerParameters(['HTTPS' => 'on', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
$sourceSessionVerified = false;
if ('ripe-1.3.1' === $version) {
    $oldSession = json_decode((string) file_get_contents('/rehearsal/original-light-session.json'), true, 512, JSON_THROW_ON_ERROR);
    $client->getCookieJar()->set(new Symfony\Component\BrowserKit\Cookie($oldSession['cookie_name'], $oldSession['cookie'], null, '/', 'localhost', true));
    // Even renaming the original cookie to the native name cannot restore authority.
    $client->getCookieJar()->set(new Symfony\Component\BrowserKit\Cookie('EMAILERSESSID', json_decode((string) file_get_contents('/rehearsal/original-light-session.json'), true, 512, JSON_THROW_ON_ERROR)['cookie'], null, '/', 'localhost', true));
    $client->request('GET', 'https://localhost/admin/profile');
    if (!in_array($client->getResponse()->getStatusCode(), [302, 303], true)) {
        throw new RuntimeException('Original Light login session retained access.');
    }
    $sourceSessionVerified = true;
}
$page = $client->request('GET', 'https://localhost/login');
$client->submit($page->selectButton('Sign in')->form(['login_identifier' => 'admin@example.test', 'password' => $f['administrator']['password']]));
if (303 !== $client->getResponse()->getStatusCode() || str_contains((string) $client->getResponse()->headers->get('Location'), '/login')) {
    throw new RuntimeException('Unchanged administrator password failed actual Ripe login.');
}
$page = $client->request('GET', 'https://localhost/admin/mfa');
if (200 !== $client->getResponse()->getStatusCode() || !str_contains((string) $client->getResponse()->getContent(), 'Not enabled')) {
    throw new RuntimeException('MFA was activated by conversion.');
}
$client->submit($page->selectButton('Start or restart enrollment')->form());
if (403 !== $client->getResponse()->getStatusCode()) {
    throw new RuntimeException('A recent High grant was inherited without explicit reauthentication.');
}
$page = $client->request('GET', 'https://localhost/admin/reauthenticate?purpose=mfa_management');
$client->submit($page->selectButton('Confirm current credentials')->form(['password' => $f['administrator']['password']]));
if (303 !== $client->getResponse()->getStatusCode() || str_contains((string) $client->getResponse()->headers->get('Location'), '/reauthenticate')) {
    throw new RuntimeException('Normal fresh-password MFA enrollment reauthentication failed.');
}
$page = $client->request('GET', 'https://localhost/admin/mfa');
$enrollment = $client->submit($page->selectButton('Start or restart enrollment')->form());
$seed = $enrollment->filter('code')->first()->text();
if (1 !== preg_match('/^[A-Z2-7]{32}$/D', $seed)) {
    throw new RuntimeException('Normal MFA enrollment did not issue valid material.');
}
$code = (new App\Module\Identity\Application\Totp())->code($seed, intdiv(time(), 30));
$client->submit($enrollment->selectButton('Confirm MFA')->form(['totp_code' => $code, 'recovery_saved' => '1']));
sodium_memzero($seed);
sodium_memzero($code);
if (1 !== (int) $c->fetchOne("SELECT COUNT(*) FROM mfa_credential WHERE credential_type = 'totp' AND active = 1") || $hash !== $c->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$ownerId])) {
    throw new RuntimeException('Normal enrollment or byte-for-byte password hash preservation failed.');
}
echo json_encode(['original_session_revoked' => $sourceSessionVerified, 'unchanged_password_login' => true, 'password_hash_unchanged' => true, 'owner_role_and_high' => true, 'mfa_unconfigured_after_upgrade' => true, 'no_inherited_high_grant' => true, 'normal_explicit_mfa_enrollment' => true], JSON_THROW_ON_ERROR)."\n";
