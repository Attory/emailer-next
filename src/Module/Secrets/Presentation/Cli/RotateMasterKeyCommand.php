<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:secrets:rotate-master-key', description: 'Reserved fail-closed skeleton for transactional master-key rotation.')]
final class RotateMasterKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>Master-key rotation is unavailable until re-authentication and recovery controls are implemented.</error>');

        return self::FAILURE;
    }
}
