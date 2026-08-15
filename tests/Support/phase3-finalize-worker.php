<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Setup\Application\Contract\InstallationFinalizer;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $testContainer = $kernel->getContainer()->get('test.service_container');
    if (!$testContainer instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    $finalizer = $testContainer->get(InstallationFinalizer::class);
    if (!$finalizer instanceof InstallationFinalizer) {
        throw new RuntimeException('Finalizer is unavailable.');
    }
    fwrite(STDOUT, $finalizer->finalize());
} finally {
    $kernel->shutdown();
}
