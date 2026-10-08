<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\SensitiveActionAuthorizer;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class ReauthenticationService implements SensitiveActionAuthorizer
{
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        private MfaVerifier $mfa,
        private AuditSink $audit,
        private LoginThrottle $throttle,
        private SessionRegistry $sessions,
    ) {
    }

    public function verify(
        AuthenticatedUser $user,
        string $password,
        string $mfaCode,
        string $purpose,
        string $source,
        string $sessionId,
    ): bool {
        try {
            $reservations = $this->throttle->reserveReauthentication($user->id(), $source, $sessionId);
        } catch (LoginThrottled) {
            $this->audit->append(new AuditRecord(
                $user->id(),
                'authentication.reauthentication_lockout',
                $user->id(),
                'failure',
                ['purpose' => $this->normalizePurpose($purpose)],
                $this->throttle->sourceHash($source),
            ));

            return false;
        }
        $passwordValid = password_verify($password, $user->getPassword());
        $mfaValid = $this->mfa->verify($user->id(), $mfaCode, $passwordValid);
        $valid = $passwordValid && $mfaValid;
        if ($valid) {
            $this->throttle->reauthenticationSucceeded($reservations);
        } else {
            $this->throttle->reauthenticationFailed($reservations);
        }
        $this->audit->append(new AuditRecord(
            $user->id(),
            'authentication.reauthentication',
            $user->id(),
            $valid ? 'success' : 'failure',
            ['purpose' => $this->normalizePurpose($purpose)],
            $this->throttle->sourceHash($source),
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

    public function expiresAt(SessionInterface $session, string $purpose): int
    {
        $timestamp = $session->get('identity.reauthenticated_at');

        return $this->isRecent($session, $purpose) && \is_int($timestamp) ? $timestamp + self::LIFETIME_SECONDS : 0;
    }

    public function isRecent(SessionInterface $session, string $purpose): bool
    {
        try {
            $this->assertRecent($session, $purpose);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    public function confirm(SessionInterface $session, AdministrativeActor $actor, string $password, string $code, string $purpose, ?string $source, ?string $userAgent): bool
    {
        if (!$actor instanceof AuthenticatedUser || !$this->verify($actor, $password, $code, $purpose, $source ?? 'unknown', $session->getId())) {
            return false;
        }
        $this->mark($session, $purpose);
        $session->migrate(true);
        $this->sessions->bind($session->getId(), $actor->id(), $source, $userAgent);

        return true;
    }

    private function normalizePurpose(string $purpose): string
    {
        return 1 === preg_match('/^[a-z][a-z0-9_.-]{2,63}$/', $purpose) ? $purpose : 'sensitive_operation';
    }
}
