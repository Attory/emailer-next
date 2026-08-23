<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Release\Application\ReleaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'emailer:release:update', description: 'Run the explicit verified backup, migration, atomic switch, and health-checked update workflow.')]
final class UpdateReleaseCommand extends Command
{
    public function __construct(private readonly ReleaseManager $releases)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper || !$input->isInteractive() || true !== $helper->ask($input, $output, new ConfirmationQuestion('Apply this verified update with maintenance and backup? [y/N] ', false))) {
            return Command::FAILURE;
        }
        $package = $input->getArgument('package');
        if (!\is_string($package)) {
            return Command::INVALID;
        }
        $this->releases->update($package);
        $output->writeln('<info>Update completed and passed Doctor.</info>');

        return Command::SUCCESS;
    }
}
