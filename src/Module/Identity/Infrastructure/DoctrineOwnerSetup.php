<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\Contract\MfaEnrollment;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Identity\Application\Totp;
use App\Module\Secrets\Application\Contract\SecretVault;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class DoctrineOwnerSetup implements OwnerSetup
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private SecretVault $secretVault,
        private Totp $totp,
    ) {
    }

    public function create(string $loginIdentifier, string $password): string
    {
        $login = $this->normalizeLogin($loginIdentifier);
        if (14 > \strlen($password) || 1_024 < \strlen($password)) {
            throw new \InvalidArgumentException('Owner password must be between 14 and 1024 bytes.');
        }

        $existing = $this->connection->fetchAssociative("SELECT id, login_identifier FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1");
        if (false !== $existing) {
            if ($login !== $existing['login_identifier'] || !\is_string($existing['id'])) {
                throw new \RuntimeException('An initial Owner already exists.');
            }

            return $existing['id'];
        }

        $hash = $this->passwordHasher->hashPassword(new OwnerPasswordSubject(), $password);
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new \RuntimeException('Argon2id password hashing is unavailable.');
        }

        $ownerId = self::uuidV7();
        $this->connection->insert('app_user', [
            'id' => $ownerId,
            'login_identifier' => $login,
            'password_hash' => $hash,
            'role' => 'ROLE_OWNER',
            'active' => 1,
            'created_at' => self::now(),
        ]);

        return $ownerId;
    }

    public function startMfa(string $ownerId, string $installationId): MfaEnrollment
    {
        $login = $this->connection->fetchOne("SELECT login_identifier FROM app_user WHERE id = ? AND role = 'ROLE_OWNER' AND active = 1", [$ownerId]);
        if (!\is_string($login)) {
            throw new \RuntimeException('Initial Owner is unavailable.');
        }

        $seed = $this->totp->generateSeed();
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8).'-'.substr(bin2hex(random_bytes(8)), 0, 8));
        }

        $this->connection->transactional(function (Connection $connection) use ($ownerId, $installationId, $seed, $codes): void {
            $oldSecrets = $connection->fetchFirstColumn(
                'SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND encrypted_secret_id IS NOT NULL AND active = 0',
                [$ownerId],
            );
            $connection->executeStatement('DELETE FROM mfa_credential WHERE user_id = ? AND active = 0', [$ownerId]);
            foreach ($oldSecrets as $oldSecret) {
                if (\is_string($oldSecret)) {
                    $connection->delete('encrypted_secret', ['id' => $oldSecret]);
                }
            }

            $secretId = $this->secretVault->store('identity.totp-seed', $seed, $installationId);
            $connection->insert('mfa_credential', [
                'id' => self::uuidV7(),
                'user_id' => $ownerId,
                'credential_type' => 'totp',
                'encrypted_secret_id' => $secretId,
                'value_hash' => null,
                'active' => 0,
                'consumed_at' => null,
                'created_at' => self::now(),
            ]);

            foreach ($codes as $code) {
                $hash = password_hash($code, PASSWORD_ARGON2ID, ['memory_cost' => 8_192, 'time_cost' => 2, 'threads' => 1]);
                $connection->insert('mfa_credential', [
                    'id' => self::uuidV7(),
                    'user_id' => $ownerId,
                    'credential_type' => 'recovery_code',
                    'encrypted_secret_id' => null,
                    'value_hash' => $hash,
                    'active' => 0,
                    'consumed_at' => null,
                    'created_at' => self::now(),
                ]);
            }
        });

        $label = rawurlencode('Emailer Next:'.$login);
        $uri = \sprintf('otpauth://totp/%s?secret=%s&issuer=Emailer%%20Next&algorithm=SHA1&digits=6&period=30', $label, $seed);

        return new MfaEnrollment($seed, $codes, $uri);
    }

    public function confirmMfa(string $ownerId, string $installationId, string $code, bool $recoverySaved): void
    {
        if (!$recoverySaved) {
            throw new \InvalidArgumentException('Recovery-code acknowledgement is required.');
        }

        $secretId = $this->connection->fetchOne(
            "SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND credential_type = 'totp' AND active = 0",
            [$ownerId],
        );
        if (!\is_string($secretId)) {
            throw new \RuntimeException('Pending MFA enrollment is unavailable.');
        }

        $seed = $this->secretVault->reveal($secretId, 'identity.totp-seed', $installationId);
        try {
            if (!$this->totp->verify($seed, $code)) {
                throw new \RuntimeException('MFA confirmation failed.');
            }
        } finally {
            sodium_memzero($seed);
        }

        $this->connection->executeStatement('UPDATE mfa_credential SET active = 1 WHERE user_id = ? AND active = 0', [$ownerId]);
    }

    public function skipMfa(string $ownerId): void
    {
        $count = $this->connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE id = ? AND role = 'ROLE_OWNER' AND active = 1", [$ownerId]);
        if ((!\is_int($count) && !\is_string($count)) || 1 !== (int) $count) {
            throw new \RuntimeException('Initial Owner is unavailable.');
        }
    }

    private function normalizeLogin(string $login): string
    {
        $normalized = \Normalizer::normalize(trim($login), \Normalizer::FORM_C);
        if (!\is_string($normalized)) {
            throw new \InvalidArgumentException('Owner login identifier is invalid.');
        }
        $normalized = mb_strtolower($normalized, 'UTF-8');
        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL) || 254 < \strlen($normalized)) {
            throw new \InvalidArgumentException('Owner login identifier must be a valid email address.');
        }

        return $normalized;
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
