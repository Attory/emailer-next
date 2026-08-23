<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Cli;

use App\Module\Backup\Application\BackupEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'emailer:backup:restore', description: 'Restore an authenticated backup while delivery is quiesced.')]
final class RestoreBackupCommand extends Command
{
    public function __construct(private readonly BackupEngine $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper || !$input->isInteractive() || true !== $helper->ask($input, $output, new ConfirmationQuestion('Restore this verified backup? Delivery must already be quiesced [y/N] ', false))) {
            return Command::FAILURE;
        }
        $path = $input->getArgument('path');
        if (!\is_string($path)) {
            return Command::INVALID;
        }
        $this->backups->restore($path);
        $output->writeln('<info>Restore completed and delivery remains quiesced. Run Doctor before explicit resume.</info>');

        return Command::SUCCESS;
    }
}
