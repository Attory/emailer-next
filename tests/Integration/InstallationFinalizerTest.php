<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Health\Application\Contract\SetupDoctor;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Infrastructure\DoctrineInstallationFinalizer;
use App\Tests\Support\ResetsPhase3State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class InstallationFinalizerTest extends KernelTestCase
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
            $this->service(LockFactory::class),
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

    private function prepareCompleteSetup(): string
    {
        $state = $this->service(SetupState::class);
        $installationId = $state->initializeKey();
        $configuration = $this->service(ConfigurationSetup::class);
        $configuration->saveOrganisation($installationId, new OrganisationInput('Synthetic Organisation', 'https://emailer.example.test', 'en_AU', 'Australia/Sydney'));
        $state->mark('organisation');

        $owner = $this->service(OwnerSetup::class);
        $ownerId = $owner->create('owner@example.test', 'synthetic owner password 2026');
        $owner->skipMfa($ownerId);
        $state->mark('owner');

        $configuration->testAndSaveMail($installationId, new MailInput('mailpit', 1025, 'none', '', 'synthetic-credential', 'phase3-sender@example.test', 'Emailer Next Test', ''));
        $state->mark('mail');
        $configuration->savePolicies(new PolicyInput(60, 1, 5, 10_000, 10_485_760, 100_000, 30, 365, 'Synthetic unsubscribe footer.', 'supervised'));
        $state->mark('policy');
        $state->mark('capability');

        return $installationId;
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
