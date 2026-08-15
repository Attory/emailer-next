<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$signalFile = $argv[1] ?? null;
if (!is_string($signalFile) || '' === $signalFile) {
    throw new RuntimeException('Synthetic coordination path is missing.');
}

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $testContainer = $kernel->getContainer()->get('test.service_container');
    if (!$testContainer instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    $lifecycle = $testContainer->get(SetupLifecycle::class);
    $connection = $testContainer->get(Connection::class);
    if (!$lifecycle instanceof SetupLifecycle || !$connection instanceof Connection) {
        throw new RuntimeException('Setup lifecycle test services are unavailable.');
    }

    $lifecycle->mutate(static function () use ($connection, $signalFile): void {
        if (false === file_put_contents($signalFile, 'locked', LOCK_EX)) {
            throw new RuntimeException('Synthetic coordination signal failed.');
        }
        usleep(750_000);
        $connection->update('organisation_settings', [
            'display_name' => 'Delayed Synthetic Organisation',
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['singleton_key' => '1']);
    });
    fwrite(STDOUT, 'mutated');
} finally {
    $kernel->shutdown();
}
