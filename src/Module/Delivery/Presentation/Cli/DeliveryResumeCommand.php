<?php

declare(strict_types=1);

namespace App\Module\Delivery\Presentation\Cli;

use App\Module\Delivery\Application\Contract\SendingGate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:delivery:resume', description: 'Allow delivery workers to claim work after a completed maintenance operation.')]
final class DeliveryResumeCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly SendingGate $sendingGate)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sendingGate->assertMaintenanceReady();
        if (1 !== $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1' AND schema_version = 1 AND accepting_work = 0")) {
            throw new \RuntimeException('Delivery runtime was not quiesced or compatible.');
        }
        $output->writeln('accepting_work=1 schema_version=1');

        return Command::SUCCESS;
    }
}
