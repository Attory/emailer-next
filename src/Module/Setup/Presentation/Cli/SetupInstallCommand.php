<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Cli;

use App\Module\Setup\Application\Contract\CliSetupState;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\Preflight;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emailer:setup:install',
    description: 'Verify CLI ownership and run the credential-free setup preflight.',
)]
final class SetupInstallCommand extends Command
{
    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly Preflight $preflight,
        private readonly CliSetupState $cliSetupStateStore,
        private readonly Clock $clock,
        private readonly string $publicDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->installationLock->exists()) {
            $io->error('Setup is permanently locked for this installation.');

            return self::FAILURE;
        }

        $checks = $this->preflight->run($this->publicDirectory);
        $rows = [];
        $passed = true;

        foreach ($checks as $check) {
            $rows[] = [$check->name, $check->passed ? 'PASS' : 'FAIL', $check->message];
            $passed = $passed && $check->passed;
        }

        $io->table(['Check', 'Result', 'Guidance'], $rows);

        if (!$passed) {
            $io->error('Correct the failed preflight checks before continuing setup.');

            return self::FAILURE;
        }

        $this->cliSetupStateStore->recordOwnershipVerified($this->clock->now());
        $io->success('CLI ownership and Phase 2 preflight are verified. Credential setup remains unavailable until Phase 3.');

        return self::SUCCESS;
    }
}
