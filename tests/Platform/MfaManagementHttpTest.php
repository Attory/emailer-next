<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\Totp;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MfaManagementHttpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testFreshPurposeReturnsToMfaAndTheFormEnrollsWithoutSending(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'Synthetic MFA '.bin2hex(random_bytes(20));
        $user = $this->createPhase4User($db, 'owner@example.test', $password);
        $server = $this->phase4HttpsServer();
        $mail = $db->fetchOne('SELECT COUNT(*) FROM delivery_attempt');
        $page = $client->request('GET', '/login', server: $server);
        $client->submit($page->selectButton('Sign in')->form(['login_identifier' => 'owner@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $client->request('GET', '/admin/mfa/start', server: $server);
        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'POST');
        $page = $client->request('GET', '/admin/mfa', server: $server);
        $client->submit($page->selectButton('Start or restart enrollment')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);
        $page = $client->request('GET', '/admin/reauthenticate?purpose=backup_management', server: $server);
        $client->submit($page->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin/backups', 303);
        $page = $client->request('GET', '/admin/mfa', server: $server);
        $client->submit($page->selectButton('Start or restart enrollment')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);
        $page = $client->request('GET', '/admin/reauthenticate?purpose=mfa_management&return=https://untrusted.example', server: $server);
        $before = $client->getCookieJar()->get('EMAILERSESSID')?->getValue();
        $client->submit($page->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin/mfa', 303);
        self::assertTrue($before !== $client->getCookieJar()->get('EMAILERSESSID')?->getValue());
        $page = $client->followRedirect();
        self::assertSelectorTextContains('main p[role="status"]', 'Identity confirmed');
        self::assertSelectorNotExists('a[href*="reauthenticate"]');
        $client->request('POST', '/admin/mfa/start', ['_token' => 'invalid'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $page = $client->request('GET', '/admin/mfa', server: $server);
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', time() - 301);
        $session->save();
        $client->submit($page->selectButton('Start or restart enrollment')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);
        $page = $client->request('GET', '/admin/reauthenticate?purpose=mfa_management', server: $server);
        $client->submit($page->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        $page = $client->followRedirect();
        $page = $client->submit($page->selectButton('Start or restart enrollment')->form(), [], $server);
        self::assertResponseIsSuccessful();
        // Never use assertions that dump generated MFA material on failure.
        $seed = $page->filter('p code')->first()->text();
        $totp = new Totp();
        $code = $totp->code($seed, intdiv(time(), 30));
        $client->request('POST', '/admin/mfa/confirm', ['_token' => 'invalid', 'totp_code' => $code, 'recovery_saved' => '1'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->submit($page->selectButton('Confirm MFA')->form(['totp_code' => $code, 'recovery_saved' => '1']), [], $server);
        self::assertResponseRedirects('/admin/mfa', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Status: Enabled');
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM mfa_credential WHERE user_id=? AND credential_type='totp' AND active=1", [$user->id()])));
        self::assertSame($mail, $db->fetchOne('SELECT COUNT(*) FROM delivery_attempt'));
    }
}
