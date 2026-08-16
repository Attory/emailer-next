<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Application\LoginThrottle;
use App\Module\Identity\Application\LoginThrottled;
use App\Module\Identity\Application\MfaVerifier;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$operation = $argv[1] ?? '';
$first = $argv[2] ?? '';
$second = $argv[3] ?? '';
$third = $argv[4] ?? '';
$ready = $argv[5] ?? '';
$release = $argv[6] ?? '';
if (!in_array($operation, ['login', 'reauthentication', 'totp'], true) || '' === $ready || '' === $release) {
    throw new RuntimeException('Worker arguments are missing.');
}

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    if (false === file_put_contents($ready, 'ready', LOCK_EX)) {
        throw new RuntimeException('Worker readiness signal cannot be published.');
    }
    $deadline = microtime(true) + 10.0;
    while (!file_exists($release) && microtime(true) < $deadline) {
        usleep(1_000);
    }
    if (!file_exists($release)) {
        throw new RuntimeException('Worker release signal was not received.');
    }

    try {
        if ('totp' === $operation) {
            $verifier = $container->get(MfaVerifier::class);
            if (!$verifier instanceof MfaVerifier) {
                throw new RuntimeException('MFA verifier is unavailable.');
            }
            fwrite(STDOUT, $verifier->verify($first, $second) ? 'accepted' : 'rejected');
        } else {
            $throttle = $container->get(LoginThrottle::class);
            if (!$throttle instanceof LoginThrottle) {
                throw new RuntimeException('Authentication throttle is unavailable.');
            }
            if ('login' === $operation) {
                $throttle->reserveLogin($first, $second);
            } else {
                $throttle->reserveReauthentication($first, $second, $third);
            }
            fwrite(STDOUT, 'accepted');
        }
    } catch (LoginThrottled) {
        fwrite(STDOUT, 'rejected');
    }
} finally {
    $kernel->shutdown();
}
