<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Application\Contract;

interface CampaignUpdateStatus
{
    /** @param list<string> $ids
     * @return list<string>
     */
    public function stillSending(array $ids): array;
}
