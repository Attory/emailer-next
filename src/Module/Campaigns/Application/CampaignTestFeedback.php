<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Application;

final class CampaignTestFeedback
{
    /** @param array<string, mixed> $row
     * @return array{id: string, state: string, destination: string, message: string, terminal: bool}
     */
    public static function describe(array $row, ?string $delay = null): array
    {
        foreach (['id', 'state', 'recipient_address'] as $key) {
            if (!\is_string($row[$key] ?? null)) {
                throw new \UnexpectedValueException('Test status is unavailable.');
            }
        }
        $state = $row['state'];
        $message = match ($state) {
            'accepted' => 'Test email sent to '.$row['recipient_address'].'.',
            'processing' => 'Sending… The worker is processing your test for '.$row['recipient_address'].'. Waiting for the SMTP result; do not submit it again.',
            'pending' => 'Waiting to send to '.$row['recipient_address'].'. '.($delay ?? 'The delivery worker has not started this test yet. This status updates automatically.'),
            'failed' => match ($row['failure_class'] ?? null) {
                'superseded' => 'This draft changed before the test was sent. Review the current draft, then request a new test.',
                'suppressed' => 'Your test address is unsubscribed or suppressed. Review Unsubscribes with an Owner; sending protections have not been bypassed.',
                'smtp_auth' => 'SMTP authentication failed. Ask an Owner to check the saved mail credentials and connection in Mail settings.',
                'smtp_tls' => 'The secure SMTP connection failed. Ask an Owner to check the SMTP hostname, encryption and certificate in Mail settings.',
                'smtp_connect' => 'The SMTP server could not be reached. Ask an Owner to check its hostname, port and connectivity before requesting another test.',
                'smtp_rejected' => 'The SMTP server rejected the test. Ask an Owner to check sender and recipient restrictions and the mail-server logs.',
                'prepare' => 'The test could not be prepared. Check the published template and Mail settings, then review Doctor.',
                'queue' => 'The test could not be queued. Ask the server operator to check the queue and worker; this test will not be retried automatically.',
                'ambiguous' => 'SMTP acceptance could not be confirmed. Check the destination mailbox and ask the server operator to trace the test before considering another send. It will not be retried automatically.',
                default => 'The test failed. Ask an Owner to check Mail settings and Doctor and have the server operator inspect the failure. It will not be retried automatically.',
            },
            default => throw new \UnexpectedValueException('Test status is unavailable.'),
        };

        return ['id' => $row['id'], 'state' => $state, 'destination' => $row['recipient_address'], 'message' => $message, 'terminal' => \in_array($state, ['accepted', 'failed'], true)];
    }
}
