<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Configuration\Application\Contract\AppearanceInitialization;
use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Health\Application\Contract\SetupDoctor;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Identity\Application\Totp;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use App\Module\Setup\Infrastructure\DoctrineInstallationFinalizer;
use App\Tests\Support\ResetsPhase3State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class InstallationFinalizerTest extends KernelTestCase
{
    use ResetsPhase3State;

    private Connection $connection;
    private string $signalFile = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = $this->service(Connection::class);
        $this->resetPhase3Database($this->connection);
        $this->removePhase3Files();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->signalFile && (file_exists($this->signalFile) || is_link($this->signalFile))) {
            unlink($this->signalFile);
        }
        $this->removePhase3Files();
        parent::tearDown();
    }

    public function testIncompleteFinalizationFailsClosedWithoutLock(): void
    {
        $this->service(SetupState::class)->initializeKey();
        $finalizer = $this->service(InstallationFinalizer::class);

        $this->expectException(\RuntimeException::class);
        try {
            $finalizer->finalize();
        } finally {
            self::assertNull($this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'"));
            self::assertSame(0, $this->dbCount('SELECT COUNT(*) FROM setup_installation_event'));
            self::assertFalse($this->service(InstallationLock::class)->exists());
        }
    }

    public function testLockPublicationFailureCompensatesDatabaseCompletion(): void
    {
        $this->prepareCompleteSetup();
        $failingLock = new class implements InstallationLock {
            public function exists(): bool
            {
                return false;
            }

            public function create(array $metadata): void
            {
                throw new \RuntimeException('Synthetic lock publication failure.');
            }

            public function remove(): void
            {
            }
        };
        $finalizer = new DoctrineInstallationFinalizer(
            $this->connection,
            $failingLock,
            $this->service(SetupDoctor::class),
            $this->service(SetupLifecycle::class),
        );

        try {
            $finalizer->finalize();
            self::fail('Failed lock publication was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installation lock completion failed safely.', $exception->getMessage());
        }

        self::assertNull($this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'"));
        self::assertSame(0, $this->dbCount('SELECT COUNT(*) FROM setup_installation_event'));
    }

    public function testAppearanceInitializationFailureCanResumeWithoutChangingTheOwner(): void
    {
        $installationId = $this->prepareCompleteSetup();
        $before = $this->connection->fetchAllAssociative('SELECT id, password_hash FROM app_user ORDER BY id');
        $appearance = $this->createMock(AppearanceInitialization::class);
        $appearance->expects(self::exactly(2))->method('initializeFresh')->willReturnCallback(static function (): void {
            /** @var int $attempt */
            static $attempt = 0;
            if (0 === $attempt++) {
                throw new \RuntimeException('Synthetic appearance storage interruption.');
            }
        });
        $finalizer = new DoctrineInstallationFinalizer(
            $this->connection,
            $this->service(InstallationLock::class),
            $this->service(SetupDoctor::class),
            $this->service(SetupLifecycle::class),
            appearance: $appearance,
        );
        try {
            $finalizer->finalize();
            self::fail('Interrupted appearance initialization was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installation lock completion failed safely.', $exception->getMessage());
        }
        self::assertFalse($this->service(InstallationLock::class)->exists());
        self::assertNull($this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'"));
        self::assertSame(0, $this->dbCount('SELECT COUNT(*) FROM setup_installation_event'));
        self::assertSame($installationId, $finalizer->finalize());
        self::assertSame($installationId, $finalizer->finalize());
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT id, password_hash FROM app_user ORDER BY id'));
        self::assertSame(1, $this->dbCount('SELECT COUNT(*) FROM setup_installation_event'));
    }

    public function testConcurrentAndRepeatedFinalizationIsSingleUse(): void
    {
        $installationId = $this->prepareCompleteSetup();
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        self::ensureKernelShutdown();

        $first = new Process([PHP_BINARY, 'tests/Support/phase3-finalize-worker.php'], $projectDirectory);
        $second = new Process([PHP_BINARY, 'tests/Support/phase3-finalize-worker.php'], $projectDirectory);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        self::assertTrue($first->isSuccessful(), $first->getOutput().$first->getErrorOutput().' exit '.(string) $first->getExitCode());
        self::assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput().' exit '.(string) $second->getExitCode());
        self::assertSame($installationId, $first->getOutput());
        self::assertSame($installationId, $second->getOutput());

        self::bootKernel();
        $this->connection = $this->service(Connection::class);
        self::assertSame(1, $this->dbCount('SELECT COUNT(*) FROM setup_installation_event'));
        self::assertNotNull($this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'"));
        self::assertTrue($this->service(InstallationLock::class)->exists());
        self::assertSame($installationId, $this->service(InstallationFinalizer::class)->finalize());
    }

    public function testDelayedMutationFinishesBeforeFinalizationAndEveryPostLockMutationFails(): void
    {
        $installationId = $this->prepareCompleteSetup();
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        $this->signalFile = sys_get_temp_dir().'/emailer-phase3-lifecycle-'.bin2hex(random_bytes(8));
        self::ensureKernelShutdown();

        $mutation = new Process([PHP_BINARY, 'tests/Support/phase3-delayed-mutation-worker.php', $this->signalFile], $projectDirectory);
        $mutation->start();
        $deadline = microtime(true) + 5.0;
        while (!file_exists($this->signalFile) && microtime(true) < $deadline) {
            if ($mutation->isTerminated()) {
                break;
            }
            usleep(20_000);
        }
        self::assertFileExists($this->signalFile, $mutation->getOutput().$mutation->getErrorOutput());

        $finalization = new Process([PHP_BINARY, 'tests/Support/phase3-finalize-worker.php'], $projectDirectory);
        $finalization->start();
        $mutation->wait();
        $finalization->wait();
        self::assertTrue($mutation->isSuccessful(), $mutation->getOutput().$mutation->getErrorOutput());
        self::assertTrue($finalization->isSuccessful(), $finalization->getOutput().$finalization->getErrorOutput());
        self::assertSame('mutated', $mutation->getOutput());
        self::assertSame($installationId, $finalization->getOutput());

        self::bootKernel();
        $this->connection = $this->service(Connection::class);
        self::assertSame('Delayed Synthetic Organisation', $this->connection->fetchOne("SELECT display_name FROM organisation_settings WHERE singleton_key = '1'"));
        self::assertTrue($this->service(InstallationLock::class)->exists());
        $before = $this->phase3MutableSnapshot();
        $state = $this->service(SetupState::class);
        $databaseSetup = $this->service(DatabaseSetup::class);
        $configuration = $this->service(ConfigurationSetup::class);
        $owner = $this->service(OwnerSetup::class);
        $ownerId = $this->connection->fetchOne("SELECT id FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1");
        self::assertIsString($ownerId);
        $databaseParams = $this->connection->getParams();
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
        $databaseInput = new DatabaseInput($databaseHost, (int) $databasePort, $databaseName, $databaseUser, $databasePassword);

        foreach ([
            static fn () => $databaseSetup->configure($databaseInput),
            static fn () => $state->initializeKey(),
            static fn () => $state->mark('organisation'),
            static fn () => $configuration->saveOrganisation($installationId, new OrganisationInput('Rejected Organisation', 'https://rejected.example.test', 'en_AU', 'Australia/Sydney')),
            static fn () => $owner->create('owner@example.test', 'synthetic owner password 2026'),
            static fn () => $owner->startMfa($ownerId, $installationId),
            static fn () => $owner->confirmMfa($ownerId, $installationId, '000000', true),
            static fn () => $owner->skipMfa($ownerId),
            static fn () => $configuration->testAndSaveMail($installationId, new MailInput('mailpit', 1025, 'none', '', '', 'phase3-sender@example.test', 'Rejected Test', '')),
            static fn () => $configuration->savePolicies(new PolicyInput(61, 1, 5, 10_000, 10_485_760, 100_000, 30, 365, 'Rejected footer.', 'supervised')),
        ] as $mutationAttempt) {
            $this->expectLockedMutation($mutationAttempt);
        }

        self::assertSame($before, $this->phase3MutableSnapshot());
    }

    private function prepareCompleteSetup(): string
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $configuration = $this->service(ConfigurationSetup::class);
        $configuration->saveOrganisation($installationId, new OrganisationInput('Synthetic Organisation', 'https://emailer.example.test', 'en_AU', 'Australia/Sydney'));
        $state->mark('organisation');

        $owner = $this->service(OwnerSetup::class);
        $ownerId = $owner->create('owner@example.test', 'synthetic owner password 2026');
        $enrollment = $owner->startMfa($ownerId, $installationId);
        $owner->confirmMfa($ownerId, $installationId, $this->service(Totp::class)->code($enrollment->seed, intdiv(time(), 30)), true);
        $state->mark('owner');

        $configuration->testAndSaveMail($installationId, new MailInput('mailpit', 1025, 'none', '', '', 'phase3-sender@example.test', 'Emailer Next Test', ''));
        $state->mark('mail');
        $configuration->savePolicies(new PolicyInput(60, 1, 5, 10_000, 10_485_760, 100_000, 30, 365, 'Synthetic unsubscribe footer.', 'supervised'));
        $state->mark('policy');
        $state->mark('capability');

        return $installationId;
    }

    /** @param callable(): mixed $mutation */
    private function expectLockedMutation(callable $mutation): void
    {
        try {
            $mutation();
            self::fail('A Phase 3 mutation succeeded after installation-lock publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('locked', strtolower($exception->getMessage()));
        }
    }

    private function phase3MutableSnapshot(): string
    {
        $snapshot = [];
        foreach (['installation_state', 'organisation_settings', 'app_user', 'mfa_credential', 'sender_identity', 'encrypted_secret', 'delivery_policy', 'privacy_policy'] as $table) {
            $snapshot[$table] = $this->connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY 1');
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
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

    private function dbCount(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Test count is invalid.');
        }

        return (int) $value;
    }
}
