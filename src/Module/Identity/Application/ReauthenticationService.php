<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class ReauthenticationService
{
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        private MfaVerifier $mfa,
        private AuditSink $audit,
    ) {
    }

    public function verify(AuthenticatedUser $user, string $password, string $mfaCode, string $purpose): bool
    {
        $valid = password_verify($password, $user->getPassword()) && $this->mfa->verify($user->id(), $mfaCode);
        $this->audit->append(new AuditRecord(
            $user->id(),
            'authentication.reauthentication',
            $user->id(),
            $valid ? 'success' : 'failure',
            ['purpose' => $this->normalizePurpose($purpose)],
        ));

        return $valid;
    }

    public function mark(SessionInterface $session, string $purpose): void
    {
        $session->set('identity.reauthenticated_at', time());
        $session->set('identity.reauthenticated_purpose', $this->normalizePurpose($purpose));
    }

    public function assertRecent(SessionInterface $session, string $purpose): void
    {
        $timestamp = $session->get('identity.reauthenticated_at');
        $storedPurpose = $session->get('identity.reauthenticated_purpose');
        if (!\is_int($timestamp)
            || !\is_string($storedPurpose)
            || !hash_equals($this->normalizePurpose($purpose), $storedPurpose)
            || $timestamp < time() - self::LIFETIME_SECONDS
            || $timestamp > time() + 5
        ) {
            throw new AccessDeniedException('Recent re-authentication is required.');
        }
    }

    private function normalizePurpose(string $purpose): string
    {
        return 1 === preg_match('/^[a-z][a-z0-9_.-]{2,63}$/', $purpose) ? $purpose : 'sensitive_operation';
    }
}
