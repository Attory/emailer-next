<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Identity\Application\Totp;
use App\Module\Identity\Infrastructure\DoctrineRecoveryCodeConsumer;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Module\Setup\Application\Contract\SetupState;
use App\Tests\Support\ResetsPhase3State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class SecureSetupPersistenceTest extends KernelTestCase
{
    use ResetsPhase3State;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = $this->service(Connection::class);
        $this->resetPhase3Database($this->connection);
        $this->removePhase3Files();
    }

    protected function tearDown(): void
    {
        $this->removePhase3Files();
        parent::tearDown();
    }

    public function testEncryptedVaultBindsIdentityAndNeverStoresPlaintext(): void
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $vault = $this->service(SecretVault::class);
        $plaintext = 'runtime-'.bin2hex(random_bytes(18));

        $first = $vault->store('smtp.credential', $plaintext, $installationId);
        $second = $vault->store('smtp.credential', $plaintext, $installationId);
        self::assertSame($plaintext, $vault->reveal($first, 'smtp.credential', $installationId));
        self::assertNotSame(
            $this->connection->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$first]),
            $this->connection->fetchOne('SELECT envelope FROM encrypted_secret WHERE id = ?', [$second]),
        );
        $envelopes = $this->connection->fetchOne('SELECT GROUP_CONCAT(envelope) FROM encrypted_secret');
        self::assertIsString($envelopes);
        self::assertStringNotContainsString($plaintext, $envelopes);

        foreach ([
            [$first, 'identity.totp-seed', $installationId],
            [$first, 'smtp.credential', '018f6f4b-03d2-7aaa-8aaa-000000000099'],
            [$second, 'smtp.credential', $installationId],
        ] as $index => [$recordId, $type, $identity]) {
            if (2 === $index) {
                $this->connection->update('encrypted_secret', ['key_version' => 99], ['id' => $recordId]);
            }
            try {
                $vault->reveal($recordId, $type, $identity);
                self::fail('Invalid encrypted-record identity was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Encrypted secret authentication failed.', $exception->getMessage());
                self::assertStringNotContainsString($plaintext, $exception->getMessage());
            }
        }
    }

    public function testOwnerArgon2idMfaAndRecoveryStorageBoundaries(): void
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $ownerSetup = $this->service(OwnerSetup::class);
        $ownerId = $ownerSetup->create('OWNER@EXAMPLE.TEST', 'synthetic owner password 2026');

        $hash = $this->connection->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$ownerId]);
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify('synthetic owner password 2026', $hash));
        self::assertSame('owner@example.test', $this->connection->fetchOne('SELECT login_identifier FROM app_user WHERE id = ?', [$ownerId]));
        self::assertSame($ownerId, $ownerSetup->create('owner@example.test', 'synthetic owner password 2026'));
        try {
            $ownerSetup->create('owner@example.test', 'another sufficiently long password');
            self::fail('An unrelated Owner replacement password was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }
        self::assertSame($hash, $this->connection->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$ownerId]));

        $interrupted = $ownerSetup->startMfa($ownerId, $installationId);
        $interruptedSecret = $this->connection->fetchOne("SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 0", [$ownerId]);
        self::assertIsString($interruptedSecret);
        $enrollment = $ownerSetup->startMfa($ownerId, $installationId);
        self::assertNotSame($interrupted->seed, $enrollment->seed);
        self::assertSame(11, $this->dbCount('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND active = 0', [$ownerId]));
        self::assertSame(0, $this->dbCount('SELECT COUNT(*) FROM encrypted_secret WHERE id = ?', [$interruptedSecret]));
        $databaseContents = json_encode($this->connection->fetchAllAssociative('SELECT * FROM mfa_credential'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($enrollment->seed, $databaseContents);
        foreach ($enrollment->recoveryCodes as $code) {
            self::assertStringNotContainsString($code, $databaseContents);
        }

        $totp = $this->service(Totp::class);
        $ownerSetup->confirmMfa($ownerId, $installationId, $totp->code($enrollment->seed, intdiv(time(), 30)), true);
        self::assertSame(11, $this->dbCount('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND active = 1', [$ownerId]));
        try {
            $ownerSetup->confirmMfa($ownerId, $installationId, $totp->code($enrollment->seed, intdiv(time(), 30)), true);
            self::fail('Repeated MFA confirmation was silently accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already confirmed', $exception->getMessage());
        }
        try {
            $ownerSetup->startMfa($ownerId, $installationId);
            self::fail('A second applicable TOTP enrollment was created.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already confirmed', $exception->getMessage());
        }
        self::assertSame(1, $this->dbCount("SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 1", [$ownerId]));
        $recoveryHash = $this->connection->fetchOne("SELECT value_hash FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code' LIMIT 1", [$ownerId]);
        self::assertIsString($recoveryHash);
        self::assertTrue(password_verify($enrollment->recoveryCodes[0], $recoveryHash));
        $consumer = new DoctrineRecoveryCodeConsumer($this->connection);
        self::assertTrue($consumer->consume($ownerId, $enrollment->recoveryCodes[0]));
        self::assertFalse($consumer->consume($ownerId, $enrollment->recoveryCodes[0]));
    }

    public function testSkippingMfaDeletesInterruptedEnrollmentMaterial(): void
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $ownerSetup = $this->service(OwnerSetup::class);
        $ownerId = $ownerSetup->create('owner@example.test', 'synthetic owner password 2026');
        $ownerSetup->startMfa($ownerId, $installationId);

        self::assertSame(11, $this->dbCount('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ?', [$ownerId]));
        self::assertSame(1, $this->dbCount("SELECT COUNT(*) FROM encrypted_secret WHERE record_type = 'identity.totp-seed'"));
        $ownerSetup->skipMfa($ownerId);
        self::assertSame(0, $this->dbCount('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ?', [$ownerId]));
        self::assertSame(0, $this->dbCount("SELECT COUNT(*) FROM encrypted_secret WHERE record_type = 'identity.totp-seed'"));
    }

    public function testMailpitCapturePersistsOnlyActuallyTestedTransportAndSafePolicies(): void
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $configuration = $this->service(ConfigurationSetup::class);
        $configuration->saveOrganisation($installationId, new OrganisationInput('Synthetic Organisation', 'https://emailer.example.test', 'en_AU', 'Australia/Sydney'));
        $configuration->testAndSaveMail($installationId, new MailInput('mailpit', 1025, 'none', '', '', 'phase3-sender@example.test', 'Emailer Next Test', ''));
        $configuration->savePolicies(new PolicyInput(60, 1, 5, 10_000, 10_485_760, 100_000, 30, 365, 'Synthetic unsubscribe footer.', 'supervised'));

        self::assertSame(0, $this->integer("SELECT tls_peer_verification FROM sender_identity WHERE singleton_key = '1'"));
        self::assertSame('none', $this->connection->fetchOne("SELECT encryption_mode FROM sender_identity WHERE singleton_key = '1'"));
        self::assertNull($this->connection->fetchOne("SELECT smtp_username FROM sender_identity WHERE singleton_key = '1'"));
        self::assertNull($this->connection->fetchOne("SELECT credential_secret_id FROM sender_identity WHERE singleton_key = '1'"));
        self::assertSame(0, $this->integer("SELECT open_tracking + click_tracking + rsvp_enabled FROM privacy_policy WHERE singleton_key = '1'"));
        self::assertSame('global', $this->connection->fetchOne("SELECT suppression_mode FROM privacy_policy WHERE singleton_key = '1'"));

        $messages = $this->mailpitMessages();
        $subjects = array_column($messages, 'Subject');
        self::assertContains('[TEST] Emailer Next Phase 3 setup', $subjects);

        foreach ([
            new MailInput('smtp.example.test', 587, 'starttls', '', '', 'phase3-sender@example.test', 'Emailer Next Test', ''),
            new MailInput('mailpit', 1025, 'starttls', '', '', 'phase3-sender@example.test', 'Emailer Next Test', ''),
            new MailInput('mailpit', 1025, 'tls', '', '', 'phase3-sender@example.test', 'Emailer Next Test', ''),
            new MailInput('mailpit', 1025, 'none', 'synthetic-user', '', 'phase3-sender@example.test', 'Emailer Next Test', ''),
            new MailInput('mailpit', 1025, 'none', '', 'synthetic-credential', 'phase3-sender@example.test', 'Emailer Next Test', ''),
        ] as $invalidInput) {
            try {
                $configuration->testAndSaveMail($installationId, $invalidInput);
                self::fail('Untested or live SMTP configuration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Mailpit', $exception->getMessage());
            }
        }
    }

    /** @return list<array<mixed>> */
    private function mailpitMessages(): array
    {
        $curl = curl_init('http://mailpit:8025/api/v1/messages?limit=100');
        self::assertNotFalse($curl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($curl);
        self::assertIsString($response);
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['messages']);

        $messages = [];
        foreach ($decoded['messages'] as $message) {
            self::assertIsArray($message);
            $messages[] = $message;
        }

        return $messages;
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

    /** @param list<mixed> $parameters */
    private function dbCount(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Test count is invalid.');
        }

        return (int) $value;
    }

    private function integer(string $sql): int
    {
        return $this->dbCount($sql);
    }
}
