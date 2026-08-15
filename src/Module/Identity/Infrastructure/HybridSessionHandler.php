<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Setup\Application\Contract\InstallationLock;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;

final class HybridSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private \SessionHandlerInterface $active;

    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly DatabaseSessionHandler $database,
        string $setupSessionDirectory,
    ) {
        $this->active = new NativeFileSessionHandler($setupSessionDirectory);
    }

    public function open(string $path, string $name): bool
    {
        if ($this->installationLock->exists()) {
            $this->active = $this->database;
        }

        return $this->active->open($path, $name);
    }

    public function close(): bool
    {
        return $this->active->close();
    }

    public function read(string $id): string|false
    {
        return $this->active->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return $this->active->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->active->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->active->gc($max_lifetime);
    }

    public function validateId(string $id): bool
    {
        return $this->active instanceof \SessionUpdateTimestampHandlerInterface
            ? $this->active->validateId($id)
            : true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->active instanceof \SessionUpdateTimestampHandlerInterface
            ? $this->active->updateTimestamp($id, $data)
            : $this->active->write($id, $data);
    }
}
