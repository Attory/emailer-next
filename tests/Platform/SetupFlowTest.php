<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Setup\Application\Contract\ChallengeStore;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Domain\ChallengeState;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetupFlowTest extends WebTestCase
{
    private string $proofFile = '';
    private string $installLockFile = '';
    private string $stateFile = '';
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

        if ('' !== $this->stateFile) {
            @unlink($this->stateFile);
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

        self::assertNotFalse(file_put_contents($this->proofFile, $code));
        self::assertTrue(chmod($this->proofFile, 0o600));
        $client->submit($form, [], $this->httpsServer());
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('h1', 'Ownership proof not accepted');
        self::assertFileExists($this->proofFile);
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

    public function testSecondClientCannotReplaceActiveChallengeAndOriginalProofStillSucceeds(): void
    {
        $originalClient = self::createClient();
        $originalCrawler = $originalClient->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseIsSuccessful();
        $originalCode = $this->extractCode((string) $originalClient->getResponse()->getContent());
        $originalForm = $originalCrawler->selectButton('Verify proof file')->form();
        $this->trackSetupFiles();

        $secondClient = new KernelBrowser($originalClient->getKernel());
        $secondAddress = '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(8)), 4));
        $secondClient->request('GET', '/setup', server: $this->httpsServer($secondAddress));

        self::assertSame(409, $secondClient->getResponse()->getStatusCode());
        self::assertStringNotContainsString($originalCode, (string) $secondClient->getResponse()->getContent());

        self::assertNotFalse(file_put_contents($this->proofFile, $originalCode));
        self::assertTrue(chmod($this->proofFile, 0o600));
        $originalClient->submit($originalForm, [], $this->httpsServer());

        self::assertSame(303, $originalClient->getResponse()->getStatusCode());
        self::assertSame('/setup/preflight', $originalClient->getResponse()->headers->get('Location'));
        self::assertFileDoesNotExist($this->proofFile);
    }

    public function testExpiredChallengeCanBeReplacedOverHttp(): void
    {
        $client = self::createClient();
        $client->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseIsSuccessful();
        $expiredCode = $this->extractCode((string) $client->getResponse()->getContent());
        $this->trackSetupFiles();
        $store = $this->challengeStore();
        $state = $store->load();
        self::assertNotNull($state);
        $now = time();
        $store->save(new ChallengeState(
            $state->identifier,
            $state->codeHash,
            $now - 901,
            $now - 1,
            $state->failedAttempts,
            $state->requestIpHash,
        ));

        $client->request('GET', '/setup', server: $this->httpsServer());

        self::assertResponseIsSuccessful();
        self::assertNotSame($expiredCode, $this->extractCode((string) $client->getResponse()->getContent()));
    }

    public function testChallengeIssuanceEndpointRemainsRateLimitedWhileActive(): void
    {
        $client = self::createClient();
        $client->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseIsSuccessful();
        $code = $this->extractCode((string) $client->getResponse()->getContent());
        $this->trackSetupFiles();

        for ($request = 2; $request <= 6; ++$request) {
            $client->request('GET', '/setup', server: $this->httpsServer());
            self::assertResponseStatusCodeSame(409);
            self::assertStringNotContainsString($code, (string) $client->getResponse()->getContent());
        }

        $client->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseStatusCodeSame(429);
    }

    public function testTenHttpAttemptsExhaustSameChallengeAndTerminateAuthority(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseIsSuccessful();
        $code = $this->extractCode((string) $client->getResponse()->getContent());
        $this->trackSetupFiles();
        self::assertNotFalse(file_put_contents($this->proofFile, str_repeat('0', 32)));
        self::assertTrue(chmod($this->proofFile, 0o600));
        $identifier = $this->challengeStore()->load()?->identifier;
        self::assertNotNull($identifier);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $crawler = $client->submit($crawler->filter('form')->form(), [], $this->httpsServer());
            self::assertResponseStatusCodeSame(400);
            self::assertSelectorTextContains('h1', 'Ownership proof not accepted');
            self::assertStringNotContainsString($code, (string) $client->getResponse()->getContent());

            $state = $this->challengeStore()->load();

            if ($attempt < 10) {
                self::assertNotNull($state);
                self::assertSame($identifier, $state->identifier);
                self::assertSame($attempt, $state->failedAttempts);
            } else {
                self::assertNull($state);
            }
        }

        $client->request('GET', '/setup', server: $this->httpsServer());
        self::assertResponseIsSuccessful();
        self::assertNotSame($code, $this->extractCode((string) $client->getResponse()->getContent()));
    }

    public function testValidProofSucceedsAfterEarlierInvalidHttpAttempt(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/setup', server: $this->httpsServer());
        $code = $this->extractCode((string) $client->getResponse()->getContent());
        $this->trackSetupFiles();
        self::assertNotFalse(file_put_contents($this->proofFile, str_repeat('0', 32)));
        self::assertTrue(chmod($this->proofFile, 0o600));

        $crawler = $client->submit($crawler->filter('form')->form(), [], $this->httpsServer());
        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->challengeStore()->load()?->failedAttempts);
        self::assertNotFalse(file_put_contents($this->proofFile, $code));
        self::assertTrue(chmod($this->proofFile, 0o600));

        $client->submit($crawler->filter('form')->form(), [], $this->httpsServer());

        self::assertResponseRedirects('/setup/preflight', 303);
        self::assertFileDoesNotExist($this->proofFile);
        self::assertNull($this->challengeStore()->load());
    }

    /** @return array<string, string> */
    private function httpsServer(?string $remoteAddress = null): array
    {
        return [
            'HTTPS' => 'on',
            'DOCUMENT_ROOT' => \dirname(__DIR__, 2).'/public',
            'REMOTE_ADDR' => $remoteAddress ?? $this->remoteAddress,
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

    private function trackSetupFiles(): void
    {
        $proofFile = self::getContainer()->getParameter('emailer.proof_file');
        $stateFile = self::getContainer()->getParameter('emailer.setup_state_file');
        self::assertIsString($proofFile);
        self::assertIsString($stateFile);
        $this->proofFile = $proofFile;
        $this->stateFile = $stateFile;
    }

    private function challengeStore(): ChallengeStore
    {
        $store = self::getContainer()->get(ChallengeStore::class);
        self::assertInstanceOf(ChallengeStore::class, $store);

        return $store;
    }
}
