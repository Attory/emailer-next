<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Health\Application\Contract\SetupDoctor;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\InstallationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class DoctrineInstallationFinalizer implements InstallationFinalizer
{
    public function __construct(
        private Connection $connection,
        private InstallationLock $installationLock,
        private SetupDoctor $doctor,
        private LockFactory $lockFactory,
    ) {
    }

    public function finalize(): string
    {
        $lock = $this->lockFactory->createLock('emailer.phase3.finalization', 30.0, true);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Installation finalization is already in progress.');
        }

        try {
            if ($this->installationLock->exists()) {
                $completed = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1' AND completed_at IS NOT NULL");
                if (\is_string($completed)) {
                    return $completed;
                }
                throw new \RuntimeException('Installation lock and database state disagree.');
            }

            if ([] !== $this->doctor->criticalFailures()) {
                throw new \RuntimeException('Critical setup preflight failed.');
            }

            $completedAt = self::now();
            $this->connection->beginTransaction();
            try {
                $state = $this->connection->fetchAssociative("SELECT installation_uuid, completed_at FROM installation_state WHERE singleton_key = '1' FOR UPDATE");
                if (false === $state || !\is_string($state['installation_uuid'])) {
                    throw new \RuntimeException('Installation identity is unavailable.');
                }
                $installationId = $state['installation_uuid'];
                $ownerId = $this->connection->fetchOne("SELECT id FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1");
                if (!\is_string($ownerId)) {
                    throw new \RuntimeException('Initial Owner is unavailable.');
                }

                if (null === $state['completed_at']) {
                    $this->connection->insert('setup_installation_event', [
                        'id' => self::uuidV7(),
                        'owner_id' => $ownerId,
                        'action' => 'installation.completed',
                        'occurred_at' => $completedAt,
                        'result' => 'success',
                    ]);
                    $this->connection->update('installation_state', ['completed_at' => $completedAt], ['singleton_key' => '1']);
                }
                $this->connection->commit();
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }

            try {
                $this->installationLock->create([
                    'version' => 1,
                    'installation_uuid' => $installationId,
                    'completed_at' => $completedAt,
                    'schema_version' => 1,
                ]);
            } catch (\Throwable $exception) {
                $this->connection->transactional(function (Connection $connection): void {
                    $connection->delete('setup_installation_event', ['action' => 'installation.completed']);
                    $connection->update('installation_state', ['completed_at' => null], ['singleton_key' => '1']);
                });
                throw new \RuntimeException('Installation lock completion failed safely.', previous: $exception);
            }

            return $installationId;
        } finally {
            $lock->release();
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function uuidV7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);
        for ($index = 5; 0 <= $index; --$index) {
            $bytes[$index] = \chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
