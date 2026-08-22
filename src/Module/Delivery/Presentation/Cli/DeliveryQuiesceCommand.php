<?php

declare(strict_types=1);

namespace App\Module\Delivery\Presentation\Cli;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:delivery:quiesce', description: 'Stop new delivery claims and report the in-flight count.')]
final class DeliveryQuiesceCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
        $active = $this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        if (!is_numeric($active)) {
            throw new \UnexpectedValueException('Delivery runtime count is invalid.');
        }
        $output->writeln('accepting_work=0 active_claims='.(int) $active);

        return Command::SUCCESS;
    }
}
