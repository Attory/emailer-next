<?php

declare(strict_types=1);

namespace App\Shared;

/** A file hold survives database restore, code-pointer recovery and host restart. */
final readonly class EditionDeliveryHold
{
    public function __construct(private string $privateDirectory)
    {
    }

    public function active(): bool
    {
        return file_exists($this->privateDirectory.'/edition-delivery-hold.json') || is_link($this->privateDirectory.'/edition-delivery-hold.json');
    }

    public function accepted(): bool
    {
        if (!$this->active() || is_link($this->privateDirectory.'/edition-delivery-hold.json')) {
            return false;
        }
        $receipt = json_decode((string) @file_get_contents($this->privateDirectory.'/edition-delivery-hold.json'), true);

        return \is_array($receipt) && 'accepted' === ($receipt['phase'] ?? null);
    }

    public function needsOfflineVerification(): bool
    {
        if (!$this->active() || is_link($this->privateDirectory.'/edition-delivery-hold.json')) {
            return false;
        }
        $receipt = json_decode((string) @file_get_contents($this->privateDirectory.'/edition-delivery-hold.json'), true);

        return \is_array($receipt) && \in_array($receipt['phase'] ?? null, ['quiescing', 'backing_up', 'mutating', 'validated', 'activated'], true);
    }

    public function assertSendingAllowed(): void
    {
        if ($this->active()) {
            throw new \DomainException('Edition transition delivery hold requires explicit acceptance and delivery resume.');
        }
    }

    public function assertResumeAllowed(): void
    {
        if ($this->active() && !$this->accepted()) {
            throw new \DomainException('Edition transition has not been accepted; recovery delivery hold remains active.');
        }
    }

    public function releaseAfterResume(): void
    {
        $this->assertResumeAllowed();
        if ($this->active() && !@unlink($this->privateDirectory.'/edition-delivery-hold.json')) {
            throw new \RuntimeException('Accepted delivery hold could not be released.');
        }
    }
}
