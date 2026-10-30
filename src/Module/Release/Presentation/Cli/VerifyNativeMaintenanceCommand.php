<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Health\Application\Contract\RuntimeVerification;
use App\Module\Release\Application\ReleaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:release:verify-native-maintenance', description: 'Verify the authenticated activated native operation offline without granting sending readiness.')]
final class VerifyNativeMaintenanceCommand extends Command
{
    public function __construct(private readonly ReleaseManager $releases, private readonly RuntimeVerification $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operation', InputArgument::REQUIRED);
        $this->addArgument('package', InputArgument::REQUIRED);
        $this->addArgument('source-package', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = $input->getArgument('operation');
        $package = $input->getArgument('package');
        $source = $input->getArgument('source-package');
        if (!\is_string($operation) || !\is_string($package) || !\is_string($source)) {
            return Command::INVALID;
        }
        $this->releases->assertNativeMaintenance($operation, $package, $source);
        $result = $this->health->verifyRuntime(true);
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true === $result['runtime_verified'] ? Command::SUCCESS : Command::FAILURE;
    }
}
