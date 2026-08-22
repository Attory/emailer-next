<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface DeliveryConfiguration
{
    /** @return array{installation_id: string, host: string, port: int, encryption: string, username: ?string, credential_id: ?string, verify_peer: bool, sender_address: string, sender_name: string, reply_to: ?string, one_click_dkim_confirmed: bool, unsubscribe_footer: string, messages_per_minute: int, concurrency: int, retry_limit: int, max_campaign_size: int} */
    public function current(): array;
}
