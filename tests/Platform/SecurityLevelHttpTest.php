<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Configuration\Application\SecurityLevelManager;
use App\Module\Configuration\Infrastructure\DoctrineSecurityLevelReader;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\Contract\SecurityLevelReader;
use App\Module\Identity\Application\ReauthenticationService;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Group('integration')]
final class SecurityLevelHttpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testFreshSettingAndMalformedOrUnreadableValueFailClosed(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $reader = $this->phase4Service(SecurityLevelReader::class);
        self::assertSame('high', $reader->current());
        self::assertTrue($reader->requiresRecentAuthentication());
        $db->update('organisation_settings', ['security_level' => 'normal'], ['singleton_key' => '1']);
        self::assertSame('normal', $reader->current());
        self::assertFalse($reader->requiresRecentAuthentication());
        $db->update('organisation_settings', ['security_level' => 'high'], ['singleton_key' => '1']);
        try {
            $db->update('organisation_settings', ['security_level' => 'NORMAL'], ['singleton_key' => '1']);
            self::fail('The database accepted a security level outside the exact allowlist.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        }

        $unreadable = $this->createMock(Connection::class);
        $unreadable->expects(self::once())->method('fetchOne')->willThrowException(new \RuntimeException('Synthetic read failure'));
        self::assertSame('high', (new DoctrineSecurityLevelReader($unreadable))->current());
        $missing = $this->createMock(Connection::class);
        $missing->expects(self::once())->method('fetchOne')->willReturn(false);
        self::assertSame('high', (new DoctrineSecurityLevelReader($missing))->current());
        $invalid = $this->createMock(Connection::class);
        $invalid->expects(self::once())->method('fetchOne')->willReturn('unsupported');
        self::assertSame('high', (new DoctrineSecurityLevelReader($invalid))->current());
    }

    public function testOwnerDowngradeAndRestoreKeepAuthorizationCsrfAuditAndCsvGate(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'security-level-'.bin2hex(random_bytes(10));
        $owner = $this->createPhase4User($db, 'security-owner@example.test', $password);
        $this->createPhase4User($db, 'security-admin@example.test', $password, AdministrativeRole::Administrator);
        $this->createPhase4User($db, 'security-operator@example.test', $password, AdministrativeRole::Operator);
        $list = $this->phase4Service(ContactManager::class)->createList($owner, 'Synthetic list');
        $server = $this->phase4HttpsServer('2001:db8::235');

        $client->request('POST', '/admin/settings/security/update', server: $server);
        self::assertResponseRedirects('https://localhost/login');
        $this->login($client, 'security-owner@example.test', $password, $server);
        $client->request('GET', '/admin', server: $server);
        self::assertSelectorTextContains('section a[href="/admin/settings/security"]', 'Change security level');
        $crawler = $client->request('GET', '/admin/settings/security', server: $server);
        self::assertSelectorTextContains('main > p > strong', 'Current level: High');
        $form = $crawler->selectButton('Switch to Normal')->form();
        $csrf = $this->phase4String($form->getValues()['_token']);

        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal', 'confirm_normal' => 'lower_to_normal', '_token' => 'wrong'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal', '_token' => $csrf], server: $server);
        self::assertResponseRedirects('/admin/settings/security', 303);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal', 'confirm_normal' => 'lower_to_normal', '_token' => $csrf], server: $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=contact_data_access', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal', 'confirm_normal' => 'lower_to_normal', '_token' => $csrf], server: $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=security_level_change', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $crawler = $client->request('GET', '/admin/settings/security', server: $server);
        $client->submit($crawler->selectButton('Switch to Normal')->form(['confirm_normal' => 'lower_to_normal']), [], $server);
        self::assertResponseRedirects('/admin/settings/security', 303);
        self::assertSame('normal', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        self::assertSelectorNotExists('a[href*="reauthenticate?purpose=contact_data_access"]');
        $client->submit($crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseIsSuccessful();
        self::assertSame("email,first_name,last_name\r\n", $client->getInternalResponse()->getContent());

        $crawler = $client->request('GET', '/admin/settings/security', server: $server);
        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'high', '_token' => 'invalid'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/admin/settings/security', server: $server);
        $client->submit($crawler->selectButton('Switch to High')->form(), [], $server);
        self::assertResponseRedirects('/admin/settings/security', 303);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', 0);
        $session->save();
        $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal', 'confirm_normal' => 'lower_to_normal', '_token' => $csrf], server: $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        $client->submit($crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);

        $events = $db->fetchAllAssociative("SELECT actor_user_id, source_hash, summary_json FROM audit_event WHERE action = 'configuration.security_level.changed' AND actor_user_id = ? ORDER BY occurred_at", [$owner->id()]);
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame($owner->id(), $event['actor_user_id']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->phase4String($event['source_hash']));
            self::assertStringNotContainsString('@', $this->phase4String($event['summary_json']));
            self::assertStringNotContainsString($password, $this->phase4String($event['summary_json']));
        }
        self::assertSame(['old_level' => 'high', 'new_level' => 'normal'], json_decode($this->phase4String($events[0]['summary_json']), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['old_level' => 'normal', 'new_level' => 'high'], json_decode($this->phase4String($events[1]['summary_json']), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEveryAdministrativeRecentAuthenticationPurposeUsesOnePolicy(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $service = $this->phase4Service(ReauthenticationService::class);
        $session = new Session(new MockArraySessionStorage());
        $purposes = [
            'contact_data_access',
            'backup_management',
            'rsvp_data_access',
            'campaign_approval',
            'mail_configuration',
            'delivery_policy',
            'password_change',
            'session_management',
            'mfa_management',
            'user_management',
        ];
        foreach ($purposes as $purpose) {
            self::assertFalse($service->isRecent($session, $purpose), $purpose);
            try {
                $service->assertRecent($session, $purpose);
                self::fail('High did not enforce '.$purpose);
            } catch (AccessDeniedException) {
                // Expected for a session with no purpose-bound confirmation.
            }
        }
        $db->update('organisation_settings', ['security_level' => 'normal'], ['singleton_key' => '1']);
        foreach ($purposes as $purpose) {
            self::assertTrue($service->isRecent($session, $purpose), $purpose);
            $service->assertRecent($session, $purpose);
        }
        $db->update('organisation_settings', ['security_level' => 'high'], ['singleton_key' => '1']);
        foreach ($purposes as $purpose) {
            self::assertFalse($service->isRecent($session, $purpose), $purpose);
        }
        $this->expectException(AccessDeniedException::class);
        $service->assertRecentUnderHigh($session, 'security_level_change');
    }

    public function testNormalAdministrativeFormsApplyImmediatelyAndStillRequireCsrf(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'normal-forms-'.bin2hex(random_bytes(10));
        $this->createPhase4User($db, 'normal-forms-owner@example.test', $password);
        $server = $this->phase4HttpsServer('2001:db8::237');
        $this->login($client, 'normal-forms-owner@example.test', $password, $server);

        $highUsers = $client->request('GET', '/admin/users', server: $server);
        self::assertSelectorExists('form[action="/admin/users/create"] [name="actor_password"]');
        $client->submit($highUsers->selectButton('Create account')->form([
            'login_identifier' => 'high-blocked@example.test',
            'password' => 'synthetic-valid-'.bin2hex(random_bytes(10)),
            'role' => AdministrativeRole::Auditor->value,
        ]), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user WHERE login_identifier = ?', ['high-blocked@example.test'])));

        $db->update('organisation_settings', ['security_level' => 'normal'], ['singleton_key' => '1']);
        $normalUsers = $client->request('GET', '/admin/users', server: $server);
        self::assertSelectorNotExists('form[action="/admin/users/create"] [name="actor_password"]');
        self::assertSelectorNotExists('form[action="/admin/users/create"][data-mail-settings-confirmation-expires-at-value]');
        $create = $normalUsers->selectButton('Create account')->form([
            'login_identifier' => 'normal-created@example.test',
            'password' => 'synthetic-valid-'.bin2hex(random_bytes(10)),
            'role' => AdministrativeRole::Auditor->value,
        ]);
        $client->request('POST', '/admin/users/create', array_replace($create->getValues(), ['_token' => 'invalid']), server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->submit($create, [], $server);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user WHERE login_identifier = ?', ['normal-created@example.test'])));

        $highDelivery = $db->fetchAssociative('SELECT * FROM delivery_policy');
        $normalDelivery = $client->request('GET', '/admin/settings/delivery', server: $server);
        self::assertSelectorNotExists('form[action="/admin/settings/delivery/update"] [name="owner_password"]');
        $limits = $normalDelivery->selectButton('Save delivery limits')->form([
            'messages_per_minute' => '30',
            'worker_concurrency' => '2',
            'retry_limit' => '3',
            'maximum_campaign_size' => '500',
        ]);
        $client->request('POST', '/admin/settings/delivery/update', array_replace($limits->getValues(), ['_token' => 'invalid']), server: $server);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($highDelivery, $db->fetchAssociative('SELECT * FROM delivery_policy'));
        $client->submit($limits, [], $server);
        self::assertResponseRedirects('/admin/settings/delivery', 303);
        self::assertSame(30, $this->phase4Int($db->fetchOne('SELECT messages_per_minute FROM delivery_policy')));

        $normalMail = $client->request('GET', '/admin/settings/mail', server: $server);
        self::assertSelectorNotExists('form[action="/admin/settings/mail/update"] [name="owner_password"]');
        $mail = $normalMail->selectButton('Store secure SMTP configuration')->form([
            'host' => 'smtp.example.test',
            'port' => '587',
            'encryption' => 'starttls',
            'username' => 'sender@example.test',
            'password' => 'synthetic-smtp-'.bin2hex(random_bytes(10)),
            'sender_address' => 'sender@example.test',
            'sender_name' => 'Synthetic Sender',
            'reply_to' => 'reply@example.test',
        ]);
        $client->request('POST', '/admin/settings/mail/update', array_replace($mail->getValues(), ['_token' => 'invalid']), server: $server);
        self::assertResponseStatusCodeSame(400);
        $client->submit($mail, [], $server);
        self::assertResponseRedirects('/admin/settings/mail', 303);
        self::assertSame('smtp.example.test', $this->phase4String($db->fetchOne('SELECT smtp_host FROM sender_identity')));

        $db->update('organisation_settings', ['security_level' => 'high'], ['singleton_key' => '1']);
        $highAgain = $client->request('GET', '/admin/users', server: $server);
        self::assertSelectorExists('form[action="/admin/users/create"] [name="actor_password"]');
        $client->submit($highAgain->selectButton('Create account')->form([
            'login_identifier' => 'restored-blocked@example.test',
            'password' => 'synthetic-valid-'.bin2hex(random_bytes(10)),
            'role' => AdministrativeRole::Auditor->value,
        ]), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM app_user WHERE login_identifier = ?', ['restored-blocked@example.test'])));
    }

    public function testAdministratorCanViewButCannotChangeAndOperatorCannotViewInBothLevels(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'security-roles-'.bin2hex(random_bytes(10));
        $owner = $this->createPhase4User($db, 'roles-owner@example.test', $password);
        $this->createPhase4User($db, 'roles-admin@example.test', $password, AdministrativeRole::Administrator);
        $this->createPhase4User($db, 'roles-operator@example.test', $password, AdministrativeRole::Operator);
        $list = $this->phase4Service(ContactManager::class)->createList($owner, 'Role matrix list');
        $server = $this->phase4HttpsServer('2001:db8::236');

        foreach (['high', 'normal'] as $level) {
            $db->update('organisation_settings', ['security_level' => $level], ['singleton_key' => '1']);
            $client->request('GET', '/admin/settings/security', server: $server);
            self::assertResponseRedirects('https://localhost/login');
            $this->login($client, 'roles-admin@example.test', $password, $server);
            $client->request('GET', '/admin', server: $server);
            self::assertSelectorTextContains('section a[href="/admin/settings/security"]', 'View security settings');
            $client->request('GET', '/admin/settings/security', server: $server);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main > p > strong', 'Current level: '.ucfirst($level));
            self::assertSelectorNotExists('form[action="/admin/settings/security/update"]');
            $client->request('POST', '/admin/settings/security/update', ['security_level' => 'high', '_token' => 'invalid'], server: $server);
            self::assertResponseStatusCodeSame(403);
            $crawler = $client->request('GET', '/admin/lists', server: $server);
            $form = $crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form();
            $client->request('POST', '/admin/lists/'.$list.'/download', ['_token' => 'invalid'], server: $server);
            self::assertResponseStatusCodeSame(400);
            $client->submit($form, [], $server);
            if ('high' === $level) {
                self::assertResponseStatusCodeSame(403);
            } else {
                self::assertResponseIsSuccessful();
                self::assertSame("email,first_name,last_name\r\n", $client->getInternalResponse()->getContent());
            }

            self::ensureKernelShutdown();
            $client = self::createClient();
            $this->login($client, 'roles-operator@example.test', $password, $server);
            $client->request('GET', '/admin/settings/security', server: $server);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/settings/security/update', ['security_level' => 'normal'], server: $server);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/lists/'.$list.'/download', server: $server);
            self::assertResponseStatusCodeSame(403);

            self::ensureKernelShutdown();
            $client = self::createClient();
            $db = $this->phase4Service(Connection::class);
        }
    }

    public function testRepeatedChangeIsIdempotentAndAuditFailureRollsBackAtomically(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'atomic-owner@example.test', 'atomic-'.bin2hex(random_bytes(10)));
        $manager = $this->phase4Service(SecurityLevelManager::class);
        $session = new Session(new MockArraySessionStorage());
        $source = str_repeat('a', 64);
        $manager->change($owner, $session, 'high', false, $source);
        self::assertSame(0, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'configuration.security_level.changed' AND actor_user_id = ?", [$owner->id()])));
        $db->update('organisation_settings', ['security_level' => 'normal'], ['singleton_key' => '1']);
        try {
            $manager->change($owner, $session, 'high', false, 'invalid-source-hash');
            self::fail('Invalid audit metadata was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('normal', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        }
        $manager->change($owner, $session, 'high', false, $source);
        $manager->change($owner, $session, 'high', false, $source);
        self::assertSame('high', $db->fetchOne("SELECT security_level FROM organisation_settings WHERE singleton_key = '1'"));
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'configuration.security_level.changed' AND actor_user_id = ?", [$owner->id()])));
    }

    /** @param array<string, string> $server */
    private function login(KernelBrowser $client, string $login, string $password, array $server): void
    {
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => $login, 'password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseStatusCodeSame(303);
    }
}
