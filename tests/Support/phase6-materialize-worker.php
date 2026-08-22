<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$campaignId = $argv[1] ?? '';
$version = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : 0;
$ownSignal = $argv[3] ?? '';
$peerSignal = $argv[4] ?? '';
if (1 !== preg_match('/^[0-9a-f-]{36}$/', $campaignId) || 1 > $version || '' === $ownSignal || '' === $peerSignal) {
    throw new RuntimeException('Phase 6 worker arguments are invalid.');
}

$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    $gateway = $container->get(CampaignDeliveryGateway::class);
    if (!$gateway instanceof CampaignDeliveryGateway) {
        throw new RuntimeException('Delivery gateway is unavailable.');
    }
    if (false === file_put_contents($ownSignal, 'ready', LOCK_EX)) {
        throw new RuntimeException('Worker signal cannot be published.');
    }
    $deadline = microtime(true) + 5.0;
    while (!file_exists($peerSignal) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!file_exists($peerSignal)) {
        throw new RuntimeException('Peer worker did not become ready.');
    }
    $gateway->materializeAndDispatch($campaignId, $version);
    fwrite(STDOUT, 'done');
} finally {
    $kernel->shutdown();
}
