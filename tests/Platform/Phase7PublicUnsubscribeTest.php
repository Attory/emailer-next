<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\RecipientActions\Application\RecipientTokenService;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class Phase7PublicUnsubscribeTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testManualAndOneClickUnsubscribeAreCookieFreeGenericAndIdempotent(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase7-public-owner@example.test', 'phase7-password-'.bin2hex(random_bytes(8)));
        [$path, $email] = $this->unsubscribePath($connection, $actor, 'phase7-public-recipient@example.test');
        $sessionBaseline = $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM user_session'));
        $client->getCookieJar()->set(new Cookie('EMAILERSESSID', 'forged-admin-session', null, '/', 'localhost', true, true));
        $server = $this->phase4HttpsServer('2001:db8:7::10');

        $client->request('GET', $path, server: $server);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($email, (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($sessionBaseline, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM user_session')));

        $client->request('POST', $path, parameters: ['confirm' => 'unsubscribe'], server: $server);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->isRedirect());
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression WHERE email_canonical = ?', [$email])));

        $client->getCookieJar()->clear();
        $client->request('POST', $path, parameters: ['List-Unsubscribe' => 'One-Click'], server: $server);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->isRedirect());
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression WHERE email_canonical = ?', [$email])));
        self::assertSame(2, $this->phase4Int($connection->fetchOne('SELECT use_count FROM recipient_token')));

        $client->request('POST', $path, parameters: ['unexpected' => 'value'], server: $server);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($client->getResponse()->isRedirect());

        $client->request('POST', $path, parameters: ['List-Unsubscribe' => 'One-Click'], files: ['attachment' => []], server: array_merge($server, ['CONTENT_TYPE' => 'multipart/form-data; boundary=phase7']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testTamperedExpiredAndInsecureRequestsChangeNoStateAndRateLimitIsDedicated(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase7-negative-owner@example.test', 'phase7-password-'.bin2hex(random_bytes(8)));
        [$path, $email] = $this->unsubscribePath($connection, $actor, 'phase7-negative-recipient@example.test');
        $tampered = substr($path, 0, -14).('A' === substr($path, -14, 1) ? 'B' : 'A').substr($path, -13);
        $server = $this->phase4HttpsServer('2001:db8:7::20');

        $client->request('POST', $tampered, parameters: ['List-Unsubscribe' => 'One-Click'], server: $server);
        self::assertResponseStatusCodeSame(404);
        $generic = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($email, $generic);
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression')));

        $client->request('GET', '/r/not-a-token/unsubscribe', server: $server);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $connection->executeStatement('UPDATE recipient_token SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        $client->request('POST', $path, parameters: ['List-Unsubscribe' => 'One-Click'], server: $server);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($generic, (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM suppression')));

        [$insecurePath] = $this->unsubscribePath($connection, $actor, 'phase7-insecure-recipient@example.test');
        $client->request('POST', 'http://localhost'.$insecurePath, parameters: ['List-Unsubscribe' => 'One-Click'], server: ['HTTPS' => 'off', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '2001:db8:7::21']);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($client->getResponse()->isRedirect());
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM suppression WHERE email_canonical = 'phase7-insecure-recipient@example.test'")));

        [$limitedPath] = $this->unsubscribePath($connection, $actor, 'phase7-limited-recipient@example.test');
        $limitedServer = $this->phase4HttpsServer('2001:db8:7:'.implode(':', str_split(bin2hex(random_bytes(6)), 4)));
        for ($request = 0; $request < 20; ++$request) {
            $client->request('GET', $limitedPath, server: $limitedServer);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', $limitedPath, server: $limitedServer);
        self::assertResponseStatusCodeSame(429);
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    /** @return array{string, string} */
    private function unsubscribePath(Connection $connection, AuthenticatedUser $actor, string $email): array
    {
        $contactId = Identity::uuidV7();
        $listId = Identity::uuidV7();
        $now = Identity::now();
        $connection->insert('contact', ['id' => $contactId, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => 'Synthetic Recipient', 'first_name' => 'Rae', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase7-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 7 public list '.substr($listId, -6), 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'manual', 'created_at' => $now]);
        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($actor, 'Phase 7 public template '.substr($listId, -6), 'Phase 7 public subject', '', '<p>Phase 7 public body.</p>', 'Phase 7 public body.');
        $versions = $templates->versions($actor, $templateId);
        $versionId = $versions[0]['id'] ?? null;
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 7 public campaign '.substr($listId, -6), $versionId, [$listId]);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, $this->phase4Int($campaigns->detail($actor, $campaignId)['operation_version']), true);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $transport->reset();
        $campaigns->queue($actor, $campaignId);
        $recipientId = $connection->fetchOne('SELECT id FROM campaign_recipient WHERE campaign_id = ?', [$campaignId]);
        self::assertIsString($recipientId);
        $url = $this->phase4Service(RecipientTokenService::class)->issue($campaignId, $recipientId, $contactId, $email);
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);

        return [$path, $email];
    }
}
