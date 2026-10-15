<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Queue compatibility verification is isolated-only.');
}
require '/rehearsal/source-releases/1.0.6/vendor/autoload.php';
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Queue verification refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
$serializer = new Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
$verified = [];
foreach ($c->fetchAllAssociative('SELECT body,headers FROM messenger_messages WHERE queue_name = ? ORDER BY id', ['async']) as $row) {
    $envelope = $serializer->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR)]);
    $message = $envelope->getMessage();
    if (!$message instanceof App\Module\Delivery\Application\Message\DeliverCampaignRecipient || 1 !== $message->operationVersion) {
        throw new RuntimeException('Retained Light message cannot be decoded by the actual target codec.');
    }
    $state = $c->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$message->recipientId]);
    if (!in_array($state, ['accepted', 'retry'], true)) {
        throw new RuntimeException('Retained Light message lost its historical delivery identity.');
    }
    $verified[] = $state;
}
sort($verified);
if (['accepted', 'retry'] !== $verified) {
    throw new RuntimeException('Both retained accepted and retry messages must be verified.');
}
echo json_encode(['actual_source_serialized_envelopes_decoded_by_target' => 2, 'original_accepted_and_retry_identities_retained' => true, 'messages_dispatched' => 0], JSON_THROW_ON_ERROR)."\n";
