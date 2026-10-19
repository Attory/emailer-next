<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\AdministrativeRole;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class AppearanceHttpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
            $directory = self::getContainer()->getParameter('emailer.appearance_dir');
            if (\is_string($directory) && is_dir($directory)) {
                foreach (new \DirectoryIterator($directory) as $entry) {
                    if (!$entry->isDot() && $entry->isFile()) {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($directory);
            }
        }
        parent::tearDown();
    }

    public function testPreferencesBrandingAuthorizationCsrfAndSavedStylesAcrossSessions(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'appearance-'.bin2hex(random_bytes(12));
        $this->createPhase4User($db, 'appearance-owner@example.test', $password);
        $this->createPhase4User($db, 'appearance-operator@example.test', $password, AdministrativeRole::Operator);
        $server = $this->phase4HttpsServer('2001:db8::241');
        $client->request('GET', '/admin/appearance', server: $server);
        self::assertResponseRedirects('https://localhost/login');
        $this->login($client, 'appearance-owner@example.test', $password, $server);
        $crawler = $client->request('GET', '/admin', server: $server);
        $token = $crawler->filter('[data-appearance]')->attr('data-csrf-token');
        self::assertIsString($token);
        $this->post($client, '/admin/appearance', ['skin' => 'warm', 'motion' => 'reduced', '_token' => 'invalid'], $server);
        self::assertResponseStatusCodeSame(400);
        $this->post($client, '/admin/appearance', ['skin' => 'warm', 'motion' => 'reduced', '_token' => $token], $server);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/campaigns', server: $server);
        self::assertSelectorExists('body[data-skin="warm"][data-motion="reduced"]');
        self::assertSelectorExists('script[type="module"][src*="appearance-"]');
        $this->post($client, '/admin/appearance/branding', ['action' => 'save', 'website_url' => 'https://example.com', 'tokens' => ['primary' => '#244a66', 'accent' => '#8b4b28', 'surface' => '#ffffff', 'background' => '#f4f6f5', 'text' => '#243632', 'font' => 'serif'], '_token' => $token], $server);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/appearance/tokens.css', server: $server);
        self::assertResponseHeaderSame('Content-Type', 'text/css; charset=UTF-8');
        self::assertStringContainsString('--website-primary:#244a66', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('--website-on-primary:', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString("'unsafe-inline'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        $this->post($client, '/admin/appearance/branding', ['action' => 'save', 'website_url' => 'https://example.com', 'tokens' => ['primary' => '#777777'], '_token' => $token], $server);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/appearance/tokens.css', server: $server);
        self::assertStringContainsString('--website-on-primary:#000000;', (string) $client->getResponse()->getContent());
        $this->post($client, '/admin/appearance/branding', ['action' => 'save', 'website_url' => 'https://example.com', 'tokens' => ['primary' => '#244a66'], '_token' => $token], $server);
        self::assertResponseIsSuccessful();
        $this->post($client, '/admin/appearance/branding', ['action' => 'refresh', 'website_url' => 'https://127.0.0.1', '_token' => $token], $server);
        self::assertResponseStatusCodeSame(400);
        self::ensureKernelShutdown();
        $client = self::createClient();
        $this->login($client, 'appearance-owner@example.test', $password, $server);
        $client->request('GET', '/admin', server: $server);
        self::assertSelectorExists('body[data-skin="warm"][data-motion="reduced"]');
        self::ensureKernelShutdown();
        $client = self::createClient();
        $this->login($client, 'appearance-operator@example.test', $password, $server);
        $crawler = $client->request('GET', '/admin', server: $server);
        self::assertSelectorNotExists('[data-branding-refresh]');
        $token = $crawler->filter('[data-appearance]')->attr('data-csrf-token');
        self::assertIsString($token);
        $this->post($client, '/admin/appearance', ['skin' => 'studio', 'motion' => 'system', '_token' => $token], $server);
        self::assertResponseIsSuccessful();
        $this->post($client, '/admin/appearance/branding', ['action' => 'save', '_token' => $token], $server);
        self::assertResponseStatusCodeSame(403);
        $db = $this->phase4Service(Connection::class);
        $client->request('GET', '/admin/appearance/tokens.css', server: $server);
        self::assertStringContainsString('--website-primary:#244a66', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM delivery_attempt')));
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM messenger_messages')));
    }

    /** @param array<string, mixed> $data
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, string $path, array $data, array $server): void
    {
        $client->request('POST', $path, server: $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $server */
    private function login(KernelBrowser $client, string $login, string $password, array $server): void
    {
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => $login, 'password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseStatusCodeSame(303);
    }
}
