<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Setup\Application\Contract\ChallengeStore;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\ProofFile;
use Symfony\Component\Lock\LockFactory;

final readonly class ProofVerifier
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private ChallengeStore $store,
        private ProofFile $proofFile,
        private Clock $clock,
        private LockFactory $lockFactory,
    ) {
    }

    public function verify(string $expectedIdentifier, string $requestIp): ProofVerificationResult
    {
        $lock = $this->lockFactory->createLock('emailer-setup-proof', 10.0);

        if (!$lock->acquire(true)) {
            return ProofVerificationResult::Invalid;
        }

        try {
            $state = $this->store->load();

            if (null === $state || !hash_equals($state->identifier, $expectedIdentifier)) {
                return ProofVerificationResult::Invalid;
            }

            if ($state->expiresAt <= $this->clock->now()) {
                $this->store->delete();

                return ProofVerificationResult::Expired;
            }

            if ($state->failedAttempts >= self::MAX_ATTEMPTS) {
                $this->store->delete();

                return ProofVerificationResult::AttemptsExceeded;
            }

            $ipMatches = hash_equals(
                $state->requestIpHash,
                ChallengeIssuer::requestIpHash($state->identifier, $requestIp),
            );
            $code = $this->proofFile->readNormalizedCode();
            $matches = null !== $code && hash_equals($state->codeHash, hash('sha256', $code));

            if (!$ipMatches || !$matches) {
                $failedState = $state->withFailedAttempt();

                if ($failedState->failedAttempts >= self::MAX_ATTEMPTS) {
                    $this->store->delete();
                } else {
                    $this->store->save($failedState);
                }

                return ProofVerificationResult::Invalid;
            }

            $this->proofFile->delete();
            $this->store->delete();

            return ProofVerificationResult::Success;
        } finally {
            $lock->release();
        }
    }
}
