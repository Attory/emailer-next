<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['empty', 'bounded'], true)) {
    throw new RuntimeException('Isolated mailbox verification requires an explicit mode.');
}
$counts = [];
foreach (['mailpit', 'auth-mailpit'] as $host) {
    $data = json_decode((string) file_get_contents('http://'.$host.':8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
    $total = $data['total'] ?? null;
    // The sinks retain 100 messages. Never allow that ceiling to conceal new
    // messages or make retention pruning invalidate a zero-send comparison.
    if (!is_int($total) || $total < 0 || $total >= 80 || ('empty' === $argv[1] && 0 !== $total)) {
        throw new RuntimeException('The isolated mailbox is not empty or lacks retention headroom. Keep the failed case for diagnosis.');
    }
    $counts[$host] = $total;
}
echo json_encode(['isolated_mailbox_mode' => $argv[1], 'message_counts' => $counts], JSON_THROW_ON_ERROR)."\n";
