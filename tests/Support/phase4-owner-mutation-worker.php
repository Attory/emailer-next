<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\UserAdministration;
use Doctrine\DBAL\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$operation = $argv[1] ?? '';
$actorId = $argv[2] ?? '';
$targetId = $argv[3] ?? '';
$ownSignal = $argv[4] ?? '';
$peerSignal = $argv[5] ?? '';
if (!in_array($operation, ['demote', 'disable', 'delete'], true) || '' === $actorId || '' === $targetId || '' === $ownSignal || '' === $peerSignal) {
    throw new RuntimeException('Worker arguments are missing.');
}

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $testContainer = $kernel->getContainer()->get('test.service_container');
    if (!$testContainer instanceof Psr\Container\ContainerInterface) {
        throw new RuntimeException('Test container is unavailable.');
    }
    $connection = $testContainer->get(Connection::class);
    $users = $testContainer->get(UserAdministration::class);
    if (!$connection instanceof Connection || !$users instanceof UserAdministration) {
        throw new RuntimeException('Phase 4 worker services are unavailable.');
    }
    $record = $connection->fetchAssociative('SELECT login_identifier, password_hash, role, active FROM app_user WHERE id = ?', [$actorId]);
    if (false === $record
        || !is_string($record['login_identifier'] ?? null)
        || !is_string($record['password_hash'] ?? null)
        || !is_string($record['role'] ?? null)
        || !is_numeric($record['active'] ?? null)
    ) {
        throw new RuntimeException('Worker user is unavailable.');
    }
    $actor = new AuthenticatedUser(
        $actorId,
        $record['login_identifier'],
        $record['password_hash'],
        AdministrativeRole::from($record['role']),
        1 === (int) $record['active'],
    );
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

    try {
        match ($operation) {
            'demote' => $users->update($actor, $targetId, AdministrativeRole::Administrator, true),
            'disable' => $users->update($actor, $targetId, AdministrativeRole::Owner, false),
            'delete' => $users->delete($actor, $targetId),
        };
        fwrite(STDOUT, 'updated');
    } catch (Throwable) {
        fwrite(STDOUT, 'rejected');
    }
} finally {
    $kernel->shutdown();
}
