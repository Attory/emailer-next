<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignApprovedSummaryHttpTest extends WebTestCase
{
    use Phase4State;

    public function testApprovedListAudienceSurvivesTemplateSettingsAndListEdits(): void
    {
        $this->verifySummary(false);
    }

    public function testApprovedUploadedAudienceAndAbsentReplyToRemainFrozen(): void
    {
        $this->verifySummary(true);
    }

    private function verifySummary(bool $uploaded): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = $this->phase4Service(Connection::class);
        $db->beginTransaction();
        $this->preparePhase4State($db);
        if (false === $db->fetchOne("SELECT 1 FROM privacy_policy WHERE singleton_key='1'")) {
            $db->insert('privacy_policy', ['singleton_key' => '1', 'open_tracking' => 0, 'click_tracking' => 0,
                'rsvp_enabled' => 0, 'operational_retention_days' => 30, 'delivery_retention_days' => 90,
                'suppression_mode' => 'global', 'unsubscribe_footer' => 'Unsubscribe: {{ unsubscribe_url }}']);
        }
        $displayZone = $uploaded ? 'Australia/Sydney' : 'UTC';
        $db->update('organisation_settings', ['canonical_base_url' => 'https://emailer.example.test', 'time_zone' => $displayZone], ['singleton_key' => '1']);
        $password = 'Synthetic operator phrase '.bin2hex(random_bytes(12));
        $owner = $this->createPhase4User($db, 'summary-owner@example.test', 'Synthetic owner phrase '.bin2hex(random_bytes(12)));
        $operator = $this->createPhase4User($db, 'summary-operator@example.test', $password, AdministrativeRole::Operator);
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'emailer.example.test', 'REMOTE_ADDR' => '198.51.100.71'];
        $csv = sys_get_temp_dir().'/summary-'.bin2hex(random_bytes(8)).'.csv';
        try {
            $db->update('sender_identity', ['sender_name' => 'Approved sender <original>', 'sender_address' => 'approved-sender@example.test', 'reply_to_address' => $uploaded ? null : 'approved-replies@example.test'], ['singleton_key' => '1']);
            $templates = $this->phase4Service(TemplateManager::class);
            $template = $templates->create($owner, 'Original template name', 'Approved subject <original>', '', '<p>Synthetic approved content</p>', 'Synthetic approved content');
            $version = $this->phase4String($templates->versions($owner, $template)[0]['id']);
            $templates->publish($owner, $template, $version);
            $list = Identity::uuidV7();
            $now = Identity::now();
            $db->insert('contact_list', ['id' => $list, 'name' => 'Synthetic audience', 'created_at' => $now, 'updated_at' => $now]);
            foreach (['first-recipient@example.test', 'second-recipient@example.test'] as $address) {
                $contact = Identity::uuidV7();
                $db->insert('contact', ['id' => $contact, 'email_entered' => $address, 'email_canonical' => $address, 'display_name' => 'Synthetic recipient', 'first_name' => 'Synthetic', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'synthetic-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
                $db->insert('list_membership', ['contact_id' => $contact, 'list_id' => $list, 'source' => 'synthetic-test', 'created_at' => $now]);
            }
            $campaigns = $this->phase4Service(CampaignManager::class);
            file_put_contents($csv, "email,first_name\nfirst-recipient@example.test,First\nsecond-recipient@example.test,Second\n");
            $saved = $campaigns->saveDraft($owner, null, 'Approved summary fixture', $version, 17, $uploaded ? $csv : null, $uploaded ? [] : [$list], $uploaded);
            $id = $saved['id'];
            $client->request('GET', '/admin/campaigns/'.$id, server: $server);
            self::assertResponseRedirects('/login');
            $login = $client->request('GET', '/login', server: $server);
            $client->request('POST', '/login', ['login_identifier' => $operator->getUserIdentifier(), 'password' => $password, '_token' => $login->filter('[name="_token"]')->attr('value')], server: $server);
            self::assertResponseStatusCodeSame(303);
            $draft = $client->request('GET', '/admin/campaigns/'.$id, server: $server);
            self::assertResponseIsSuccessful();
            self::assertSame(0, $draft->filter('[data-approved-subject]')->count());

            // Synthetic Administrator/Owner approval with the existing explicit test waiver.
            // No transport or campaign queue is invoked.
            $campaigns->submitForReview($owner, $id);
            $campaigns->approve($owner, $id, $this->phase4Int($campaigns->detail($owner, $id)['operation_version']), true);
            $before = $db->fetchAssociative('SELECT * FROM campaign_content_snapshot WHERE campaign_id=?', [$id]);
            $audienceBefore = $db->fetchAssociative('SELECT * FROM campaign_audience_snapshot WHERE campaign_id=?', [$id]);
            $approvedAt = new \DateTimeImmutable($this->phase4String($db->fetchOne('SELECT approved_at FROM campaign WHERE id=?', [$id])), new \DateTimeZone('UTC'));
            $expectedApproval = $approvedAt->setTimezone(new \DateTimeZone($displayZone))->format('j M Y, g:i a').' ('.$displayZone.')';

            // Publish later content and change the editable template name/settings/list.
            $newVersion = $templates->addVersion($owner, $template, 'Later editable subject', '', '<p>Later editable content</p>', 'Later editable content');
            $templates->publish($owner, $template, $newVersion);
            $db->update('email_template', ['name' => 'Later editable template name'], ['id' => $template]);
            $db->update('sender_identity', ['sender_name' => 'Later installation sender', 'sender_address' => 'later-sender@example.test', 'reply_to_address' => 'later-replies@example.test'], ['singleton_key' => '1']);
            $db->update('delivery_policy', ['messages_per_minute' => 13], ['singleton_key' => '1']);
            $db->delete('list_membership', ['list_id' => $list]);
            $db->update('contact_list', ['archived_at' => Identity::now()], ['id' => $list]);
            $db->update('contact', ['email_entered' => 'changed-contact@example.test', 'email_canonical' => 'changed-contact@example.test'], ['email_canonical' => 'first-recipient@example.test']);
            $db->insert('suppression', ['id' => Identity::uuidV7(), 'email_canonical' => 'FIRST-RECIPIENT@example.test', 'reason' => 'manual', 'source' => 'synthetic-test', 'created_at' => Identity::now()]);
            $db->insert('suppression', ['id' => Identity::uuidV7(), 'email_canonical' => 'not-in-approved-audience@example.test', 'reason' => 'manual', 'source' => 'synthetic-test', 'created_at' => Identity::now()]);

            foreach (['approved', 'paused', 'completed', 'cancelled'] as $state) {
                $db->update('campaign', ['state' => $state], ['id' => $id]);
                $page = $client->request('GET', '/admin/campaigns/'.$id, server: $server);
                self::assertResponseIsSuccessful();
                self::assertSame('Approved summary fixture', $page->filter('h1')->text());
                self::assertSame(ucfirst($state), $page->filter('[data-campaign-state]')->text());
                self::assertSame($id, $page->filter('[data-campaign-id]')->text());
                self::assertSame('Approved subject <original>', $page->filter('[data-approved-subject]')->text());
                self::assertSame('Approved sender <original> <approved-sender@example.test>', $page->filter('[data-approved-sender]')->text());
                self::assertSame($uploaded ? 'Not set' : 'approved-replies@example.test', $page->filter('[data-approved-reply-to]')->text());
                self::assertSame('2', $page->filter('[data-approved-recipient-count]')->text());
                self::assertSame('1', $page->filter('[data-approved-current-suppressed]')->text());
                self::assertSame('1', $page->filter('[data-approved-current-unsuppressed]')->text());
                self::assertSame('13', $page->filter('[data-configured-rate]')->text());
                self::assertSame($expectedApproval, $page->filter('[data-approved-at]')->text());
                self::assertSame(0, $page->filter('form[action$="/approve"]')->count());
                $html = (string) $client->getResponse()->getContent();
                foreach (['Later editable subject', 'Later editable template name', 'Later installation sender', 'later-sender@example.test', 'later-replies@example.test', 'first-recipient@example.test', 'second-recipient@example.test', 'changed-contact@example.test', 'not-in-approved-audience@example.test'] as $privateOrCurrent) {
                    self::assertStringNotContainsString($privateOrCurrent, $html);
                }
                self::assertStringContainsString('Approved subject &lt;original&gt;', $html);
                if ('approved' === $state) {
                    self::assertSame(1, $page->filter('form[action$="/queue"] [name="_token"]')->count());
                    self::assertSame(1, $page->filter('form[action$="/schedule"] [name="_token"]')->count());
                    $output = getenv('EMAILER_SUMMARY_BROWSER_OUTPUT');
                    if (!$uploaded && \is_string($output) && '' !== $output) {
                        // Synthetic authenticated response only; no real account/session/recipient data.
                        $html = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html) ?? $html;
                        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');
                        file_put_contents($output, str_replace('</head>', '<style>'.$css.'</style></head>', $html));
                    }
                }
            }
            self::assertSame($before, $db->fetchAssociative('SELECT * FROM campaign_content_snapshot WHERE campaign_id=?', [$id]));
            self::assertSame($audienceBefore, $db->fetchAssociative('SELECT * FROM campaign_audience_snapshot WHERE campaign_id=?', [$id]));
            self::assertSame('high', $db->fetchOne('SELECT security_level FROM organisation_settings'));
            foreach (['campaign_test_delivery', 'messenger_messages', 'campaign_recipient', 'delivery_attempt'] as $table) {
                self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM '.$table)));
            }
        } finally {
            $db->rollBack();
            if (is_file($csv)) {
                unlink($csv);
            }
            $this->removePhase4Files();
        }
    }
}
