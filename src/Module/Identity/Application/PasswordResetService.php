<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class PasswordResetService
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private SessionRegistry $sessions,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private AuditSink $audit,
    ) {
    }

    public function request(string $loginIdentifier, ?string $sourceHash): void
    {
        try {
            $login = IdentityUtil::normalizeLogin($loginIdentifier);
        } catch (\InvalidArgumentException) {
            return;
        }
        $user = $this->connection->fetchAssociative(
            'SELECT id, login_identifier FROM app_user WHERE login_identifier = ? AND active = 1',
            [$login],
        );
        if (false === $user || !\is_string($user['id'] ?? null) || !\is_string($user['login_identifier'] ?? null)) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $id = IdentityUtil::uuidV7();
        $this->connection->insert('password_reset_token', [
            'id' => $id,
            'user_id' => $user['id'],
            'token_hash' => $tokenHash,
            'created_at' => IdentityUtil::now(),
            'expires_at' => (new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'consumed_at' => null,
        ]);

        try {
            $sender = $this->connection->fetchAssociative("SELECT sender_address, sender_name FROM sender_identity WHERE singleton_key = '1'");
            if (false === $sender || !\is_string($sender['sender_address'] ?? null) || !\is_string($sender['sender_name'] ?? null)) {
                throw new \RuntimeException('Sender identity is unavailable.');
            }
            $url = $this->urls->generate('identity_password_reset', ['selector' => $id], UrlGeneratorInterface::ABSOLUTE_URL).'#token='.rawurlencode($token);
            $this->mailer->send((new Email())
                ->from(new Address($sender['sender_address'], $sender['sender_name']))
                ->to($user['login_identifier'])
                ->subject('[Emailer Next] Password reset')
                ->text("A password reset was requested for your Emailer Next account.\n\nUse this single-use link within 30 minutes:\n".$url."\n\nIf you did not request this, ignore this message.\n"));
            $this->audit->append(new AuditRecord(
                $user['id'],
                'identity.password_reset.requested',
                $user['id'],
                'success',
                ['delivery' => 'mailpit'],
                $sourceHash,
            ));
        } catch (\Throwable $exception) {
            $this->connection->delete('password_reset_token', ['id' => $id]);
            throw $exception;
        } finally {
            sodium_memzero($token);
        }
    }

    public function complete(string $selector, string $token, string $newPassword, ?string $sourceHash): string
    {
        IdentityUtil::assertPassword($newPassword);
        if (32 > \strlen($token) || 128 < \strlen($token)) {
            throw new \RuntimeException('Password reset was not accepted.');
        }
        $hash = $this->passwordHasher->hashPassword(new OwnerPasswordSubject(), $newPassword);
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new \RuntimeException('Argon2id password hashing is unavailable.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($selector, $token, $hash, $sourceHash): string {
            $record = $connection->fetchAssociative(
                'SELECT id, user_id, token_hash, expires_at, consumed_at FROM password_reset_token WHERE id = ? FOR UPDATE',
                [$selector],
            );
            if (false === $record
                || !\is_string($record['id'] ?? null)
                || !\is_string($record['user_id'] ?? null)
                || !\is_string($record['token_hash'] ?? null)
                || !\is_string($record['expires_at'] ?? null)
                || null !== ($record['consumed_at'] ?? null)
                || !hash_equals($record['token_hash'], hash('sha256', $token))
                || new \DateTimeImmutable($record['expires_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                throw new \RuntimeException('Password reset was not accepted.');
            }
            $active = $connection->fetchOne('SELECT active FROM app_user WHERE id = ? FOR UPDATE', [$record['user_id']]);
            if (!is_numeric($active) || 1 !== (int) $active) {
                throw new \RuntimeException('Password reset was not accepted.');
            }

            $now = IdentityUtil::now();
            $connection->update('app_user', [
                'password_hash' => $hash,
                'password_changed_at' => $now,
                'updated_at' => $now,
            ], ['id' => $record['user_id']]);
            $connection->update('password_reset_token', ['consumed_at' => $now], ['id' => $record['id']]);
            $connection->executeStatement(
                'UPDATE password_reset_token SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL',
                [$now, $record['user_id']],
            );
            $this->sessions->revokeAll($record['user_id']);
            $this->audit->append(new AuditRecord(
                $record['user_id'],
                'identity.password_reset.completed',
                $record['user_id'],
                'success',
                ['authority_invalidated' => true],
                $sourceHash,
            ));

            return $record['user_id'];
        });
    }
}
