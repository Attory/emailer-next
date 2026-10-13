<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Identity\Application\Contract\SecurityLevelReader;
use App\Module\Identity\Application\Contract\SensitiveActionAuthorizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class SecurityLevelManager
{
    public function __construct(
        private Connection $connection,
        private SecurityLevelReader $reader,
        private AdministrativeAuthorization $authorization,
        private SensitiveActionAuthorizer $reauthentication,
        private AuditSink $audit,
    ) {
    }

    public function current(): string
    {
        return $this->reader->current();
    }

    public function change(AdministrativeActor $actor, SessionInterface $session, string $requested, bool $confirmed, string $sourceHash): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Owner, 'configuration.security_level.change');
        if (!\in_array($requested, ['high', 'normal'], true)) {
            throw new \InvalidArgumentException('Security level is unsupported.');
        }

        $this->connection->transactional(function () use ($actor, $session, $requested, $confirmed, $sourceHash): void {
            $row = $this->connection->fetchAssociative("SELECT installation_uuid, security_level FROM organisation_settings WHERE singleton_key = '1' FOR UPDATE");
            if (false === $row || !\is_string($row['installation_uuid'] ?? null) || !\is_string($row['security_level'] ?? null) || !\in_array($row['security_level'], ['high', 'normal'], true)) {
                throw new \UnexpectedValueException('Installation security state is unavailable.');
            }
            $previous = $row['security_level'];
            if ('high' === $previous && 'normal' === $requested) {
                if (!$confirmed) {
                    throw new \InvalidArgumentException('Lowering the level requires deliberate confirmation.');
                }
                $this->reauthentication->assertRecentUnderHigh($session, 'security_level_change');
            }
            if ($requested === $previous) {
                return;
            }
            $updated = $this->connection->update('organisation_settings', [
                'security_level' => $requested,
                'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ], ['singleton_key' => '1', 'security_level' => $previous]);
            if (1 !== $updated) {
                throw new \UnexpectedValueException('Installation security transition could not be stored.');
            }
            $this->audit->append(new AuditRecord(
                $actor->id(),
                'configuration.security_level.changed',
                $row['installation_uuid'],
                'success',
                ['old_level' => $previous, 'new_level' => $requested],
                $sourceHash,
            ));
        });
    }
}
