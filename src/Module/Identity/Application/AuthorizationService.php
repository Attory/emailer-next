<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;

final readonly class AuthorizationService
{
    public function __construct(private AuditSink $audit)
    {
    }

    public function assertAtLeast(AuthenticatedUser $actor, AdministrativeRole $required, string $action): void
    {
        if (!$actor->active() || $actor->role()->rank() < $required->rank()) {
            $this->deny($actor, $action);
        }
    }

    public function assertCanManageUser(
        AuthenticatedUser $actor,
        AdministrativeRole $currentRole,
        AdministrativeRole $requestedRole,
        string $action,
    ): void {
        $this->assertAtLeast($actor, AdministrativeRole::Administrator, $action);
        if (AdministrativeRole::Owner === $currentRole || AdministrativeRole::Owner === $requestedRole) {
            $this->assertAtLeast($actor, AdministrativeRole::Owner, $action);
        }
    }

    public function recordDenial(AuthenticatedUser $actor, string $action): void
    {
        $this->audit->append(new AuditRecord(
            $actor->id(),
            'authorization.denied',
            null,
            'denied',
            ['operation' => substr(preg_replace('/[^a-z0-9_.-]/', '_', strtolower($action)) ?? 'unknown', 0, 96)],
        ));
    }

    private function deny(AuthenticatedUser $actor, string $action): never
    {
        $this->recordDenial($actor, $action);

        throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Access denied.');
    }
}
