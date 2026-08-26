<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Analytics\Application\RsvpManager;
use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Delivery\Application\DeliverCampaignRecipientHandler;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\Housekeeping\Application\Housekeeper;
use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use App\Module\RecipientActions\Application\RecipientTokenService;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class Phase11RsvpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testFrozenPurposeScopedRsvpIsBoundedCookieFreeAndRepeatSafe(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE privacy_policy SET rsvp_enabled = 1, rsvp_repeat_policy = 'replace', rsvp_retention_days = 30 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'phase11-owner@example.test', 'phase11-password-'.bin2hex(random_bytes(8)));
        [$campaignId, $recipientId, $contactId, $email] = $this->campaign($connection, $actor, true);
        $tokens = $this->phase4Service(RecipientTokenService::class);
        $tokens->issue($campaignId, $recipientId, $contactId, $email);
        $service = $this->phase4Service(RecipientAnalyticsService::class);
        $url = $service->issueRsvp($campaignId, $recipientId, $contactId, $email);
        ($this->phase4Service(DeliverCampaignRecipientHandler::class))($this->delivery($recipientId));
        self::assertSame('accepted', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$recipientId]));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = 'rsvp'", [$recipientId])));
        self::assertStringNotContainsString($email, $url);
        self::assertStringNotContainsString($campaignId, $url);
        $path = $this->path($url);
        $server = $this->phase4HttpsServer('2001:db8:11::1');

        $client->request('GET', $path, server: $server);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Phase 11 campaign', (string) $client->getResponse()->getContent());
        $this->assertPrivate($client->getResponse());

        $client->request('POST', $path, ['response' => 'yes', 'guests' => '2', 'note' => '=SUM(1,1)'], server: $server);
        self::assertResponseIsSuccessful();
        self::assertSame('yes', $connection->fetchOne('SELECT response_choice FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId]));
        self::assertSame(2, $this->phase4Int($connection->fetchOne('SELECT guest_count FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId])));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT submission_count FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId])));
        $this->assertPrivate($client->getResponse());

        $client->request('POST', $path, ['response' => 'maybe', 'guests' => '0', 'note' => 'Changed'], server: $server);
        self::assertResponseIsSuccessful();
        self::assertSame('maybe', $connection->fetchOne('SELECT response_choice FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId]));
        self::assertSame(2, $this->phase4Int($connection->fetchOne('SELECT submission_count FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId])));

        $client->request('POST', $path, ['response' => 'yes', 'guests' => '21', 'note' => ''], server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', $path, ['response' => 'yes', 'guests' => '0', 'note' => '', 'extra' => 'x'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $tampered = substr($path, 0, -6).('A' === substr($path, -6, 1) ? 'B' : 'A').substr($path, -5);
        $client->request('GET', $tampered, server: $server);
        self::assertResponseStatusCodeSame(404);

        $tracking = $service->issue($campaignId, $recipientId, $contactId, $email, false, false);
        $client->request('GET', preg_replace('#/view$#', '/rsvp', $this->path($tracking['view'])) ?? '', server: $server);
        self::assertResponseStatusCodeSame(404);

        $connection->executeStatement("UPDATE privacy_policy SET rsvp_enabled = 0, rsvp_repeat_policy = 'first' WHERE singleton_key = '1'");
        $client->request('GET', $path, server: $server);
        self::assertResponseIsSuccessful();
        self::assertSame('replace', $connection->fetchOne('SELECT rsvp_repeat_policy FROM campaign_content_snapshot WHERE campaign_id = ?', [$campaignId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = ? AND email_canonical IS NOT NULL', [$recipientId, 'rsvp'])));
    }

    public function testFirstResponsePolicyAggregateExportNeutralizationAndRetention(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE privacy_policy SET rsvp_enabled = 1, rsvp_repeat_policy = 'first', rsvp_retention_days = 1 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'phase11-admin@example.test', 'phase11-password-'.bin2hex(random_bytes(8)));
        [$campaignId, $recipientId, $contactId, $email] = $this->campaign($connection, $actor, true);
        $this->phase4Service(RecipientTokenService::class)->issue($campaignId, $recipientId, $contactId, $email);
        $service = $this->phase4Service(RecipientAnalyticsService::class);
        $token = $this->token($service->issueRsvp($campaignId, $recipientId, $contactId, $email));
        $service->submitRsvp($token, 'yes', 1, '=HYPERLINK("https://invalid")');
        $service->submitRsvp($token, 'no', 0, 'replacement');
        self::assertSame('yes', $connection->fetchOne('SELECT response_choice FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId]));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT submission_count FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId])));

        $manager = $this->phase4Service(RsvpManager::class);
        $view = $manager->view($actor);
        self::assertSame(1, $this->phase4Int($view['campaigns'][0]['responded'] ?? null));
        $stream = tmpfile();
        self::assertIsResource($stream);
        self::assertSame(1, $manager->export($actor, $campaignId, $stream));
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString($email, $csv);

        $connection->executeStatement('UPDATE rsvp_response SET last_submitted_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY) WHERE campaign_recipient_id = ?', [$recipientId]);
        $housekeeper = $this->phase4Service(Housekeeper::class);
        self::assertSame(1, $housekeeper->run(true)['rsvp']);
        self::assertSame(1, $housekeeper->run(false)['rsvp']);
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM rsvp_response WHERE campaign_recipient_id = ?', [$recipientId])));
    }

    /** @return array{string, string, string, string} */
    private function campaign(Connection $connection, \App\Module\Identity\Application\AuthenticatedUser $actor, bool $enabled): array
    {
        $email = 'phase11-recipient-'.bin2hex(random_bytes(4)).'@example.test';
        $contactId = Identity::uuidV7();
        $listId = Identity::uuidV7();
        $now = Identity::now();
        $connection->insert('contact', ['id' => $contactId, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => 'Synthetic Recipient', 'first_name' => 'Rae', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase11-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 11 list '.substr($listId, -6), 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'manual', 'created_at' => $now]);
        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($actor, 'Phase 11 template '.substr($listId, -6), 'Phase 11 subject', '', '<p>Phase 11 body. <a href="{{ rsvp_url }}">RSVP</a></p>', 'Phase 11 RSVP: {{ rsvp_url }}');
        $versionId = $templates->versions($actor, $templateId)[0]['id'] ?? null;
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 11 campaign '.substr($listId, -6), $versionId, [$listId]);
        $campaigns->configureRsvp($actor, $campaignId, $enabled);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $campaigns->queue($actor, $campaignId);
        $recipientId = $connection->fetchOne('SELECT id FROM campaign_recipient WHERE campaign_id = ?', [$campaignId]);
        self::assertIsString($recipientId);

        return [$campaignId, $recipientId, $contactId, $email];
    }

    private function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);

        return $path;
    }

    private function token(string $url): string
    {
        $parts = explode('/', trim($this->path($url), '/'));
        self::assertCount(3, $parts);

        return $parts[1];
    }

    private function delivery(string $recipientId): DeliverCampaignRecipient
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof DeliverCampaignRecipient && $message->recipientId === $recipientId) {
                return $message;
            }
        }
        self::fail('Queued RSVP delivery was unavailable.');
    }

    private function assertPrivate(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }
}
