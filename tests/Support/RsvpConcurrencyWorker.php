<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\RecipientActions\Application\RecipientAnalyticsService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$token = trim((string) stream_get_contents(STDIN));
$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $testContainer = $kernel->getContainer()->get('test.service_container');
    if (!$testContainer instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    $service = $testContainer->get(RecipientAnalyticsService::class);
    if (!$service instanceof RecipientAnalyticsService) {
        throw new RuntimeException('RSVP service is unavailable.');
    }
    $service->submitRsvp($token, 'yes', 0, 'Concurrent response');
    fwrite(STDOUT, "DONE\n");
} finally {
    $kernel->shutdown();
}
