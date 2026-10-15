<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Source session issuance is isolated-only.');
}
require '/rehearsal/source-releases/1.0.0/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (['BrowserKit', 'DomCrawler', 'CssSelector'] as $component) {
    $loader->addPsr4('Symfony\\Component\\'.$component.'\\', '/opt/emailer-vendor/symfony/'.strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $component)));
}
$loader->register(false);
$fixture = json_decode((string) file_get_contents('/tools/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
$client = new Symfony\Component\HttpKernel\HttpKernelBrowser(new App\Kernel('dev', false));
$client->setServerParameters(['HTTPS' => 'on', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
$page = $client->request('GET', 'https://localhost/login');
$client->submit($page->selectButton('Sign in')->form(['login_identifier' => 'admin@example.test', 'password' => $fixture['administrator']['password']]));
if (303 !== $client->getResponse()->getStatusCode()) {
    throw new RuntimeException('Published Light fixture login failed.');
}
$cookie = $client->getCookieJar()->get('EMAILERLIGHTSESSID', '/', 'localhost');
if (null === $cookie) {
    throw new RuntimeException('Published Light did not issue a session.');
}
file_put_contents('/rehearsal/original-light-session.json', json_encode(['cookie_name' => 'EMAILERLIGHTSESSID', 'cookie' => $cookie->getValue()], JSON_THROW_ON_ERROR));
chmod('/rehearsal/original-light-session.json', 0o600);
echo "Published Light issued an actual authenticated source session; material remains protected.\n";
