<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use App\Module\RecipientActions\Application\RecipientTokenService;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class Phase10RecipientAnalyticsTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testPublicViewOpenAndFrozenClickAreCookieFreePrivateAndTransportProtected(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE privacy_policy SET open_tracking = 1, click_tracking = 1 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'phase10-public-owner@example.test', 'phase10-password-'.bin2hex(random_bytes(8)));
        $email = 'phase10-public-recipient@example.test';
        [$campaignId, $recipientId, $contactId] = $this->campaign($connection, $actor, $email);
        $this->phase4Service(RecipientTokenService::class)->issue($campaignId, $recipientId, $contactId, $email);
        $links = $this->phase4Service(RecipientAnalyticsService::class)->issue($campaignId, $recipientId, $contactId, $email, true, true);
        self::assertIsString($links['open']);
        self::assertIsString($links['click']);
        $viewPath = $this->path($links['view']);
        $openPath = $this->path($links['open']);
        $clickPath = $this->path($links['click'].'/1');
        $server = $this->phase4HttpsServer('2001:db8:10::1');
        $client->getCookieJar()->set(new Cookie('EMAILERSESSID', 'forged-admin-session', null, '/', 'localhost', true, true));

        $client->request('GET', $viewPath, server: $server);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Phase 10 public body', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());
        $this->assertPrivateCookieFree($client->getResponse());

        $client->request('GET', $openPath, server: $server);
        self::assertResponseIsSuccessful();
        self::assertSame('image/gif', $client->getResponse()->headers->get('Content-Type'));
        $this->assertPrivateCookieFree($client->getResponse());
        $client->request('HEAD', $openPath, server: $server);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'open'", [$recipientId])));

        $client->request('GET', $clickPath, server: $server);
        self::assertResponseRedirects('https://example.test/frozen-public-target', 302);
        $this->assertPrivateCookieFree($client->getResponse());
        $client->request('HEAD', $clickPath, server: $server);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'click'", [$recipientId])));

        $client->request('GET', $this->path($links['click'].'/999'), server: $server);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($client->getResponse()->headers->has('Location'));
        $tampered = substr($viewPath, 0, -6).('A' === substr($viewPath, -6, 1) ? 'B' : 'A').substr($viewPath, -5);
        $client->request('GET', $tampered, server: $server);
        self::assertResponseStatusCodeSame(404);

        $before = $this->phase4Int($connection->fetchOne('SELECT SUM(event_count) FROM analytics_aggregate WHERE campaign_recipient_id = ?', [$recipientId]));
        $client->request('GET', 'http://localhost'.$openPath, server: ['HTTPS' => 'off', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '2001:db8:10::2']);
        self::assertResponseStatusCodeSame(308);
        self::assertSame($before, $this->phase4Int($connection->fetchOne('SELECT SUM(event_count) FROM analytics_aggregate WHERE campaign_recipient_id = ?', [$recipientId])));
    }

    /** @return array{string, string, string} */
    private function campaign(Connection $connection, \App\Module\Identity\Application\AuthenticatedUser $actor, string $email): array
    {
        $contactId = Identity::uuidV7();
        $listId = Identity::uuidV7();
        $now = Identity::now();
        $connection->insert('contact', ['id' => $contactId, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => 'Synthetic Recipient', 'first_name' => 'Rae', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase10-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 10 public list', 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'manual', 'created_at' => $now]);
        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($actor, 'Phase 10 public template', 'Phase 10 subject', '', '<p><a href="{{ view_in_browser_url }}">Browser view</a> Phase 10 public body. <a href="https://example.test/frozen-public-target">Read</a></p>', 'Phase 10 public body.');
        $versionId = $templates->versions($actor, $templateId)[0]['id'] ?? null;
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 10 public campaign', $versionId, [$listId]);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, $this->phase4Int($campaigns->detail($actor, $campaignId)['operation_version']), true);
        $campaigns->queue($actor, $campaignId);
        $recipientId = $connection->fetchOne('SELECT id FROM campaign_recipient WHERE campaign_id = ?', [$campaignId]);
        self::assertIsString($recipientId);

        return [$campaignId, $recipientId, $contactId];
    }

    private function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);

        return $path;
    }

    private function assertPrivateCookieFree(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }
}
