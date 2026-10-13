<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Contacts\Application\ContactExporter;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\Contract\ImportStorage;
use App\Module\Contacts\Application\ImportManager;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Suppression\Application\SuppressionManager;
use App\Module\Templates\Application\Contract\TemplateAssetStorage;
use App\Module\Templates\Application\TemplateAssetManager;
use App\Module\Templates\Application\TemplateManager;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Group('integration')]
final class Phase5ContentDataTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testContactAddressesAreMaskedAndFullDataActionsAreAdministratorOnlyAndAudited(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase5-contacts-'.bin2hex(random_bytes(10));
        $operator = $this->createPhase4User($connection, 'operator@example.test', $password, AdministrativeRole::Operator);
        $administrator = $this->createPhase4User($connection, 'admin@example.test', $password, AdministrativeRole::Administrator);
        $auditor = $this->createPhase4User($connection, 'auditor@example.test', $password, AdministrativeRole::Auditor);
        $contacts = $this->phase4Service(ContactManager::class);
        $contacts->defineField($administrator, 'customer_since', 'Customer since', 'date', null);

        $contactId = $contacts->create($operator, 'Sensitive.Person+Tag@EXAMPLE.TEST', [
            'display_name' => '=Spreadsheet Formula',
            'first_name' => 'Sensitive',
            'last_name' => 'Person',
            'locale' => 'en_AU',
            'time_zone' => 'Australia/Sydney',
            'consent_source' => 'manual test',
        ], ['customer_since' => '2026-08-17']);
        self::assertSame('2026-08-17', $connection->fetchOne('SELECT value_text FROM contact_attribute WHERE contact_id = ? AND field_key = ?', [$contactId, 'customer_since']));
        try {
            $contacts->create($operator, 'invalid-date@example.test', ['consent_source' => 'manual'], ['customer_since' => '2026-02-31']);
            self::fail('An impossible custom-field date was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertFalse($connection->fetchOne('SELECT 1 FROM contact WHERE email_canonical = ?', ['invalid-date@example.test']));
        $listed = $contacts->list($operator);
        self::assertCount(1, $listed);
        self::assertSame('S***@E***.TEST', $listed[0]['email_display']);
        self::assertArrayNotHasKey('email_entered', $listed[0]);
        self::assertArrayNotHasKey('email_canonical', $listed[0]);

        try {
            $contacts->list($auditor);
            self::fail('Auditor was able to enumerate contacts.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        try {
            $contacts->reveal($operator, $contactId);
            self::fail('Operator was able to reveal a full address.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $deniedStream = fopen('php://temp', 'w+b');
        self::assertIsResource($deniedStream);
        try {
            $this->phase4Service(ContactExporter::class)->export($operator, $deniedStream);
            self::fail('Operator was able to export full addresses.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        } finally {
            fclose($deniedStream);
        }
        self::assertSame('Sensitive.Person+Tag@EXAMPLE.TEST', $contacts->reveal($administrator, $contactId));

        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        self::assertSame(1, $this->phase4Service(ContactExporter::class)->export($administrator, $stream));
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($csv);
        self::assertStringContainsString('Sensitive.Person+Tag@EXAMPLE.TEST', $csv);
        self::assertStringContainsString("'=Spreadsheet Formula", $csv);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'contact.address.revealed' AND actor_user_id = ?", [$administrator->id()])));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'contact.exported' AND actor_user_id = ?", [$administrator->id()])));
        self::assertStringNotContainsString('Sensitive.Person', json_encode($connection->fetchAllAssociative('SELECT * FROM audit_event WHERE actor_user_id = ?', [$administrator->id()]), JSON_THROW_ON_ERROR));
    }

    public function testImportPreviewCommitSuppressionDuplicatesAndPrivateReport(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $operator = $this->createPhase4User($connection, 'importer@example.test', 'phase5-import-'.bin2hex(random_bytes(10)), AdministrativeRole::Operator);
        $contacts = $this->phase4Service(ContactManager::class);
        $suppressions = $this->phase4Service(SuppressionManager::class);
        $imports = $this->phase4Service(ImportManager::class);
        $storage = $this->phase4Service(ImportStorage::class);
        $listId = $contacts->createList($operator, 'Phase 5 import list');
        $suppressionId = $suppressions->add($operator, 'blocked@example.test', 'manual');
        self::assertSame($suppressionId, $suppressions->add($operator, 'blocked@example.test', 'manual'));

        $source = tempnam(sys_get_temp_dir(), 'emailer-phase5-import-');
        self::assertIsString($source);
        file_put_contents($source, "\xEF\xBB\xBFemail,display,locale,time_zone\nvalid@example.test,Valid Person,en_AU,Australia/Sydney\nvalid@example.test,Duplicate,en_AU,Australia/Sydney\nblocked@example.test,Blocked,en_AU,Australia/Sydney\nnot-an-address,Invalid,en_AU,Australia/Sydney\nprofile@example.test,Profile,invalid locale,Australia/Sydney\nbad@example.test,\x01control,en_AU,Australia/Sydney\nutf8@example.test,\xC3\x28,en_AU,Australia/Sydney\n");
        try {
            $importId = $imports->upload($operator, $listId, 'headered', $source);
            $preview = $imports->preview($operator, $importId, ['email' => 0, 'display_name' => 1, 'locale' => 2, 'time_zone' => 3]);
            self::assertSame(7, $preview['total']);
            self::assertSame(1, $preview['valid']);
            self::assertSame(1, $preview['duplicate']);
            self::assertSame(1, $preview['suppressed']);
            self::assertSame(2, $preview['invalid']);
            self::assertSame(2, $preview['rejected']);

            $report = $imports->report($operator, $importId);
            $reportText = stream_get_contents($report);
            fclose($report);
            self::assertIsString($reportText);
            self::assertStringContainsString('row 3: duplicate in upload', $reportText);
            self::assertStringContainsString('row 7: rejected row content', $reportText);
            self::assertStringContainsString('row 8: rejected row content', $reportText);
            self::assertStringNotContainsString('@', $reportText);

            $committed = $imports->commit($operator, $importId);
            self::assertSame(1, $committed['valid']);
            self::assertSame(1, $committed['suppressed']);
            self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM list_membership WHERE list_id = ?', [$listId])));
            self::assertFalse($connection->fetchOne('SELECT 1 FROM contact WHERE email_canonical = ?', ['blocked@example.test']));
            $storage->deleteReport($this->phase4String($preview['report_token']));
        } finally {
            unlink($source);
        }
    }

    public function testTemplateVersionsRemainImmutablePreviewIsSyntheticAndRasterAssetsAreReencoded(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $operator = $this->createPhase4User($connection, 'templates@example.test', 'phase5-templates-'.bin2hex(random_bytes(10)), AdministrativeRole::Operator);
        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($operator, 'Welcome', 'Hello {{ contact.first_name }}', 'Preview', '<p>Hello <strong>{{ contact.display_name }}</strong>. <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>', 'Hello {{ contact.display_name }}');
        $first = $templates->versions($operator, $templateId)[0];
        $firstDigest = $first['content_sha256'];
        $secondId = $templates->addVersion($operator, $templateId, 'Updated', '', '<p>Updated content</p>', 'Updated content');
        $templates->publish($operator, $templateId, $secondId);
        $versions = $templates->versions($operator, $templateId);
        self::assertCount(2, $versions);
        self::assertSame($firstDigest, $connection->fetchOne('SELECT content_sha256 FROM template_version WHERE id = ?', [$first['id']]));
        self::assertSame($secondId, $connection->fetchOne('SELECT published_version_id FROM email_template WHERE id = ?', [$templateId]));
        self::assertNotNull($connection->fetchOne('SELECT published_at FROM template_version WHERE id = ?', [$secondId]));
        $preview = $templates->preview($operator, $this->phase4String($first['id']));
        self::assertStringContainsString('Alex Example', $this->phase4String($preview['html_body']));
        self::assertStringNotContainsString('@', $this->phase4String($preview['html_body']));
        $mailpitBefore = $this->mailpitCount();
        $templates->fakeTest($operator, $secondId);
        self::assertSame($mailpitBefore, $this->mailpitCount());
        $fakeAudit = json_decode($this->phase4String($connection->fetchOne("SELECT summary_json FROM audit_event WHERE action = 'template.test.validated' ORDER BY occurred_at DESC LIMIT 1")), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fakeAudit);
        self::assertSame('fake_only', $fakeAudit['transport'] ?? null);

        $imagePath = tempnam(sys_get_temp_dir(), 'emailer-phase5-image-');
        self::assertIsString($imagePath);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $imagePath);
        unset($image);
        try {
            $asset = $this->phase4Service(TemplateAssetManager::class)->store($operator, $imagePath);
            self::assertMatchesRegularExpression('#^/template-assets/[a-f0-9]{48}\.png$#', $asset['url']);
            self::assertSame(2, $asset['width']);
            self::assertSame(2, $asset['height']);
            $filename = basename($asset['url']);
            $storage = $this->phase4Service(TemplateAssetStorage::class);
            self::assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->file($storage->publicPath($filename)));
            $storage->delete($filename);
        } finally {
            unlink($imagePath);
        }
    }

    public function testHeaderlessLargeImportPreviewKeepsApplicationMemoryBounded(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $operator = $this->createPhase4User($connection, 'streaming@example.test', 'phase5-streaming-'.bin2hex(random_bytes(10)), AdministrativeRole::Operator);
        $listId = $this->phase4Service(ContactManager::class)->createList($operator, 'Streaming import list');
        $imports = $this->phase4Service(ImportManager::class);
        $source = tempnam(sys_get_temp_dir(), 'emailer-phase5-stream-');
        self::assertIsString($source);
        $stream = fopen($source, 'w+b');
        self::assertIsResource($stream);
        for ($row = 0; $row < 5_000; ++$row) {
            fputcsv($stream, ['stream-'.$row.'@example.test', 'Person '.$row], ',', '"', '');
        }
        fclose($stream);
        try {
            $importId = $imports->upload($operator, $listId, 'headerless', $source);
            $before = memory_get_usage(true);
            $preview = $imports->preview($operator, $importId, ['email' => 0, 'display_name' => 1]);
            $after = memory_get_usage(true);
            self::assertSame(5_000, $preview['total']);
            self::assertSame(5_000, $preview['valid']);
            self::assertLessThan(24 * 1024 * 1024, max(0, $after - $before));
            $this->phase4Service(ImportStorage::class)->deleteReport($this->phase4String($preview['report_token']));
        } finally {
            unlink($source);
        }
    }

    private function mailpitCount(): int
    {
        $contents = file_get_contents('http://mailpit:8025/api/v1/messages?limit=1');
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $total = $decoded['total'] ?? null;
        if (!\is_int($total)) {
            throw new \RuntimeException('Mailpit message count is unavailable.');
        }

        return $total;
    }
}
