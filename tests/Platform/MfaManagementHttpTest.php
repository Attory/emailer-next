<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\Totp;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zxing\QrReader;

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
        self::assertTrue(200 === $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue('no-referrer' === $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertSame(1, $page->filter('img.mfa-enrollment-qr')->count());
        // Avoid assertion diffs that could serialize a secret-bearing image or seed.
        $image = $page->filter('img.mfa-enrollment-qr')->attr('src');
        self::assertTrue(\is_string($image) && str_starts_with($image, 'data:image/png;base64,'));
        $pixels = base64_decode(substr($image, 22), true);
        self::assertTrue(\is_string($pixels) && str_starts_with($pixels, "\x89PNG\r\n\x1a\n"));
        // Never use assertions that dump generated MFA material on failure.
        $manualSeed = $page->filter('p code')->first()->text();
        $decoded = (new QrReader($pixels, QrReader::SOURCE_TYPE_BLOB, false))->text();
        self::assertTrue(\is_string($decoded) && str_starts_with($decoded, 'otpauth://totp/'));
        $query = parse_url($decoded, PHP_URL_QUERY);
        self::assertTrue(\is_string($query));
        parse_str($query, $parameters);
        $seed = $parameters['secret'] ?? null;
        self::assertTrue(\is_string($seed) && hash_equals($manualSeed, $seed));
        self::assertTrue('Emailer Next' === ($parameters['issuer'] ?? null));
        self::assertTrue('SHA1' === ($parameters['algorithm'] ?? null));
        self::assertTrue('6' === ($parameters['digits'] ?? null) && '30' === ($parameters['period'] ?? null));
        $totp = new Totp();
        $code = $totp->code($seed, intdiv(time(), 30));
        $client->request('POST', '/admin/mfa/confirm', ['_token' => 'invalid', 'totp_code' => $code, 'recovery_saved' => '1'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->submit($page->selectButton('Confirm MFA')->form(['totp_code' => $code, 'recovery_saved' => '1']), [], $server);
        self::assertResponseRedirects('/admin/mfa', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Status: Enabled');
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM mfa_credential WHERE user_id=? AND credential_type='totp' AND active=1", [$user->id()])));
        $credential = $db->fetchOne("SELECT encrypted_secret_id FROM mfa_credential WHERE user_id=? AND credential_type='totp' AND active=1", [$user->id()]);
        self::assertSelectorNotExists('img.mfa-enrollment-qr');
        self::assertSelectorNotExists('form[action="/admin/mfa/start"]');
        $page = $client->getCrawler();
        $client->submit($page->selectButton('Sign out')->form(), [], $server);
        self::assertResponseRedirects('/login', 303);
        $page = $client->request('GET', '/login', server: $server);
        $freshCode = $totp->code($seed, intdiv(time(), 30) + 1);
        $client->submit($page->selectButton('Sign in')->form(['login_identifier' => 'owner@example.test', 'password' => $password, 'mfa_code' => $freshCode]), [], $server);
        $client->followRedirect();
        $client->request('GET', '/admin/mfa', server: $server);
        self::assertSelectorTextContains('main', 'Status: Enabled');
        self::assertSelectorNotExists('img.mfa-enrollment-qr');
        $afterLogin = $this->phase4Service(Connection::class);
        self::assertTrue($credential === $afterLogin->fetchOne("SELECT encrypted_secret_id FROM mfa_credential WHERE user_id=? AND credential_type='totp' AND active=1", [$user->id()]));
        self::assertSame($mail, $db->fetchOne('SELECT COUNT(*) FROM delivery_attempt'));
    }
}
