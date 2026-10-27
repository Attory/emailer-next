<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\MfaManagement;
use App\Module\Identity\Application\Totp;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class UserManagementConfirmationHttpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testCreateUpdateDeleteConfirmInlineWithoutRedisplayingSecrets(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-owner-'.bin2hex(random_bytes(12));
        $newPassword = 'synthetic-operator-'.bin2hex(random_bytes(12));
        $owner = $this->createPhase4User($db, 'owner@example.test', $password);
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $enrollment = $management->start($owner);
        $management->confirm($owner, $totp->code($enrollment->seed, intdiv(time(), 30) - 1), true, 'synthetic-session');
        $server = $this->phase4HttpsServer('2001:db8::103');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'owner@example.test', 'password' => $password, 'mfa_code' => $enrollment->recoveryCodes[0]]), [], $server);
        $crawler = $client->request('GET', '/admin/users', server: $server);
        self::assertSelectorNotExists('a[href*="reauthenticate"]');
        self::assertSelectorExists('form[action="/admin/users/create"] dialog');
        $fields = ['login_identifier' => 'operator@example.test', 'password' => $newPassword, 'role' => 'ROLE_OPERATOR'];
        $client->submit($crawler->selectButton('Create account')->form($fields), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        self::assertSelectorTextContains('p[role="alert"]', 'Confirm your current password');
        self::assertSelectorExists('input[name="login_identifier"][value="operator@example.test"]');
        self::assertSelectorExists('form[action="/admin/users/create"] option[value="ROLE_OPERATOR"][selected]');
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user')));
        foreach ([$password, $newPassword, $enrollment->recoveryCodes[1]] as $secret) {
            self::assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());
        }
        $client->submit($client->getCrawler()->selectButton('Create account')->form($fields + ['actor_password' => 'incorrect', 'actor_mfa_code' => $enrollment->recoveryCodes[1]]), [], $server);
        self::assertResponseStatusCodeSame(422);
        $db->executeStatement('DELETE FROM login_throttle');
        $client->submit($client->getCrawler()->selectButton('Create account')->form($fields + ['actor_password' => $password, 'actor_mfa_code' => '']), [], $server);
        self::assertResponseStatusCodeSame(422);
        $db->executeStatement('DELETE FROM login_throttle');
        $client->request('POST', '/admin/users/create', $fields + ['_token' => 'invalid', 'actor_password' => $password, 'actor_mfa_code' => $enrollment->recoveryCodes[1]], server: $server);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user')));
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', time());
        $session->set('identity.reauthenticated_purpose', 'mail_configuration');
        $session->save();
        $crawler = $client->request('GET', '/admin/users', server: $server);
        self::assertSelectorExists('form[action="/admin/users/create"][data-mail-settings-confirmation-expires-at-value="0"]');
        $before = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($before);
        $client->submit($crawler->selectButton('Create account')->form($fields + ['actor_password' => $password, 'actor_mfa_code' => $enrollment->recoveryCodes[1]]), [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        $after = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($after);
        self::assertNotSame($before->getValue(), $after->getValue());
        $id = $this->phase4String($db->fetchOne('SELECT id FROM app_user WHERE login_identifier = ?', ['operator@example.test']));
        self::assertSame('ROLE_OPERATOR', $db->fetchOne('SELECT role FROM app_user WHERE id = ?', [$id]));
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', time() - 301);
        $session->save();
        $crawler = $client->request('GET', '/admin/users', server: $server);
        $form = $crawler->filter('form[action="/admin/users/'.$id.'/update"]')->form(['role' => 'ROLE_AUDITOR', 'active' => '1']);
        $client->submit($form, [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ROLE_OPERATOR', $db->fetchOne('SELECT role FROM app_user WHERE id = ?', [$id]));
        $form = $client->getCrawler()->filter('form[action="/admin/users/'.$id.'/update"]')->form(['role' => 'ROLE_AUDITOR', 'active' => '1', 'actor_password' => $password, 'actor_mfa_code' => $enrollment->recoveryCodes[2]]);
        $client->submit($form, [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame('ROLE_AUDITOR', $db->fetchOne('SELECT role FROM app_user WHERE id = ?', [$id]));
        $crawler = $client->request('GET', '/admin/users', server: $server);
        $client->submit($crawler->filter('form[action="/admin/users/'.$id.'/delete"]')->form(), [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ?', [$id])));
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.user.created' AND target_id = ?", [$id])));
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', time() - 301);
        $session->save();
        $crawler = $client->request('GET', '/admin/users', server: $server);
        $sessionCount = $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ?', [$owner->id()]));
        $client->submit($crawler->filter('form[action="/admin/users/'.$owner->id().'/update"]')->form(['role' => 'ROLE_OWNER', 'active' => '1', 'actor_password' => $password, 'actor_mfa_code' => $enrollment->recoveryCodes[3]]), [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame($sessionCount + 2, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ?', [$owner->id()])));
        $client->request('GET', '/admin/users', server: $server);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p[role="status"]', 'User updated.');
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.user.updated' AND target_id = ?", [$owner->id()])));
        $audit = json_encode($db->fetchAllAssociative('SELECT * FROM audit_event WHERE actor_user_id = ?', [$owner->id()]), JSON_THROW_ON_ERROR);
        foreach ([$password, $newPassword, $enrollment->seed, $enrollment->recoveryCodes[1]] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
    }

    public function testOperatorCannotManageUsersButCanReadCampaignGuide(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-operator-'.bin2hex(random_bytes(12));
        $this->createPhase4User($db, 'operator@example.test', $password, AdministrativeRole::Operator);
        $server = $this->phase4HttpsServer('2001:db8::104');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'operator@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $client->request('POST', '/admin/users/create', ['login_identifier' => 'another@example.test', 'password' => $password, 'role' => 'ROLE_OPERATOR', 'actor_password' => $password], server: $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user')));
        $client->request('GET', '/admin/campaigns/guide', server: $server);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        self::assertSelectorTextContains('h1', 'Create and edit a campaign');
        self::assertSelectorTextContains('#edit', 'Send a fresh test');
        self::assertSelectorTextContains('#edit', 'select that new version');
        self::assertSelectorTextContains('#send', 'Approval does not start delivery');
    }
}
