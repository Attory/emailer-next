<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Analytics\Application\Contract\RecipientAnalyticsLinks;
use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Delivery\Application\DeliverCampaignRecipientHandler;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\Delivery\Infrastructure\OutboundMimeMessageFactory;
use App\Module\Housekeeping\Application\Housekeeper;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use App\Module\RecipientActions\Application\RecipientTokenInvalid;
use App\Module\RecipientActions\Application\RecipientTokenService;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class Phase7RecipientSafetyTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testTokenLifecycleIsOpaqueVersionedExpiringReplaySafeAndBoundedlyRetained(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase7-owner@example.test', 'phase7-password-'.bin2hex(random_bytes(8)));
        [$campaignId, $recipientId, $contactId] = $this->queuedCampaign($connection, $actor, 'recipient-one@example.test');
        $tokens = $this->phase4Service(RecipientTokenService::class);
        $url = $tokens->issue($campaignId, $recipientId, $contactId, 'recipient-one@example.test');
        $token = $this->tokenFromUrl($url);

        self::assertStringNotContainsString('recipient-one', $url);
        self::assertStringNotContainsString($campaignId, $url);
        $tokens->inspect($token);
        $tampered = substr($token, 0, -1).('A' === substr($token, -1) ? 'B' : 'A');
        $this->assertInvalid($tokens, $tampered);
        $this->assertInvalid($tokens, 'v99.'.implode('.', \array_slice(explode('.', $token), 1)));

        self::assertSame(2, $tokens->rotateKey($actor));
        $tokens->inspect($token);
        [$secondCampaign, $secondRecipient, $secondContact] = $this->queuedCampaign($connection, $actor, 'recipient-two@example.test');
        $secondToken = $this->tokenFromUrl($tokens->issue($secondCampaign, $secondRecipient, $secondContact, 'recipient-two@example.test'));
        self::assertStringStartsWith('v2.', $secondToken);

        $tokens->unsubscribe($token);
        $tokens->unsubscribe($token);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM suppression WHERE email_canonical = 'recipient-one@example.test'")));
        self::assertSame(2, $this->phase4Int($connection->fetchOne('SELECT use_count FROM recipient_token WHERE campaign_recipient_id = ?', [$recipientId])));
        self::assertSame(2, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'recipient.unsubscribe' AND target_id = (SELECT id FROM suppression WHERE email_canonical = 'recipient-one@example.test')")));

        $connection->update('recipient_token', ['expires_at' => (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s.u')], ['campaign_recipient_id' => $secondRecipient]);
        $this->assertInvalid($tokens, $secondToken);
        self::assertSame(1, $tokens->purgeExpired());
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM recipient_token WHERE campaign_recipient_id = ?', [$secondRecipient])));
        $envelope = $connection->fetchOne("SELECT envelope FROM encrypted_secret WHERE record_type = 'recipient.token.key' ORDER BY created_at LIMIT 1");
        self::assertIsString($envelope);
        self::assertStringNotContainsString($token, $envelope);
    }

    public function testDeliveryRendersUnsubscribeAndGatesOneClickHeadersOnSnapshotAttestation(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE sender_identity SET one_click_dkim_confirmed = 1 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'phase7-delivery@example.test', 'phase7-password-'.bin2hex(random_bytes(8)));
        [$campaignId, $recipientId] = $this->queuedCampaign($connection, $actor, 'phase7-recipient@example.test', true);
        $queued = $this->queuedMessage($recipientId);
        $capture = new Phase7CaptureTransport();
        $handler = new DeliverCampaignRecipientHandler(
            $connection,
            $this->phase4Service(DeliveryConfiguration::class),
            $this->phase4Service(SuppressionLookup::class),
            $capture,
            $this->phase4Service(CampaignDeliveryGateway::class),
            $this->phase4Service(RecipientUnsubscribeLinks::class),
            $this->phase4Service(\App\Module\Audit\Application\Contract\AuditSink::class),
            $this->phase4Service(\App\Module\Delivery\Application\Contract\SendingGate::class),
        );
        $handler($queued);
        self::assertNotNull($capture->message);
        $outbound = $capture->message;
        self::assertNotNull($outbound->unsubscribeUrl);
        self::assertStringStartsWith('https://localhost/r/v1.', $outbound->unsubscribeUrl);
        self::assertStringNotContainsString('phase7-recipient', $outbound->unsubscribeUrl);
        self::assertStringContainsString($outbound->unsubscribeUrl, $outbound->html);
        self::assertStringContainsString('Unsubscribe: '.$outbound->unsubscribeUrl, $outbound->text);
        self::assertTrue($outbound->oneClickUnsubscribe);

        $mime = $this->phase4Service(OutboundMimeMessageFactory::class)->create($outbound);
        $headers = $mime->getHeaders()->toString();
        self::assertStringContainsString('List-Unsubscribe: <'.$outbound->unsubscribeUrl.'>', $headers);
        self::assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers);
        self::assertSame('accepted', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$recipientId]));
        self::assertSame('completed', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
    }

    public function testUnsubscribeBeforeClaimPreventsAQueuedDelivery(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase7-race@example.test', 'phase7-password-'.bin2hex(random_bytes(8)));
        [$campaignId, $recipientId, $contactId] = $this->queuedCampaign($connection, $actor, 'phase7-race-recipient@example.test');
        $tokens = $this->phase4Service(RecipientTokenService::class);
        $tokens->unsubscribe($this->tokenFromUrl($tokens->issue($campaignId, $recipientId, $contactId, 'phase7-race-recipient@example.test')));
        $capture = new Phase7CaptureTransport();
        $handler = new DeliverCampaignRecipientHandler($connection, $this->phase4Service(DeliveryConfiguration::class), $this->phase4Service(SuppressionLookup::class), $capture, $this->phase4Service(CampaignDeliveryGateway::class), $tokens, $this->phase4Service(\App\Module\Audit\Application\Contract\AuditSink::class), $this->phase4Service(\App\Module\Delivery\Application\Contract\SendingGate::class));
        $handler($this->queuedMessage($recipientId));
        self::assertNull($capture->message);
        self::assertSame('suppressed', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$recipientId]));
    }

    public function testPhase10PurposeScopedTrackingUsesFrozenLinksAndMinimizedReplayAggregates(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase10-owner@example.test', 'phase10-password-'.bin2hex(random_bytes(8)));

        [$disabledCampaign, $disabledRecipient] = $this->queuedCampaign($connection, $actor, 'phase10-disabled@example.test', false, true);
        $disabledCapture = new Phase7CaptureTransport();
        $this->deliveryHandler($connection, $disabledCapture)($this->queuedMessage($disabledRecipient));
        self::assertNotNull($disabledCapture->message);
        self::assertStringNotContainsString('/open.gif', $disabledCapture->message->html);
        self::assertStringNotContainsString('/click/', $disabledCapture->message->html);
        self::assertStringContainsString('/view', $disabledCapture->message->html);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM recipient_token WHERE campaign_id = ? AND purpose IN ('open', 'click')", [$disabledCampaign])));

        $connection->executeStatement("UPDATE privacy_policy SET open_tracking = 1, click_tracking = 1, tracking_retention_days = 30 WHERE singleton_key = '1'");
        [$campaignId, $recipientId] = $this->queuedCampaign($connection, $actor, 'phase10-enabled@example.test', false, true);
        $connection->executeStatement("UPDATE privacy_policy SET open_tracking = 0, click_tracking = 0 WHERE singleton_key = '1'");
        $detail = $this->phase4Service(CampaignManager::class)->detail($actor, $campaignId);
        self::assertIsArray($detail['review'] ?? null);
        self::assertSame('Open: on; click: on', $detail['review']['tracking'] ?? null);
        $capture = new Phase7CaptureTransport();
        $this->deliveryHandler($connection, $capture)($this->queuedMessage($recipientId));
        self::assertNotNull($capture->message);
        self::assertMatchesRegularExpression('#https://localhost/r/(v[^"/]+)/open\.gif#', $capture->message->html);
        self::assertMatchesRegularExpression('#https://localhost/r/(v[^"/]+)/click/1#', $capture->message->html);
        self::assertStringNotContainsString('https://example.test/frozen-target', $capture->message->html);
        self::assertStringNotContainsString('phase10-enabled@example.test', $capture->message->html);

        $openToken = $this->analyticsToken('#/r/(v[^"/]+)/open\.gif#', $capture->message->html);
        $clickToken = $this->analyticsToken('#/r/(v[^"/]+)/click/1#', $capture->message->html);
        $viewToken = $this->analyticsToken('#/r/(v[^"/]+)/view#', $capture->message->html);
        $analytics = $this->phase4Service(RecipientAnalyticsService::class);
        self::assertSame('view', $analytics->verify($viewToken, 'view')['purpose']);
        $this->assertAnalyticsInvalid($analytics, $viewToken, 'open');
        $tampered = substr($clickToken, 0, -1).('A' === substr($clickToken, -1) ? 'B' : 'A');
        $this->assertAnalyticsInvalid($analytics, $tampered, 'click');
        self::assertSame('https://example.test/frozen-target', $analytics->clickTarget($clickToken, 1, true));
        self::assertSame('https://example.test/frozen-target', $analytics->clickTarget($clickToken, 1, true));
        $analytics->recordOpen($openToken);
        $analytics->recordOpen($openToken);
        self::assertSame(2, $this->phase4Int($connection->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'click'", [$recipientId])));
        self::assertSame(2, $this->phase4Int($connection->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'open'", [$recipientId])));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM recipient_token WHERE campaign_recipient_id = ? AND purpose IN ('view', 'open', 'click') AND email_canonical IS NOT NULL", [$recipientId])));
        $storedOpen = $connection->fetchAssociative("SELECT * FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'open'", [$recipientId]);
        self::assertIsArray($storedOpen);
        self::assertSame(['campaign_recipient_id', 'event_type', 'link_index', 'event_count', 'first_seen_at', 'last_seen_at'], array_keys($storedOpen));
        self::assertStringContainsString('Phase 7 body', $analytics->browserContent($viewToken));
        $connection->executeStatement('UPDATE analytics_aggregate SET last_seen_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 31 DAY) WHERE campaign_recipient_id = ?', [$recipientId]);
        $housekeeper = $this->phase4Service(Housekeeper::class);
        self::assertSame(2, $housekeeper->run(true)['analytics']);
        self::assertSame(2, $housekeeper->run(false)['analytics']);
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM analytics_aggregate WHERE campaign_recipient_id = ?', [$recipientId])));
        $connection->executeStatement("UPDATE recipient_token SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE campaign_id = ? AND purpose IN ('view', 'open', 'click')", [$campaignId]);
        $this->assertAnalyticsInvalid($analytics, $openToken, 'open');
    }

    /** @return array{string, string, string} */
    private function queuedCampaign(Connection $connection, AuthenticatedUser $actor, string $email, bool $withMergeField = false, bool $withAnalyticsContent = false): array
    {
        $contactId = Identity::uuidV7();
        $listId = Identity::uuidV7();
        $now = Identity::now();
        $connection->insert('contact', ['id' => $contactId, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => 'Synthetic Recipient', 'first_name' => 'Rae', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase7-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 7 list '.substr($listId, -6), 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'manual', 'created_at' => $now]);
        $templates = $this->phase4Service(TemplateManager::class);
        $html = $withMergeField ? '<p>Phase 7 body. <a href="{{ unsubscribe_url }}">Leave this list</a></p>' : '<p>Phase 7 body.</p>';
        if ($withAnalyticsContent) {
            $html = '<p><a href="{{ view_in_browser_url }}">Browser view</a> Phase 7 body. <a href="https://example.test/frozen-target">Read more</a></p>';
        }
        $templateId = $templates->create($actor, 'Phase 7 template '.substr($listId, -6), 'Phase 7 subject', '', $html, 'Phase 7 body.');
        $versions = $templates->versions($actor, $templateId);
        $versionId = $versions[0]['id'] ?? null;
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 7 campaign '.substr($listId, -6), $versionId, [$listId]);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, $this->phase4Int($campaigns->detail($actor, $campaignId)['operation_version']), true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $recipientId = $connection->fetchOne('SELECT id FROM campaign_recipient WHERE campaign_id = ?', [$campaignId]);
        self::assertIsString($recipientId);

        return [$campaignId, $recipientId, $contactId];
    }

    private function queuedMessage(string $recipientId): DeliverCampaignRecipient
    {
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof DeliverCampaignRecipient && $message->recipientId === $recipientId) {
                return $message;
            }
        }
        self::fail('Queued recipient message was not found.');
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function tokenFromUrl(string $url): string
    {
        $matches = [];
        if (1 !== preg_match('#/r/(v[1-9][0-9]{0,4}\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43})/unsubscribe$#D', $url, $matches)) {
            self::fail('Recipient token URL is invalid.');
        }

        return $matches[1];
    }

    private function assertInvalid(RecipientTokenService $tokens, string $token): void
    {
        try {
            $tokens->inspect($token);
            self::fail('Invalid recipient token was accepted.');
        } catch (RecipientTokenInvalid) {
            return;
        }
    }

    private function deliveryHandler(Connection $connection, Phase7CaptureTransport $capture): DeliverCampaignRecipientHandler
    {
        return new DeliverCampaignRecipientHandler($connection, $this->phase4Service(DeliveryConfiguration::class), $this->phase4Service(SuppressionLookup::class), $capture, $this->phase4Service(CampaignDeliveryGateway::class), $this->phase4Service(RecipientUnsubscribeLinks::class), $this->phase4Service(\App\Module\Audit\Application\Contract\AuditSink::class), $this->phase4Service(\App\Module\Delivery\Application\Contract\SendingGate::class), $this->phase4Service(RecipientAnalyticsLinks::class));
    }

    private function assertAnalyticsInvalid(RecipientAnalyticsService $analytics, string $token, string $purpose): void
    {
        try {
            $analytics->verify($token, $purpose);
            self::fail('Invalid analytics token was accepted.');
        } catch (RecipientTokenInvalid) {
        }
    }

    private function analyticsToken(string $pattern, string $html): string
    {
        $matches = [];
        if (1 !== preg_match($pattern, $html, $matches)) {
            self::fail('Analytics token URL is unavailable.');
        }

        return $matches[1];
    }
}

final class Phase7CaptureTransport implements DeliveryTransport
{
    public ?OutboundMessage $message = null;

    public function send(OutboundMessage $message): string
    {
        $this->message = $message;

        return $message->messageId;
    }
}
