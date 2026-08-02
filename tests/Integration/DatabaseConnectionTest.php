<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class DatabaseConnectionTest extends KernelTestCase
{
    public function testMariaDbConnectionIsAvailable(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertStringContainsString('MariaDB', $connection->getDatabasePlatform()::class);
    }
}
