<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\IdentityUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AuthenticatedUser> */
final readonly class DoctrineUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): AuthenticatedUser
    {
        try {
            $login = IdentityUtil::normalizeLogin($identifier);
        } catch (\InvalidArgumentException) {
            throw new UserNotFoundException();
        }

        $record = $this->connection->fetchAssociative(
            'SELECT id, login_identifier, password_hash, role, active FROM app_user WHERE login_identifier = ?',
            [$login],
        );
        if (false === $record || !$this->isActive($record['active'] ?? null)) {
            throw new UserNotFoundException();
        }

        return $this->hydrate($record);
    }

    public function refreshUser(UserInterface $user): AuthenticatedUser
    {
        if (!$user instanceof AuthenticatedUser) {
            throw new UnsupportedUserException();
        }

        $record = $this->connection->fetchAssociative(
            'SELECT id, login_identifier, password_hash, role, active FROM app_user WHERE id = ?',
            [$user->id()],
        );
        if (false === $record || !$this->isActive($record['active'] ?? null)) {
            throw new UserNotFoundException();
        }

        return $this->hydrate($record);
    }

    public function supportsClass(string $class): bool
    {
        return AuthenticatedUser::class === $class || is_subclass_of($class, AuthenticatedUser::class);
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): AuthenticatedUser
    {
        if (!\is_string($record['id'] ?? null)
            || !\is_string($record['login_identifier'] ?? null)
            || !\is_string($record['password_hash'] ?? null)
            || !\is_string($record['role'] ?? null)
        ) {
            throw new UserNotFoundException();
        }

        return new AuthenticatedUser(
            $record['id'],
            $record['login_identifier'],
            $record['password_hash'],
            AdministrativeRole::from($record['role']),
            $this->isActive($record['active'] ?? null),
        );
    }

    private function isActive(mixed $value): bool
    {
        return is_numeric($value) && 1 === (int) $value;
    }
}
