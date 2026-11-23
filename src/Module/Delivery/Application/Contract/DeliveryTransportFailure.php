<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

final class DeliveryTransportFailure extends \RuntimeException
{
    public readonly bool $affectsCircuit;

    public function __construct(public readonly string $classification, string $safeMessage = 'Delivery transport failed.', ?\Throwable $previous = null, ?bool $affectsCircuit = null, public readonly bool $knownUnsent = false, public readonly ?string $reason = null)
    {
        if (!\in_array($classification, ['transient', 'permanent', 'ambiguous'], true)) {
            throw new \InvalidArgumentException('Delivery failure classification is invalid.');
        }
        if (null !== $reason && !\in_array($reason, ['smtp_auth', 'smtp_tls', 'smtp_connect', 'smtp_rejected', 'suppressed', 'ambiguous'], true)) {
            throw new \InvalidArgumentException('Invalid safe transport reason.');
        }
        $this->affectsCircuit = $affectsCircuit ?? 'permanent' !== $classification;
        parent::__construct($safeMessage, 0, $previous);
    }
}
