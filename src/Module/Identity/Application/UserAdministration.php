<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class UserAdministration
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthorizationService $authorization,
        private SessionRegistry $sessions,
        private AuditSink $audit,
        private PasswordPolicy $passwordPolicy,
    ) {
    }

    /** @return list<array{id: string, login_identifier: string, role: string, active: bool, created_at: string, last_login_at: ?string}> */
    public function list(AuthenticatedUser $actor): array
    {
        $this->authorization->assertAtLeast($actor, AdministrativeRole::Administrator, 'identity.users.list');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, login_identifier, role, active, created_at, last_login_at FROM app_user ORDER BY login_identifier',
        );

        $result = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id'] ?? null)
                || !\is_string($row['login_identifier'] ?? null)
                || !\is_string($row['role'] ?? null)
                || !\is_string($row['created_at'] ?? null)
            ) {
                continue;
            }
            $result[] = [
                'id' => $row['id'],
                'login_identifier' => $row['login_identifier'],
                'role' => $row['role'],
                'active' => is_numeric($row['active'] ?? null) && 1 === (int) $row['active'],
                'created_at' => $row['created_at'],
                'last_login_at' => \is_string($row['last_login_at'] ?? null) ? $row['last_login_at'] : null,
            ];
        }

        return $result;
    }

    public function create(AuthenticatedUser $actor, string $login, string $password, AdministrativeRole $role, ?string $sourceHash = null): string
    {
        $this->authorization->assertCanManageUser($actor, AdministrativeRole::Auditor, $role, 'identity.users.create', $sourceHash);
        $normalized = IdentityUtil::normalizeLogin($login);
        $this->passwordPolicy->assertAcceptable($password, $normalized);
        $hash = $this->passwordHasher->hashPassword(new OwnerPasswordSubject(), $password);
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new \RuntimeException('Argon2id password hashing is unavailable.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($actor, $normalized, $hash, $role): string {
            $id = IdentityUtil::uuidV7();
            $now = IdentityUtil::now();
            $connection->insert('app_user', [
                'id' => $id,
                'login_identifier' => $normalized,
                'password_hash' => $hash,
                'role' => $role->value,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'password_changed_at' => $now,
                'mfa_changed_at' => null,
                'last_login_at' => null,
                'disabled_at' => null,
            ]);
            $this->audit->append(new AuditRecord(
                $actor->id(),
                'identity.user.created',
                $id,
                'success',
                ['role' => $role->value, 'active' => true],
            ));

            return $id;
        });
    }

    public function update(AuthenticatedUser $actor, string $targetId, AdministrativeRole $role, bool $active, ?string $sourceHash = null): AuthenticatedUser
    {
        $this->authorization->assertCanManageUser($actor, $this->targetRole($targetId), $role, 'identity.users.update', $sourceHash);
        try {
            return $this->connection->transactional(function (Connection $connection) use ($actor, $targetId, $role, $active, $sourceHash): AuthenticatedUser {
                $target = $connection->fetchAssociative('SELECT id, login_identifier, password_hash, role, active FROM app_user WHERE id = ? FOR UPDATE', [$targetId]);
                if (false === $target
                    || !\is_string($target['login_identifier'] ?? null)
                    || !\is_string($target['password_hash'] ?? null)
                    || !\is_string($target['role'] ?? null)
                ) {
                    throw new \RuntimeException('User is unavailable.');
                }
                $currentRole = AdministrativeRole::from($target['role']);
                $this->authorization->assertCanManageUser($actor, $currentRole, $role, 'identity.users.update', $sourceHash);
                $this->lockAndAssertOwnerInvariant($connection, $targetId, $currentRole, $role, $active);

                $connection->update('app_user', [
                    'role' => $role->value,
                    'active' => $active ? 1 : 0,
                    'updated_at' => IdentityUtil::now(),
                    'disabled_at' => $active ? null : IdentityUtil::now(),
                ], ['id' => $targetId]);
                $this->sessions->revokeAll($targetId);
                $this->audit->append(new AuditRecord(
                    $actor->id(),
                    'identity.user.updated',
                    $targetId,
                    'success',
                    ['from_role' => $currentRole->value, 'to_role' => $role->value, 'active' => $active],
                ));

                return new AuthenticatedUser($targetId, $target['login_identifier'], $target['password_hash'], $role, $active);
            });
        } catch (AccessDeniedException $exception) {
            $this->authorization->recordDenial($actor, 'identity.users.update', $sourceHash);
            throw $exception;
        }
    }

    public function delete(AuthenticatedUser $actor, string $targetId, ?string $sourceHash = null): void
    {
        if (hash_equals($actor->id(), $targetId)) {
            throw new \RuntimeException('The active account cannot delete itself.');
        }

        $currentRole = $this->targetRole($targetId);
        $this->authorization->assertCanManageUser($actor, $currentRole, $currentRole, 'identity.users.delete', $sourceHash);
        try {
            $this->connection->transactional(function (Connection $connection) use ($actor, $targetId, $sourceHash): void {
                $target = $connection->fetchAssociative('SELECT id, role, active FROM app_user WHERE id = ? FOR UPDATE', [$targetId]);
                if (false === $target || !\is_string($target['role'] ?? null)) {
                    throw new \RuntimeException('User is unavailable.');
                }
                $currentRole = AdministrativeRole::from($target['role']);
                $this->authorization->assertCanManageUser($actor, $currentRole, $currentRole, 'identity.users.delete', $sourceHash);
                $this->lockAndAssertOwnerInvariant($connection, $targetId, $currentRole, AdministrativeRole::Auditor, false);
                $this->audit->append(new AuditRecord(
                    $actor->id(),
                    'identity.user.deleted',
                    $targetId,
                    'success',
                    ['role' => $currentRole->value],
                ));
                $this->sessions->revokeAll($targetId);
                $secretIds = $connection->fetchFirstColumn(
                    'SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND encrypted_secret_id IS NOT NULL FOR UPDATE',
                    [$targetId],
                );
                $connection->delete('mfa_credential', ['user_id' => $targetId]);
                foreach ($secretIds as $secretId) {
                    if (\is_string($secretId)) {
                        $connection->delete('encrypted_secret', ['id' => $secretId]);
                    }
                }
                $connection->delete('app_user', ['id' => $targetId]);
            });
        } catch (AccessDeniedException $exception) {
            $this->authorization->recordDenial($actor, 'identity.users.delete', $sourceHash);
            throw $exception;
        }
    }

    public function changePassword(AuthenticatedUser $actor, string $currentPassword, string $newPassword, string $currentSessionId): AuthenticatedUser
    {
        $this->authorization->assertAtLeast($actor, AdministrativeRole::Auditor, 'identity.password.change');
        $this->passwordPolicy->assertAcceptable($newPassword, $actor->getUserIdentifier());
        if (!password_verify($currentPassword, $actor->getPassword())) {
            throw new \RuntimeException('Password change was not accepted.');
        }
        $hash = $this->passwordHasher->hashPassword(new OwnerPasswordSubject(), $newPassword);
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new \RuntimeException('Argon2id password hashing is unavailable.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($actor, $hash, $currentSessionId): AuthenticatedUser {
            $connection->update('app_user', [
                'password_hash' => $hash,
                'password_changed_at' => IdentityUtil::now(),
                'updated_at' => IdentityUtil::now(),
            ], ['id' => $actor->id()]);
            $this->sessions->revokeAll($actor->id(), $currentSessionId);
            $this->audit->append(new AuditRecord(
                $actor->id(),
                'identity.password.changed',
                $actor->id(),
                'success',
                ['authority_invalidated' => true],
            ));

            return new AuthenticatedUser($actor->id(), $actor->getUserIdentifier(), $hash, $actor->role(), true);
        });
    }

    private function lockAndAssertOwnerInvariant(
        Connection $connection,
        string $targetId,
        AdministrativeRole $currentRole,
        AdministrativeRole $requestedRole,
        bool $requestedActive,
    ): void {
        $owners = $connection->fetchFirstColumn("SELECT id FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1 ORDER BY id FOR UPDATE");
        $removesTargetOwner = AdministrativeRole::Owner === $currentRole
            && (AdministrativeRole::Owner !== $requestedRole || !$requestedActive);
        if ($removesTargetOwner && 1 === \count($owners) && \is_string($owners[0] ?? null) && hash_equals($owners[0], $targetId)) {
            throw new \RuntimeException('At least one active Owner must remain.');
        }
    }

    private function targetRole(string $targetId): AdministrativeRole
    {
        $role = $this->connection->fetchOne('SELECT role FROM app_user WHERE id = ?', [$targetId]);
        if (!\is_string($role)) {
            throw new \RuntimeException('User is unavailable.');
        }

        return AdministrativeRole::from($role);
    }
}
