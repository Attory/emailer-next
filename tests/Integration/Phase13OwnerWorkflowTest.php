<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\Contract\CampaignAudiencePreparation;
use App\Module\Contacts\Application\ImportManager;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Suppression\Application\SuppressionManager;
use App\Module\Templates\Application\TemplateManager;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Group('integration')]
final class Phase13OwnerWorkflowTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testOwnerVisibilityAndAddressCorrectionPreserveUnsubscribeAndArchivedState(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'owner@example.test', 'synthetic-owner-'.bin2hex(random_bytes(12)));
        $admin = $this->createPhase4User($db, 'admin@example.test', 'synthetic-admin-'.bin2hex(random_bytes(12)), AdministrativeRole::Administrator);
        $contacts = $this->phase4Service(ContactManager::class);
        $suppressions = $this->phase4Service(SuppressionManager::class);
        $mail = $this->phase4Service(MailConfigurationManager::class);
        $id = $contacts->create($owner, 'Original.Person@example.test', ['consent_source' => 'synthetic']);
        $suppressions->add($owner, 'Original.Person@example.test', 'unsubscribe');
        self::assertSame('Original.Person@example.test', $contacts->list($owner)[0]['email_display']);
        self::assertSame('O***@e***.test', $contacts->list($admin)[0]['email_display']);
        self::assertSame('Original.Person@example.test', $suppressions->list($owner)[0]['email_display']);
        self::assertNotSame('original.person@example.test', $suppressions->list($admin)[0]['email_display']);
        $mail->configureSecureSmtp($owner, 'smtp.example.test', 587, 'starttls', 'sender@example.test', 'synthetic-smtp-'.bin2hex(random_bytes(12)), 'sender@example.test', 'Synthetic sender', 'reply@example.test');
        self::assertSame('sender@example.test', $mail->view($owner)['smtp_username']);
        self::assertSame('reply@example.test', $mail->view($owner)['reply_to_address']);
        self::assertNotSame('sender@example.test', $mail->view($admin)['smtp_username']);
        self::assertArrayNotHasKey('credential_secret_id', $mail->view($owner));
        $contacts->update($owner, $id, 'corrected@example.test', ['consent_source' => 'synthetic']);
        self::assertTrue($suppressions->isSuppressed('Original.Person@example.test'));
        self::assertTrue($suppressions->isSuppressed('corrected@example.test'));
        $contacts->create($owner, 'occupied@example.test', ['consent_source' => 'synthetic']);
        try {
            $contacts->update($owner, $id, 'occupied@example.test', ['consent_source' => 'synthetic']);
            self::fail('Duplicate address accepted.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertFalse($suppressions->isSuppressed('occupied@example.test'));
            self::assertSame('corrected@example.test', $db->fetchOne('SELECT email_canonical FROM contact WHERE id = ?', [$id]));
        }
        $contacts->archive($owner, $id);
        self::assertSame('archived', $contacts->contactForEdit($owner, $id)['status']);
        $contacts->update($owner, $id, 'archived-correction@example.test', ['consent_source' => 'synthetic', 'display_name' => 'Corrected profile']);
        self::assertSame('archived', $db->fetchOne('SELECT status FROM contact WHERE id = ?', [$id]));
        self::assertNotNull($db->fetchOne('SELECT archived_at FROM contact WHERE id = ?', [$id]));
        self::assertTrue($suppressions->isSuppressed('archived-correction@example.test'));
        try {
            $contacts->contactForEdit($admin, $id);
            self::fail('Non-Owner obtained an unmasked editor.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $audit = json_encode($db->fetchAllAssociative('SELECT * FROM audit_event'), JSON_THROW_ON_ERROR);
        foreach (['Original.Person', 'corrected@example.test', 'smtp.example.test', 'sender@example.test', 'reply@example.test'] as $private) {
            self::assertStringNotContainsString($private, $audit);
        }
    }

    public function testListPagingSearchDuplicateAndIndependentMembershipBeyondOldLimit(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'owner@example.test', 'synthetic-owner-'.bin2hex(random_bytes(12)));
        $operator = $this->createPhase4User($db, 'operator@example.test', 'synthetic-operator-'.bin2hex(random_bytes(12)), AdministrativeRole::Operator);
        $contacts = $this->phase4Service(ContactManager::class);
        $imports = $this->phase4Service(ImportManager::class);
        $list = $contacts->createList($owner, 'Synthetic list');
        $source = tempnam(sys_get_temp_dir(), 'emailer-owner-list-');
        self::assertIsString($source);
        $csv = "email,name\n";
        for ($i = 0; $i < 501; ++$i) {
            $csv .= \sprintf("member%04d@example.test,Member %04d\n", $i, $i);
        }
        file_put_contents($source, $csv);
        try {
            $upload = $imports->upload($owner, $list, 'headered', $source);
            $imports->preview($owner, $upload, ['email' => 0, 'display_name' => 1]);
            $imports->commit($owner, $upload);
        } finally {
            unlink($source);
        }
        $last = $contacts->listPage($owner, $list, 6);
        self::assertSame(501, $last['total']);
        self::assertCount(1, $last['rows']);
        self::assertSame('member0500@example.test', $last['rows'][0]['email_display']);
        self::assertSame(1, $contacts->listPage($owner, $list, query: 'member0500@example.test')['total']);
        self::assertSame(0, $contacts->listPage($operator, $list, query: 'member0500@example.test')['total']);
        self::assertSame(0, $contacts->listPage($owner, $list, query: '%')['total']);
        self::assertSame(0, $contacts->listPage($owner, $list, available: true)['total']);
        try {
            $contacts->duplicateList($owner, $list, '');
            self::fail('A new duplicate name was not required.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM contact_list'));
        }
        try {
            $contacts->duplicateList($owner, $list, 'Synthetic list');
            self::fail('An existing list name was accepted.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::addToAssertionCount(1);
        }
        $copy = $contacts->duplicateList($owner, $list, 'Synthetic copy');
        $contacts->renameList($owner, $copy, 'Renamed copy');
        self::assertSame(501, $this->phase4Int($contacts->listInfo($owner, $copy)['member_count']));
        self::assertSame(501, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact')));
        $member = $this->phase4String($last['rows'][0]['id']);
        $contacts->removeMembership($owner, $copy, $member);
        self::assertSame(500, $this->phase4Int($contacts->listInfo($owner, $copy)['member_count']));
        self::assertSame(501, $this->phase4Int($contacts->listInfo($owner, $list)['member_count']));
        $contacts->addMembership($owner, $copy, $member);
        self::assertSame(501, $this->phase4Int($contacts->listInfo($owner, $copy)['member_count']));
        self::assertStringNotContainsString('member0500@example.test', json_encode($db->fetchAllAssociative('SELECT * FROM audit_event'), JSON_THROW_ON_ERROR));
    }

    public function testTemplateEditsAndCopiesKeepExistingPublishedContentImmutable(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'owner@example.test', 'synthetic-owner-'.bin2hex(random_bytes(12)));
        $templates = $this->phase4Service(TemplateManager::class);
        $id = $templates->create($owner, 'Original', 'Subject', 'Preview', '<p>Hello {{ contact.first_name }}</p>', 'Hello {{ contact.first_name }}');
        $first = $templates->versions($owner, $id)[0];
        $templates->publish($owner, $id, $this->phase4String($first['id']));
        $list = $this->phase4Service(ContactManager::class)->createList($owner, 'Existing audience');
        $campaigns = $this->phase4Service(CampaignManager::class);
        $member = $this->phase4Service(ContactManager::class)->create($owner, 'snapshot@example.test', ['consent_source' => 'synthetic']);
        $this->phase4Service(ContactManager::class)->addMembership($owner, $list, $member);
        $draft = $campaigns->prepareDraft($owner, 'Existing published newsletter', $this->phase4String($first['id']), [$list]);
        $campaigns->submitForReview($owner, $draft['id']);
        $stored = $db->fetchAssociative('SELECT * FROM template_version WHERE id = ?', [$first['id']]);
        $edited = $templates->edit($owner, $id, 'Edited', 'New subject', 'New preview', '<p>New content</p>', 'New content');
        self::assertNotSame($first['id'], $edited);
        self::assertSame($stored, $db->fetchAssociative('SELECT * FROM template_version WHERE id = ?', [$first['id']]));
        self::assertSame('published', $db->fetchOne('SELECT status FROM email_template WHERE id = ?', [$id]));
        self::assertSame($first['id'], $db->fetchOne('SELECT published_version_id FROM email_template WHERE id = ?', [$id]));
        self::assertCount(2, $templates->versions($owner, $id));
        try {
            $templates->duplicate($owner, $id, '');
            self::fail('A duplicate template name was not required.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $templates->duplicate($owner, $id, 'Edited');
            self::fail('An existing template name was accepted.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::addToAssertionCount(1);
        }
        $copy = $templates->duplicate($owner, $id, 'Template copy');
        self::assertCount(1, $templates->versions($owner, $copy));
        self::assertSame('New subject', $templates->draftInfo($owner, $copy)['subject']);
        self::assertSame('draft', $db->fetchOne('SELECT status FROM email_template WHERE id = ?', [$copy]));
        self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign')));
        self::assertSame('review', $campaigns->detail($owner, $draft['id'])['state']);
        $published = $this->phase4Service(\App\Module\Templates\Application\Contract\PublishedTemplateProvider::class)->get($this->phase4String($first['id']));
        self::assertSame('Subject', $published['subject']);
        $campaigns->approve($owner, $draft['id'], $this->phase4Int($campaigns->detail($owner, $draft['id'])['operation_version']), true);
        self::assertSame('Subject', $db->fetchOne('SELECT subject FROM campaign_content_snapshot WHERE campaign_id = ?', [$draft['id']]));
        self::assertSame('approved', $campaigns->detail($owner, $draft['id'])['state']);
        self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery')));
    }

    public function testOversizedCsvIsRejectedAndPreparedBusinessStateRollsBack(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'owner@example.test', 'synthetic-owner-'.bin2hex(random_bytes(12)));
        $source = tempnam(sys_get_temp_dir(), 'emailer-oversize-');
        self::assertIsString($source);
        $stream = fopen($source, 'w+b');
        self::assertIsResource($stream);
        ftruncate($stream, 10485761);
        fclose($stream);
        try {
            $this->phase4Service(CampaignAudiencePreparation::class)->prepareImport($owner, 'Oversized upload', $source, 'headered', ['email' => 0]);
            self::fail('Oversized CSV accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact_list')));
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact_import')));
        } finally {
            unlink($source);
        }
    }

    public function testCombinedUploadsArePreviewedAtomicOwnedAndNeverSend(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $owner = $this->createPhase4User($db, 'owner@example.test', 'synthetic-owner-'.bin2hex(random_bytes(12)));
        $other = $this->createPhase4User($db, 'other@example.test', 'synthetic-other-'.bin2hex(random_bytes(12)), AdministrativeRole::Operator);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $uploads = $this->phase4Service(CampaignAudiencePreparation::class);
        $this->phase4Service(SuppressionManager::class)->add($owner, 'blocked@example.test', 'unsubscribe');
        $source = tempnam(sys_get_temp_dir(), 'emailer-campaign-upload-');
        self::assertIsString($source);
        file_put_contents($source, "email,name\nvalid@example.test,Valid\nvalid@example.test,Duplicate\nblocked@example.test,Blocked\ninvalid,Invalid\n");
        $content = ['name' => 'Uploaded HTML', 'subject' => 'Synthetic newsletter', 'preheader' => 'Preview', 'html' => '<p>Hello {{ contact.display_name }}. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>'];
        $audience = ['name' => 'Uploaded CSV', 'source' => $source, 'mode' => 'headered', 'mapping' => ['email' => 0, 'display_name' => 1]];
        try {
            try {
                $campaigns->prepareDraft($owner, '', '', [], $content, $audience);
                self::fail('Invalid campaign was accepted.');
            } catch (\InvalidArgumentException) {
                foreach (['contact_list', 'contact_import', 'contact_import_candidate', 'email_template', 'template_version', 'campaign'] as $table) {
                    self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM '.$table)));
                }
            }
            $draft = $campaigns->prepareDraft($owner, 'Combined newsletter', '', [], $content, $audience);
            self::assertIsString($draft['import_id']);
            $summary = $uploads->importSummary($owner, $draft['import_id']);
            self::assertSame('previewed', $summary['status']);
            foreach (['valid_count', 'duplicate_count', 'suppressed_count', 'invalid_count'] as $count) {
                self::assertSame(1, $this->phase4Int($summary[$count]));
            }
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM list_membership')));
            self::assertSame('draft', $campaigns->detail($owner, $draft['id'])['state']);
            try {
                $campaigns->commitAudienceUpload($other, $draft['id'], $draft['import_id']);
                self::fail('Another actor committed a private upload.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            $otherList = $this->phase4Service(ContactManager::class)->createList($owner, 'Unrelated list');
            $version = $this->phase4String($db->fetchOne('SELECT template_version_id FROM campaign WHERE id = ?', [$draft['id']]));
            $otherDraft = $campaigns->create($owner, 'Unrelated draft', $version, [$otherList]);
            try {
                $campaigns->commitAudienceUpload($owner, $otherDraft, $draft['import_id']);
                self::fail('Upload committed to an unrelated draft.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            $campaigns->commitAudienceUpload($owner, $draft['id'], $draft['import_id']);
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM list_membership')));
            self::assertFalse($db->fetchOne('SELECT id FROM contact WHERE email_canonical = ?', ['blocked@example.test']));
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery')));
            self::assertSame(0, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM campaign_recipient')));
        } finally {
            unlink($source);
        }
    }
}
