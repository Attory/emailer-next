<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\MfaManagement;
use App\Module\Identity\Application\Totp;
use App\Module\Templates\Application\TemplateManager;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Group('integration')]
final class Phase13OwnerWorkflowHttpTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testListAndTemplateActionsPrefillSafeDraftAndPreviewUploadedRecipients(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-owner-'.bin2hex(random_bytes(12));
        $owner = $this->createPhase4User($db, 'owner@example.test', $password);
        $contacts = $this->phase4Service(ContactManager::class);
        $list = $contacts->createList($owner, 'Synthetic list');
        $contact = $contacts->create($owner, 'visible@example.test', ['consent_source' => 'synthetic']);
        $contacts->addMembership($owner, $list, $contact);
        $templates = $this->phase4Service(TemplateManager::class);
        $template = $templates->create($owner, 'Synthetic template', 'Synthetic subject', '', '<p>Hello</p>', 'Hello');
        $version = $this->phase4String($templates->versions($owner, $template)[0]['id']);
        $server = $this->phase4HttpsServer('2001:db8::131');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'owner@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $client->request('GET', '/admin/lists', server: $server);
        foreach (['View', 'Edit', 'Send to', 'Duplicate', 'Archive'] as $action) {
            self::assertSelectorTextContains('table', $action);
        }
        $client->request('GET', '/admin/lists/'.$list, server: $server);
        self::assertSelectorTextContains('table', 'visible@example.test');
        self::assertResponseHeaderSame('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $crawler = $client->request('GET', '/admin/lists/'.$list.'/duplicate', server: $server);
        self::assertSame('', $crawler->filter('input[name="name"]')->attr('value'));
        $client->request('GET', '/admin/templates', server: $server);
        foreach (['Preview', 'Edit', 'Use in campaign', 'Duplicate', 'Archive'] as $action) {
            self::assertSelectorTextContains('table', $action);
        }
        $client->request('GET', '/admin/campaigns?list='.$list.'&template='.$version, server: $server);
        self::assertSelectorExists('input[name="lists[]"][value="'.$list.'"][checked]');
        self::assertSelectorExists('option[value="'.$version.'"][selected]');
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign')));
        self::assertSame('draft', $db->fetchOne('SELECT status FROM email_template WHERE id = ?', [$template]));
        $csv = tempnam(sys_get_temp_dir(), 'emailer-http-csv-');
        self::assertIsString($csv);
        file_put_contents($csv, "email\nnew@example.test\ninvalid\n");
        try {
            $token = $this->phase4String($client->getCrawler()->filter('form[action="/admin/campaigns/create"] input[name="_token"]')->first()->attr('value'));
            $client->request('POST', '/admin/campaigns/create', [
                '_token' => $token, 'name' => 'Uploaded draft', 'audience_mode' => 'upload', 'csv_mode' => 'headered', 'template_version' => '',
            ], ['recipient_csv' => new UploadedFile($csv, 'synthetic.csv', 'text/csv', test: true)], $server);
            self::assertResponseStatusCodeSame(303);
            $client->followRedirect();
            self::assertSelectorExists('[data-campaign-editor] input[name="name"][value="Uploaded draft"]');
            self::assertSelectorTextContains('main', '1 recipients already saved');
            self::assertSelectorTextContains('main', '1 invalid rows left out');
            self::assertSelectorNotExists('[name="template_mode"], [name="html"], [name="template_file"]');
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM list_membership')));
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact_list')));
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact')));
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact_import')));
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient')));
            $campaignId = $this->phase4String($db->fetchOne('SELECT id FROM campaign'));
            self::assertNull($db->fetchOne('SELECT template_version_id FROM campaign WHERE id=?', [$campaignId]));
            $client->request('GET', '/admin/campaigns/'.$campaignId, server: $server);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action$="/review"] button[disabled]');
            self::assertSelectorExists('form[action$="/test"] button[disabled]');
            self::assertSame('draft', $db->fetchOne('SELECT status FROM email_template WHERE id=?', [$template]));
            foreach (['campaign_test_delivery', 'campaign_recipient', 'messenger_messages', 'delivery_attempt'] as $table) {
                self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM '.$table)));
            }
        } finally {
            if (is_file($csv)) {
                unlink($csv);
            }
        }
    }

    public function testAdministratorRevealStillRequiresRecentAuthentication(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-admin-'.bin2hex(random_bytes(12));
        $admin = $this->createPhase4User($db, 'admin@example.test', $password, AdministrativeRole::Administrator);
        $contact = $this->phase4Service(ContactManager::class)->create($admin, 'restricted@example.test', ['consent_source' => 'synthetic']);
        $server = $this->phase4HttpsServer('2001:db8::133');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'admin@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/contacts', server: $server);
        self::assertSelectorTextNotContains('table', 'restricted@example.test');
        $client->submit($crawler->selectButton('Reveal full address')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=contact_data_access', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $crawler = $client->request('GET', '/admin/contacts', server: $server);
        $client->submit($crawler->selectButton('Reveal full address')->form(), [], $server);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('code', 'restricted@example.test');
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'contact.address.revealed' AND target_id = ?", [$contact])));
    }

    public function testInlineSmtpConfirmationRequiresPasswordMfaAndCsrfAndRotatesSession(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-owner-'.bin2hex(random_bytes(12));
        $smtpPassword = 'synthetic-smtp-'.bin2hex(random_bytes(12));
        $owner = $this->createPhase4User($db, 'owner@example.test', $password);
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $enrollment = $management->start($owner);
        $management->confirm($owner, $totp->code($enrollment->seed, intdiv(time(), 30) - 1), true, 'synthetic-session');
        $server = $this->phase4HttpsServer('2001:db8::132');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'owner@example.test', 'password' => $password, 'mfa_code' => $enrollment->recoveryCodes[0]]), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $config = ['host' => 'smtp.example.test', 'port' => '587', 'encryption' => 'starttls', 'username' => 'sender@example.test', 'password' => $smtpPassword, 'sender_address' => 'sender@example.test', 'sender_name' => 'Synthetic Sender', 'reply_to' => 'reply@example.test'];
        $before = $db->fetchAssociative('SELECT * FROM sender_identity');
        $crawler = $client->request('GET', '/admin/settings/mail', server: $server);
        self::assertSelectorNotExists('a[href*="reauthenticate?purpose=mail_configuration"]');
        self::assertSelectorExists('dialog[aria-label="Confirm Owner identity"]');
        $client->submit($crawler->selectButton('Save SMTP settings')->form($config + ['owner_password' => 'incorrect', 'owner_mfa_code' => $enrollment->recoveryCodes[1]]), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name=host][value="smtp.example.test"]');
        self::assertSelectorExists('input[name=username][value="sender@example.test"]');
        self::assertSelectorNotExists('input[type=password][value]');
        self::assertSame($before, $db->fetchAssociative('SELECT * FROM sender_identity'));
        $db->executeStatement('DELETE FROM login_throttle');
        $crawler = $client->request('GET', '/admin/settings/mail', server: $server);
        $client->submit($crawler->selectButton('Save SMTP settings')->form($config + ['owner_password' => $password, 'owner_mfa_code' => '']), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name=host][value="smtp.example.test"]');
        self::assertSelectorExists('input[name=username][value="sender@example.test"]');
        self::assertSelectorNotExists('input[type=password][value]');
        self::assertSame($before, $db->fetchAssociative('SELECT * FROM sender_identity'));
        $client->request('POST', '/admin/settings/mail/update', $config + ['_token' => 'invalid', 'owner_password' => $password, 'owner_mfa_code' => $enrollment->recoveryCodes[1]], server: $server);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $db->fetchAssociative('SELECT * FROM sender_identity'));
        $sessionBefore = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($sessionBefore);
        $db->executeStatement('DELETE FROM login_throttle');
        $crawler = $client->request('GET', '/admin/settings/mail', server: $server);
        $client->submit($crawler->selectButton('Save SMTP settings')->form($config + ['owner_password' => $password, 'owner_mfa_code' => $enrollment->recoveryCodes[1]]), [], $server);
        self::assertResponseRedirects('/admin/settings/mail', 303);
        $sessionAfter = $client->getCookieJar()->get('EMAILERSESSID');
        self::assertNotNull($sessionAfter);
        self::assertNotSame($sessionBefore->getValue(), $sessionAfter->getValue());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'SMTP settings saved.');
        self::assertSelectorExists('dialog');
        self::assertSelectorExists('form[data-mail-settings-confirmation-expires-at-value]');
        self::assertSelectorExists('input[name="username"][value="sender@example.test"]');
        self::assertSame('smtp.example.test', $this->phase4Service(MailConfigurationManager::class)->view($owner)['smtp_host']);
        $response = $client->getResponse()->getContent();
        self::assertIsString($response);
        $audit = json_encode($db->fetchAllAssociative('SELECT * FROM audit_event WHERE actor_user_id = ?', [$owner->id()]), JSON_THROW_ON_ERROR);
        foreach ([$password, $smtpPassword, $enrollment->seed, $enrollment->recoveryCodes[1]] as $secret) {
            self::assertStringNotContainsString($secret, $response);
            self::assertStringNotContainsString($secret, $audit);
        }
        $session = $client->getRequest()->getSession();
        $session->set('identity.reauthenticated_at', time() - 301);
        $session->save();
        $client->submit($client->getCrawler()->selectButton('Save SMTP settings')->form($config), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-mail-connection-result]', 'Owner identity confirmation failed');
        self::assertSame(0, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authentication.reauthentication' AND result = 'failure' AND occurred_at > (SELECT MAX(occurred_at) FROM audit_event WHERE action = 'mail.configuration.updated')")));
        self::assertSelectorExists('dialog');
        self::assertSelectorExists('form[data-mail-settings-confirmation-expires-at-value="0"]');
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery')));
    }

    public function testNewsletterComposerKeepsRejectedInputAndProgressIsPrivateAndReadOnly(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-password-'.bin2hex(random_bytes(12));
        $owner = $this->createPhase4User($db, 'composer@example.test', $password);
        $contacts = $this->phase4Service(ContactManager::class);
        $list = $contacts->createList($owner, 'Composer recipients');
        $contact = $contacts->create($owner, 'private@example.test', ['consent_source' => 'synthetic']);
        $contacts->addMembership($owner, $list, $contact);
        $templates = $this->phase4Service(TemplateManager::class);
        $template = $templates->create($owner, 'Composer content', 'Synthetic subject', '', '<p>Hello</p>', 'Hello');
        $version = $this->phase4String($templates->versions($owner, $template)[0]['id']);
        $server = $this->phase4HttpsServer('2001:db8::102');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'composer@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/campaigns', server: $server);
        self::assertSelectorExists('[data-campaign-editor]');
        $client->submit($crawler->selectButton('Save draft')->form(['name' => 'Keep my work', 'template_version' => $version, 'messages_per_minute' => '0', 'audience_mode' => 'existing', 'lists' => [$list]]), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="name"][value="Keep my work"]');
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign')));
        $client->submit($client->getCrawler()->selectButton('Save draft')->form(['lists' => [$list], 'messages_per_minute' => '20']), [], $server);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        $editPath = $client->getRequest()->getPathInfo();
        $campaignId = basename(\dirname($editPath));
        $client->request('GET', '/admin/campaigns/'.$campaignId, server: $server);
        self::assertSelectorExists('progress[max="100"][value="0"]');
        self::assertSelectorTextContains('main', '20');
        $url = $client->getRequest()->getPathInfo();
        $client->request('GET', $url.'/progress', server: $server);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('private@example.test', $content);
        self::assertStringContainsString('"total":0', $content);
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_recipient')));
        $client->request('GET', $url.'/edit', server: $server);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="messages_per_minute"][value="20"]');
        $client->submit($client->getCrawler()->selectButton('Save draft')->form(['name' => 'Retain this edit', 'messages_per_minute' => '0']), [], $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="name"][value="Retain this edit"]');
        $client->request('GET', '/admin/campaigns/00000000-0000-0000-0000-000000000000/edit', server: $server);
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', '/admin/campaigns', server: $server);
        $token = $crawler->filter('form[data-campaign-editor] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/campaigns/create', ['_token' => $token, 'name' => ['unexpected'], 'template_version' => ['unexpected'], 'html' => ['unexpected'], 'lists' => 'unexpected'], server: $server);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign')));
    }

    public function testOwnerDeliveryLimitsRequireConfirmationAndPreserveRuntimeBudget(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-password-'.bin2hex(random_bytes(12));
        $this->createPhase4User($db, 'limits@example.test', $password);
        $server = $this->phase4HttpsServer('2001:db8::103');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'limits@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/settings/delivery', server: $server);
        self::assertResponseIsSuccessful();
        $before = $db->fetchAssociative('SELECT * FROM delivery_policy');
        $budget = $db->fetchAssociative('SELECT * FROM delivery_runtime');
        $fields = ['messages_per_minute' => '30', 'worker_concurrency' => '2', 'retry_limit' => '3', 'maximum_campaign_size' => '500'];
        $client->submit($crawler->selectButton('Save delivery limits')->form($fields), [], $server);
        self::assertResponseRedirects('/admin/settings/delivery', 303);
        self::assertSame($before, $db->fetchAssociative('SELECT * FROM delivery_policy'));
        $crawler = $client->request('GET', '/admin/settings/delivery', server: $server);
        $client->submit($crawler->selectButton('Save delivery limits')->form($fields + ['owner_password' => $password]), [], $server);
        self::assertResponseRedirects('/admin/settings/delivery', 303);
        self::assertSame(30, $this->phase4Int($db->fetchOne('SELECT messages_per_minute FROM delivery_policy')));
        self::assertSame($budget, $db->fetchAssociative('SELECT * FROM delivery_runtime'));
        $client->request('POST', '/admin/settings/delivery/update', $fields + ['_token' => 'invalid'], server: $server);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(30, $this->phase4Int($db->fetchOne('SELECT messages_per_minute FROM delivery_policy')));
    }
}
