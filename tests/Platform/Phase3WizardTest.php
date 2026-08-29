<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Setup\Application\Contract\InstallationLock;
use App\Tests\Support\ResetsPhase3State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class Phase3WizardTest extends WebTestCase
{
    use ResetsPhase3State;

    private string $proofFile = '';

    protected function tearDown(): void
    {
        if ('' !== $this->proofFile && (file_exists($this->proofFile) || is_link($this->proofFile))) {
            unlink($this->proofFile);
        }
        if (null !== self::$kernel) {
            $this->removePhase3Files();
        }
        parent::tearDown();
    }

    public function testSecureWizardCompletesAndPermanentlyHidesSetup(): void
    {
        $client = self::createClient();
        $connection = $this->service(Connection::class);
        $this->resetPhase3Database($connection);
        $this->removePhase3Files();
        $server = $this->httpsServer();

        $crawler = $client->request('GET', '/setup', server: $server);
        self::assertResponseIsSuccessful();
        $matches = [];
        self::assertSame(1, preg_match('/[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7}/', (string) $client->getResponse()->getContent(), $matches));
        $proofCode = $matches[0] ?? null;
        self::assertIsString($proofCode);
        $proofFile = self::getContainer()->getParameter('emailer.proof_file');
        self::assertIsString($proofFile);
        $this->proofFile = $proofFile;
        self::assertNotFalse(file_put_contents($proofFile, $proofCode));
        self::assertTrue(chmod($proofFile, 0o600));
        $client->submit($crawler->selectButton('I installed the proof; verify it')->form(), [], $server);
        self::assertResponseRedirects('/setup/preflight', 303);

        $client->request('GET', '/setup/database', server: $server);
        $databaseParams = $connection->getParams();
        $databaseHost = $databaseParams['host'] ?? null;
        $databasePort = $databaseParams['port'] ?? 3306;
        $databaseName = $databaseParams['dbname'] ?? null;
        $databaseUser = $databaseParams['user'] ?? null;
        $databasePassword = $databaseParams['password'] ?? null;
        self::assertIsString($databaseHost);
        self::assertTrue(\is_int($databasePort) || \is_string($databasePort));
        self::assertIsString($databaseName);
        self::assertIsString($databaseUser);
        self::assertIsString($databasePassword);
        $databaseForm = $client->getCrawler()->selectButton('Verify application credentials and install schema')->form([
            'host' => $databaseHost,
            'port' => (string) $databasePort,
            'database' => $databaseName,
            'username' => $databaseUser,
            'password' => $databasePassword,
        ]);
        $client->submit($databaseForm, [], $server);
        self::assertResponseRedirects('/setup/organisation', 303);

        $client->request('GET', '/setup/organisation', server: $server);
        $client->submit($client->getCrawler()->selectButton('Save organisation')->form([
            'display_name' => 'Synthetic Organisation',
            'base_url' => 'https://emailer.example.test',
            'locale' => 'en_AU',
            'time_zone' => 'Australia/Sydney',
        ]), [], $server);
        self::assertResponseRedirects('/setup/owner', 303);

        $ownerPassword = 'runtime-owner-'.bin2hex(random_bytes(12));
        $client->request('GET', '/setup/owner', server: $server);
        $ownerForm = $client->getCrawler()->selectButton('Create Owner')->form([
            'login_identifier' => 'owner@example.test',
            'password' => $ownerPassword,
            'password_confirmation' => $ownerPassword,
        ]);
        $mfaField = $ownerForm['enable_mfa'];
        self::assertInstanceOf(ChoiceFormField::class, $mfaField);
        $mfaField->untick();
        $client->submit($ownerForm, [], $server);
        self::assertResponseRedirects('/setup/mail', 303);

        $client->request('GET', '/setup/mail', server: $server);
        $client->submit($client->getCrawler()->selectButton('Send one synthetic message to local Mailpit and save')->form([
            'host' => 'mailpit',
            'port' => '1025',
            'encryption' => 'none',
            'sender_address' => 'phase3-sender@example.test',
            'sender_name' => 'Emailer Next Test',
            'reply_to' => '',
        ]), [], $server);
        self::assertResponseRedirects('/setup/policy', 303);

        $client->request('GET', '/setup/policy', server: $server);
        $client->submit($client->getCrawler()->selectButton('Save these non-sending safety defaults')->form(), [], $server);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Final critical preflight');

        $client->submit($client->getCrawler()->selectButton('Complete installation and permanently close setup')->form(), [], $server);
        self::assertResponseIsSuccessful();
        self::assertSame('Installation completed. Setup is permanently locked. Continue at /login.', $client->getResponse()->getContent());
        self::assertTrue($this->service(InstallationLock::class)->exists());
        self::assertSame([], $client->getRequest()->getSession()->all());

        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        self::assertSame([], $queue->getSent());

        $databaseSnapshot = json_encode([
            $connection->fetchAllAssociative('SELECT * FROM installation_state'),
            $connection->fetchAllAssociative('SELECT * FROM organisation_settings'),
            $connection->fetchAllAssociative('SELECT * FROM app_user'),
            $connection->fetchAllAssociative('SELECT * FROM encrypted_secret'),
            $connection->fetchAllAssociative('SELECT * FROM sender_identity'),
            $connection->fetchAllAssociative('SELECT * FROM mfa_credential'),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($ownerPassword, $databaseSnapshot);
        $this->assertRuntimeFilesExclude([$ownerPassword]);

        $client->request('GET', '/setup', server: $server);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $client->getResponse()->getContent());
    }

    /** @param list<string> $needles */
    private function assertRuntimeFilesExclude(array $needles): void
    {
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        foreach ([$projectDirectory.'/var/cache/test', $projectDirectory.'/var/log'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if (!$file->isFile() || $file->getSize() > 2_000_000) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!\is_string($contents)) {
                    continue;
                }
                foreach ($needles as $needle) {
                    self::assertStringNotContainsString($needle, $contents, $file->getPathname());
                }
            }
        }
    }

    /** @return array<string, string> */
    private function httpsServer(): array
    {
        return [
            'HTTPS' => 'on',
            'DOCUMENT_ROOT' => \dirname(__DIR__, 2).'/public',
            'REMOTE_ADDR' => '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(8)), 4)),
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        if (!$service instanceof $id) {
            throw new \RuntimeException('Test service is unavailable.');
        }

        return $service;
    }
}
