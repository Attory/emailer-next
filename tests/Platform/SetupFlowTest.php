<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Setup\Application\Contract\InstallationLock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetupFlowTest extends WebTestCase
{
    private string $proofFile = '';
    private string $installLockFile = '';
    private string $remoteAddress = '';

    protected function setUp(): void
    {
        $this->remoteAddress = '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(8)), 4));
    }

    protected function tearDown(): void
    {
        if ('' !== $this->proofFile) {
            @unlink($this->proofFile);
        }

        if ('' !== $this->installLockFile) {
            @unlink($this->installLockFile);
        }

        parent::tearDown();
    }

    public function testOwnershipProofRotatesSessionAndReachesHttpsPreflight(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/setup', server: $this->httpsServer());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $code = $this->extractCode((string) $client->getResponse()->getContent());
        $proofFile = self::getContainer()->getParameter('emailer.proof_file');
        self::assertIsString($proofFile);
        $this->proofFile = $proofFile;
        self::assertNotFalse(file_put_contents($this->proofFile, $code."\n"));
        self::assertTrue(chmod($this->proofFile, 0o600));

        $cookies = $client->getCookieJar()->all();
        self::assertNotEmpty($cookies);
        $sessionBefore = $cookies[0]->getValue();

        $form = $crawler->selectButton('Verify proof file')->form();
        self::assertArrayNotHasKey('code', $form->getPhpValues());
        $client->submit($form, [], $this->httpsServer());

        self::assertResponseRedirects('/setup/preflight', 303);
        $cookies = $client->getCookieJar()->all();
        self::assertNotEmpty($cookies);
        self::assertNotSame($sessionBefore, $cookies[0]->getValue());
        self::assertFileDoesNotExist($this->proofFile);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Setup preflight');
        self::assertStringContainsString('No credentials are collected', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($code, (string) $client->getResponse()->getContent());
    }

    public function testProofMayRunOnHttpButPreflightStopsAtHttpsGate(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', 'http://emailer.example.test/setup', server: $this->httpServer());
        $code = $this->extractCode((string) $client->getResponse()->getContent());
        $proofFile = self::getContainer()->getParameter('emailer.proof_file');
        self::assertIsString($proofFile);
        $this->proofFile = $proofFile;
        self::assertNotFalse(file_put_contents($this->proofFile, $code));
        self::assertTrue(chmod($this->proofFile, 0o600));

        $client->submit($crawler->selectButton('Verify proof file')->form(), [], $this->httpServer());
        self::assertResponseRedirects('/setup/preflight', 303);
        $client->request('GET', 'http://emailer.example.test/setup/preflight', server: $this->httpServer());

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'HTTPS is required to continue');
        self::assertStringNotContainsString('DATABASE_URL', (string) $client->getResponse()->getContent());
    }

    public function testInvalidCsrfAndMissingProofUseGenericFailure(): void
    {
        $client = self::createClient();
        $client->request('POST', '/setup/proof/verify', ['_token' => 'invalid'], server: $this->httpsServer());

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('h1', 'Ownership proof not accepted');
        self::assertStringNotContainsString('missing', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('incorrect', (string) $client->getResponse()->getContent());
    }

    public function testInstallationLockMakesSetupRoutesPermanentNotFound(): void
    {
        $client = self::createClient();
        $lock = self::getContainer()->get(InstallationLock::class);
        self::assertInstanceOf(InstallationLock::class, $lock);
        $lock->create(['completed_at' => 1_800_000_000, 'version' => 1]);
        $installLockFile = self::getContainer()->getParameter('emailer.install_lock_file');
        self::assertIsString($installLockFile);
        $this->installLockFile = $installLockFile;

        $client->request('GET', '/setup', server: $this->httpsServer());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $client->getResponse()->getContent());
    }

    /** @return array<string, string> */
    private function httpsServer(): array
    {
        return [
            'HTTPS' => 'on',
            'DOCUMENT_ROOT' => \dirname(__DIR__, 2).'/public',
            'REMOTE_ADDR' => $this->remoteAddress,
        ];
    }

    /** @return array<string, string> */
    private function httpServer(): array
    {
        return [
            'HTTPS' => 'off',
            'DOCUMENT_ROOT' => \dirname(__DIR__, 2).'/public',
            'REMOTE_ADDR' => $this->remoteAddress,
        ];
    }

    private function extractCode(string $html): string
    {
        $matches = [];
        $result = preg_match('/[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7}/', $html, $matches);

        if (1 !== $result) {
            self::fail('The setup response did not contain a valid ownership code.');
        }

        return $matches[0];
    }
}
