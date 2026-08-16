<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\MfaManagement;
use App\Module\Identity\Application\RoutePolicyRegistry;
use App\Module\Identity\Application\Totp;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

final class Phase4AccessTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testAuthenticationIsGenericRotatesSessionAndUsesHardenedCookie(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-owner-'.bin2hex(random_bytes(10));
        $this->createPhase4User($connection, 'owner@example.test', $password);
        $successfulLoginAudits = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authentication.login' AND result = 'success'"));

        $server = $this->phase4HttpsServer('2001:db8::41');
        $client->request('GET', '/admin', server: $server);
        self::assertResponseRedirects('https://localhost/login');

        $client->request('GET', '/login', server: $server);
        $before = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($before);
        $this->submitLogin($client, 'missing@example.test', 'wrong-password-value', '', $server);
        self::assertResponseRedirects('/login', 303);
        $throttle = $this->phase4Service(\App\Module\Identity\Application\LoginThrottle::class);
        self::assertSame($throttle->accountHash('missing@example.test'), $connection->fetchOne("SELECT target_id FROM audit_event WHERE action = 'authentication.failure' ORDER BY occurred_at DESC LIMIT 1"));
        $client->followRedirect();
        self::assertSelectorTextSame('[role="alert"]', 'Authentication failed.');

        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, str_repeat('a', 5_000).'@example.test', 'wrong-password-value', '', $server);
        self::assertLessThan(4_096, $this->phase4Int($connection->fetchOne('SELECT MAX(OCTET_LENGTH(payload)) FROM user_session')));

        $server = $this->phase4HttpsServer('2001:db8::42');
        $client->request('GET', '/login', server: $server);
        $beforeSuccess = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($beforeSuccess);
        $this->submitLogin($client, 'OWNER@EXAMPLE.TEST', $password, '', $server);
        self::assertResponseRedirects('/admin', 303);
        $after = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($after);
        self::assertNotSame($beforeSuccess->getValue(), $after->getValue());
        self::assertTrue($after->isSecure());
        self::assertTrue($after->isHttpOnly());
        self::assertSame('lax', strtolower((string) $after->getSameSite()));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id IS NOT NULL AND revoked_at IS NULL')));
        self::assertSame($successfulLoginAudits + 1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authentication.login' AND result = 'success'")));
    }

    public function testRoleMatrixAndDefaultDenyRegistryCoverEveryAdminRoute(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-role-'.bin2hex(random_bytes(10));
        foreach (AdministrativeRole::cases() as $role) {
            $this->createPhase4User($connection, strtolower($role->label()).'@example.test', $password, $role);
        }

        $policies = (new RoutePolicyRegistry())->all();
        $router = $this->phase4Service(RouterInterface::class);
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/admin')) {
                self::assertArrayHasKey($name, $policies);
                self::assertSame($route->getMethods(), $policies[$name]['methods']);
            }
        }

        foreach (AdministrativeRole::cases() as $index => $role) {
            if (0 < $index) {
                self::ensureKernelShutdown();
                $client = self::createClient();
            }
            $roleClient = $client;
            $server = $this->phase4HttpsServer('2001:db8::'.(50 + $index));
            $roleClient->request('GET', '/login', server: $server);
            $this->submitLogin($roleClient, strtolower($role->label()).'@example.test', $password, '', $server);
            self::assertResponseRedirects('/admin', 303);
            $roleClient->request('GET', '/admin', server: $server);
            self::assertResponseIsSuccessful();
            $roleClient->request('GET', '/admin/audit', server: $server);
            self::assertResponseIsSuccessful();
            $roleClient->request('HEAD', '/admin/profile', server: $server);
            self::assertResponseIsSuccessful();
            $roleClient->request('GET', '/admin/users', server: $server);
            if ($role->rank() >= AdministrativeRole::Administrator->rank()) {
                self::assertResponseIsSuccessful();
            } else {
                self::assertResponseStatusCodeSame(403);
                self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authorization.denied' AND actor_user_id = ? AND source_hash IS NOT NULL", [$this->phase4String($connection->fetchOne('SELECT id FROM app_user WHERE login_identifier = ?', [strtolower($role->label()).'@example.test']))])));
            }
        }
    }

    public function testEveryAdministrativeMutationRejectsMissingCsrfAndGetIsSafe(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-csrf-'.bin2hex(random_bytes(10));
        $user = $this->createPhase4User($connection, 'owner@example.test', $password);
        $server = $this->phase4HttpsServer('2001:db8::60');
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'owner@example.test', $password, '', $server);

        $mutations = [
            '/admin/logout',
            '/admin/reauthenticate',
            '/admin/profile/password',
            '/admin/sessions/revoke',
            '/admin/mfa/start',
            '/admin/mfa/confirm',
            '/admin/mfa/disable',
            '/admin/mfa/recovery',
            '/admin/users/create',
            '/admin/users/'.$user->id().'/update',
            '/admin/users/'.$user->id().'/delete',
        ];
        foreach ($mutations as $path) {
            $client->request('POST', $path, server: $server);
            self::assertResponseStatusCodeSame(400, $path);
            $client->request('GET', $path, server: $server);
            if ('/admin/reauthenticate' === $path) {
                self::assertResponseIsSuccessful($path);
            } else {
                self::assertResponseStatusCodeSame(405, $path);
            }
        }
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ? AND active = 1', [$user->id()])));
    }

    public function testSecurityHeadersAndTrustedProxyBoundary(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $authenticationAuditBaseline = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action IN ('authentication.login', 'authentication.failure')"));

        $client->request('GET', '/login', server: $this->phase4HttpsServer('2001:db8::70'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));

        self::ensureKernelShutdown();
        $client = self::createClient();
        $untrusted = ['SERVER_PORT' => '80', 'REMOTE_ADDR' => '2001:db8::71', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $client->request('GET', '/login', server: $untrusted);
        self::assertFalse($client->getRequest()->isSecure());
        self::assertResponseRedirects('https://localhost/login', 308);
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('POST', '/login', parameters: [
            'login_identifier' => 'owner@example.test',
            'password' => 'plaintext-must-not-be-processed',
        ], server: $untrusted);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($authenticationAuditBaseline, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action IN ('authentication.login', 'authentication.failure')")));

        self::ensureKernelShutdown();
        $client = self::createClient();
        $trusted = ['SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $client->request('GET', '/login', server: $trusted);
        self::assertTrue($client->getRequest()->isSecure());
        self::assertResponseIsSuccessful();
        self::assertResponseHasHeader('Strict-Transport-Security');

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/login', server: $trusted + ['HTTP_X_FORWARDED_HOST' => 'attacker.example']);
        self::assertResponseIsSuccessful();
        self::assertSame('localhost', $client->getRequest()->getHost());

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/login', server: $this->phase4HttpsServer() + ['HTTP_HOST' => 'emailer.example.test']);
        self::assertResponseRedirects('https://localhost/login', 308);

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/login', server: $this->phase4HttpsServer() + ['HTTP_HOST' => 'attacker.example']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testPasswordResetDeliveryIsGenericFragmentBoundAndSingleUse(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $login = 'reset-'.bin2hex(random_bytes(5)).'@example.test';
        $oldPassword = 'phase4-old-'.bin2hex(random_bytes(10));
        $newPassword = 'phase4-new-'.bin2hex(random_bytes(10));
        $user = $this->createPhase4User($connection, $login, $oldPassword);
        $attributedResetAuditBaseline = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action LIKE 'identity.password_reset.%' AND actor_user_id IS NOT NULL"));
        $resetRequestAuditBaseline = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.password_reset.requested'"));

        $unknownServer = $this->phase4HttpsServer('2001:db8:91:'.implode(':', str_split(bin2hex(random_bytes(6)), 4)));
        $crawler = $client->request('GET', '/password-reset', server: $unknownServer);
        $client->submit($crawler->selectButton('Request reset')->form(['login_identifier' => 'unknown-'.bin2hex(random_bytes(4)).'@example.test']), [], $unknownServer);
        self::assertResponseIsSuccessful();
        $genericUnknown = (string) $client->getResponse()->getContent();
        self::assertSame($resetRequestAuditBaseline + 1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.password_reset.requested'")));
        self::assertSame('[Emailer Next] Password reset', $this->mailpitMessageFor('phase4-sender@example.test')['Subject'] ?? null);

        $knownServer = $this->phase4HttpsServer('2001:db8:92:'.implode(':', str_split(bin2hex(random_bytes(6)), 4))) + ['HTTP_X_FORWARDED_HOST' => 'attacker.example'];
        $crawler = $client->request('GET', '/password-reset', server: $knownServer);
        $client->submit($crawler->selectButton('Request reset')->form(['login_identifier' => $login]), [], $knownServer);
        self::assertResponseIsSuccessful();
        self::assertSame($genericUnknown, (string) $client->getResponse()->getContent());
        self::assertSame($resetRequestAuditBaseline + 2, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.password_reset.requested'")));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM password_reset_token WHERE user_id = ?', [$user->id()])));

        $message = $this->mailpitMessageFor($login);
        self::assertSame('[Emailer Next] Password reset', $message['Subject'] ?? null);
        $text = $message['Text'] ?? null;
        self::assertIsString($text);
        $matches = [];
        self::assertSame(1, preg_match('~/password-reset/([0-9a-f-]{36})#token=([A-Za-z0-9_-]+)~', $text, $matches));
        self::assertStringContainsString('https://localhost/password-reset/', $text);
        self::assertStringNotContainsString('attacker.example', $text);
        $selector = $matches[1] ?? '';
        $rawToken = $matches[2] ?? '';
        self::assertSame(36, \strlen($selector));
        self::assertGreaterThanOrEqual(43, \strlen($rawToken));
        self::assertStringNotContainsString($rawToken, json_encode($connection->fetchAssociative('SELECT * FROM password_reset_token WHERE id = ?', [$selector]), JSON_THROW_ON_ERROR));

        $crawler = $client->request('GET', '/password-reset/'.$selector, server: $knownServer);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('reset_token=', $client->getRequest()->getUri());
        self::assertStringNotContainsString($rawToken, (string) $client->getResponse()->getContent());
        $client->submit($crawler->selectButton('Reset password')->form([
            'reset_token' => $rawToken,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]), [], $knownServer);
        self::assertResponseRedirects('/login', 303);
        self::assertTrue(password_verify($newPassword, $this->phase4String($connection->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$user->id()]))));

        $crawler = $client->request('GET', '/password-reset/'.$selector, server: $knownServer);
        $client->submit($crawler->selectButton('Reset password')->form([
            'reset_token' => $rawToken,
            'password' => $newPassword.'x',
            'password_confirmation' => $newPassword.'x',
        ]), [], $knownServer);
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('h1', 'Password reset not accepted');
        self::assertSame($attributedResetAuditBaseline, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action LIKE 'identity.password_reset.%' AND actor_user_id IS NOT NULL")));
        $this->assertPhase4RuntimeFilesExclude([$rawToken, $oldPassword, $newPassword]);
    }

    public function testRecentReauthenticationGatesAndPermitsSensitiveUserMutation(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-reauth-'.bin2hex(random_bytes(10));
        $this->createPhase4User($connection, 'owner@example.test', $password);
        $server = $this->phase4HttpsServer('2001:db8::93');
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'owner@example.test', $password, '', $server);

        $crawler = $client->request('GET', '/admin/users', server: $server);
        $client->submit($crawler->selectButton('Create account')->form([
            'login_identifier' => 'premature@example.test',
            'password' => 'phase4-created-'.bin2hex(random_bytes(10)),
            'role' => AdministrativeRole::Auditor->value,
        ]), [], $server);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=user_management', server: $server);
        $sessionBefore = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($sessionBefore);
        $client->submit($crawler->selectButton('Re-authenticate')->form([
            'password' => $password,
            'mfa_code' => '',
        ]), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $sessionAfter = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($sessionAfter);
        self::assertNotSame($sessionBefore->getValue(), $sessionAfter->getValue());

        $createdLogin = 'created-'.bin2hex(random_bytes(4)).'@example.test';
        $crawler = $client->request('GET', '/admin/users', server: $server);
        $client->submit($crawler->selectButton('Create account')->form([
            'login_identifier' => $createdLogin,
            'password' => 'phase4-created-'.bin2hex(random_bytes(10)),
            'role' => AdministrativeRole::Auditor->value,
        ]), [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE login_identifier = ?', [$createdLogin])));
    }

    public function testCombinedPasswordMfaLoginAndRecoveryReplayRejection(): void
    {
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-login-mfa-'.bin2hex(random_bytes(10));
        $user = $this->createPhase4User($connection, 'mfa-login@example.test', $password);
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $enrollment = $management->start($user);
        $management->confirm($user, $totp->code($enrollment->seed, intdiv(time(), 30) - 1), true, 'not-a-live-session');

        $server = $this->phase4HttpsServer('2001:db8::94');
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'mfa-login@example.test', $password, '', $server);
        self::assertResponseRedirects('/login', 303);
        $connection->executeStatement('DELETE FROM login_throttle');

        $totpCode = $totp->code($enrollment->seed, intdiv(time(), 30));
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'mfa-login@example.test', $password, $totpCode, $server);
        self::assertResponseRedirects('/admin', 303);

        self::ensureKernelShutdown();
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $connection->executeStatement('DELETE FROM login_throttle');
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'mfa-login@example.test', $password, $totpCode, $server);
        self::assertResponseRedirects('/login', 303);
        $connection->executeStatement('DELETE FROM login_throttle');

        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'mfa-login@example.test', $password, $enrollment->recoveryCodes[0], $server);
        self::assertResponseRedirects('/admin', 303);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM mfa_credential WHERE user_id = ? AND credential_type = 'recovery_code' AND consumed_at IS NOT NULL", [$user->id()])));

        $crawler = $client->request('GET', '/admin/logout', server: $server);
        self::assertResponseStatusCodeSame(405);
        unset($crawler);
        self::ensureKernelShutdown();
        $client = self::createClient();
        $connection = $this->phase4Service(Connection::class);
        $connection->executeStatement('DELETE FROM login_throttle');
        $client->request('GET', '/login', server: $server);
        $this->submitLogin($client, 'mfa-login@example.test', $password, $enrollment->recoveryCodes[0], $server);
        self::assertResponseRedirects('/login', 303);
    }

    /** @param array<string, string> $server */
    private function submitLogin(KernelBrowser $client, string $login, string $password, string $mfa, array $server): void
    {
        $form = $client->getCrawler()->selectButton('Sign in')->form([
            'login_identifier' => $login,
            'password' => $password,
            'mfa_code' => $mfa,
        ]);
        $client->submit($form, [], $server);
    }

    /** @return array<mixed> */
    private function mailpitMessageFor(string $recipient): array
    {
        $listing = file_get_contents('http://mailpit:8025/api/v1/messages?limit=100');
        self::assertIsString($listing);
        $decoded = json_decode($listing, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $messages = $decoded['messages'] ?? null;
        self::assertIsArray($messages);
        foreach ($messages as $message) {
            if (!\is_array($message) || !\is_string($message['ID'] ?? null) || !\is_array($message['To'] ?? null)) {
                continue;
            }
            foreach ($message['To'] as $to) {
                if (\is_array($to) && ($to['Address'] ?? null) === $recipient) {
                    $detail = file_get_contents('http://mailpit:8025/api/v1/message/'.rawurlencode($message['ID']));
                    self::assertIsString($detail);
                    $decodedDetail = json_decode($detail, true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($decodedDetail);

                    return $decodedDetail;
                }
            }
        }

        self::fail('The synthetic password-reset message was not captured by Mailpit.');
    }
}
