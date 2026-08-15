<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:secrets:replace', description: 'Reserved fail-closed skeleton for a future re-authenticated secret replacement workflow.')]
final class ReplaceSecretCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>Secret replacement is unavailable until the authenticated administration controls are implemented.</error>');

        return self::FAILURE;
    }
}
