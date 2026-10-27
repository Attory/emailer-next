<?php

declare(strict_types=1);

namespace App\Module\Health\Application;

use App\Module\Campaigns\Application\Contract\CampaignUpdateStatus;
use App\Shared\UpdateDeliveryState;

final readonly class UpdateStatus
{
    public function __construct(private UpdateDeliveryState $state, private CampaignUpdateStatus $campaigns)
    {
    }

    /** @return array{blocked: bool, phase: string, message: string, paused_campaign_ids: list<string>} */
    public function current(): array
    {
        $status = $this->state->status();
        if ([] === $status['paused_campaign_ids']) {
            return $status;
        }
        try {
            $status['paused_campaign_ids'] = $this->campaigns->stillSending($status['paused_campaign_ids']);
        } catch (\Throwable) {
            return ['blocked' => true, 'phase' => 'unknown', 'message' => 'Current delivery state could not be checked. Sending remains blocked; open Doctor and retry the update status check.', 'paused_campaign_ids' => []];
        }
        if ([] === $status['paused_campaign_ids'] && \in_array($status['phase'], ['acquiring', 'in_progress', 'resuming'], true)) {
            $status['message'] = 'Update in progress; sending temporarily unavailable.';
        }

        return $status;
    }
}
