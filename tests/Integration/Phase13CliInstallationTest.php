<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\SetupState;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class Phase13CliInstallationTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testInteractiveCliInstallerCompletesWithoutEchoingSecrets(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $connection = $this->phase4Service(Connection::class);
        $lock = $this->phase4Service(InstallationLock::class);
        if ($lock->exists()) {
            $lock->remove();
        }
        $masterPath = self::getContainer()->getParameter('emailer.master_key_file');
        self::assertIsString($masterPath);
        if (file_exists($masterPath) || is_link($masterPath)) {
            self::assertTrue(unlink($masterPath));
        }
        foreach (['setup_installation_event', 'mfa_credential', 'app_user', 'encrypted_secret'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement(<<<'SQL'
UPDATE installation_state SET
    installation_uuid = NULL,
    database_ready_at = UTC_TIMESTAMP(6),
    key_ready_at = NULL,
    organisation_ready_at = NULL,
    owner_ready_at = NULL,
    mail_ready_at = NULL,
    policy_ready_at = NULL,
    capability_ready_at = NULL,
    completed_at = NULL
WHERE singleton_key = '1'
SQL);

        $params = $connection->getParams();
        $host = $params['host'] ?? null;
        $database = $params['dbname'] ?? null;
        $username = $params['user'] ?? null;
        $password = $params['password'] ?? null;
        $port = $params['port'] ?? 3306;
        self::assertIsString($host);
        self::assertIsString($database);
        self::assertIsString($username);
        self::assertIsString($password);
        if (!\is_int($port) && !\is_string($port)) {
            throw new \RuntimeException('Test database port is invalid.');
        }
        $kernel = self::$kernel;
        if (null === $kernel) {
            throw new \RuntimeException('Test kernel is unavailable.');
        }
        $tester = new CommandTester((new Application($kernel))->find('emailer:setup:install'));
        $tester->setInputs([
            $host,
            (string) $port,
            $database,
            $username,
            $password,
            'Phase 13 CLI Organisation',
            'https://cli-install.example.test',
            'en_AU',
            'Australia/Sydney',
            'cli-owner@example.test',
            'correct horse battery staple',
            'correct horse battery staple',
            'mailpit',
            '1025',
            'phase13-cli-sender@example.test',
            'Emailer Next CLI Test',
            'supervised',
            'yes',
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--complete' => true]));
        self::assertTrue($lock->exists());
        self::assertTrue($this->phase4Service(SetupState::class)->progress()->completed);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1")));
        self::assertStringNotContainsString('local-only', $tester->getDisplay());
        self::assertStringNotContainsString('correct horse battery staple', $tester->getDisplay());
        self::assertStringNotContainsString('password=', strtolower($tester->getDisplay()));
    }
}
