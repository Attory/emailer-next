<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\ImportManager;
use App\Module\Contacts\Application\ListCsvExporter;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Suppression\Application\SuppressionManager;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('integration')]
final class ListCsvDownloadTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testSelectedListDownloadHasExactFieldsDeduplicatesAndQuotesNames(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-list-export-'.bin2hex(random_bytes(10));
        $admin = $this->createPhase4User($db, 'list-admin@example.test', $password, AdministrativeRole::Administrator);
        $contacts = $this->phase4Service(ContactManager::class);
        $list = $contacts->createList($admin, '../Quarter "One"; 📩');
        $otherList = $contacts->createList($admin, 'Unrelated');
        $first = $contacts->create($admin, 'Case.Member@EXAMPLE.TEST', ['first_name' => 'José, Ana', 'last_name' => 'O"Neil', 'consent_source' => 'synthetic-private-marker']);
        $duplicate = $contacts->create($admin, 'case.member@example.test', ['first_name' => 'Duplicate', 'consent_source' => 'synthetic']);
        $unrelated = $contacts->create($admin, 'unrelated@example.test', ['consent_source' => 'synthetic']);
        $db->executeStatement('UPDATE contact SET last_name = ? WHERE id = ?', ["O\"Neil\nSecond line", $first]);
        $contacts->addMembership($admin, $list, $first);
        $contacts->addMembership($admin, $list, $duplicate);
        $contacts->addMembership($admin, $otherList, $unrelated);

        $server = $this->phase4HttpsServer('2001:db8::221');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'list-admin@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        self::assertSelectorExists('form[action="/admin/lists/'.$list.'/download"]');
        $client->submit($crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=contact_data_access', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        self::assertResponseRedirects('/admin', 303);
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        $client->submit($crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="quarter-one-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.csv"$/', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $csv = $client->getInternalResponse()->getContent();
        self::assertSame((string) \strlen($csv), $response->headers->get('Content-Length'));
        self::assertStringStartsWith("email,first_name,last_name\r\n", $csv);
        self::assertStringContainsString("\"O\"\"Neil\nSecond line\"\r\n", $csv);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);
        self::assertSame(['email', 'first_name', 'last_name'], fgetcsv($stream, escape: ''));
        self::assertSame(['Case.Member@EXAMPLE.TEST', 'José, Ana', "O\"Neil\nSecond line"], fgetcsv($stream, escape: ''));
        self::assertFalse(fgetcsv($stream, escape: ''));
        fclose($stream);
        foreach (['unrelated@example.test', 'Duplicate', 'synthetic-private-marker', 'consent_source', 'email_canonical', 'suppression', 'audit', 'token'] as $private) {
            self::assertStringNotContainsString($private, $csv);
        }
        $event = $db->fetchAssociative("SELECT target_id, summary_json FROM audit_event WHERE action = 'contact.list.export.requested' ORDER BY occurred_at DESC LIMIT 1");
        self::assertIsArray($event);
        self::assertSame($list, $event['target_id']);
        $summary = $event['summary_json'];
        self::assertIsString($summary);
        self::assertStringNotContainsString('@', $summary);
        self::assertStringNotContainsString('José', $summary);
        self::assertSame(1, $this->phase4Int($db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'contact.list.export.generated' AND target_id = ?", [$list])));
    }

    public function testFormulaGuardRestoresEmailAndNamesBeforeImportSuppressionChecks(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-formula-export-'.bin2hex(random_bytes(10));
        $admin = $this->createPhase4User($db, 'list-admin@example.test', $password, AdministrativeRole::Administrator);
        $contacts = $this->phase4Service(ContactManager::class);
        $source = $contacts->createList($admin, 'Formula list');
        $target = $contacts->createList($admin, 'Reimport target');
        $protectedEmail = $contacts->create($admin, '+leading@example.test', ['first_name' => '=Formula', 'consent_source' => 'synthetic']);
        $ordinaryEmail = $contacts->create($admin, 'ordinary@example.test', ['first_name' => '+First', 'last_name' => '-Last', 'consent_source' => 'synthetic']);
        $contacts->addMembership($admin, $source, $protectedEmail);
        $contacts->addMembership($admin, $source, $ordinaryEmail);
        $this->phase4Service(SuppressionManager::class)->add($admin, '+leading@example.test');
        $server = $this->phase4HttpsServer('2001:db8::224');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'list-admin@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=contact_data_access', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        $client->submit($crawler->filter('form[action="/admin/lists/'.$source.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseIsSuccessful();
        $csv = $client->getInternalResponse()->getContent();
        self::assertStringContainsString("'EMAILER_CSV_SAFE_V1:+leading@example.test", $csv);
        self::assertStringContainsString("'EMAILER_CSV_SAFE_V1:=Formula", $csv);
        self::assertStringContainsString("'EMAILER_CSV_SAFE_V1:+First", $csv);
        $file = tempnam(sys_get_temp_dir(), 'emailer-list-reimport-');
        self::assertIsString($file);
        file_put_contents($file, $csv);
        try {
            $imports = $this->phase4Service(ImportManager::class);
            $id = $imports->upload($admin, $target, 'headered', $file);
            $counts = $imports->preview($admin, $id, ['email' => 0, 'first_name' => 1, 'last_name' => 2]);
            self::assertSame(1, $counts['suppressed']);
            self::assertSame(1, $counts['valid']);
            $candidate = $db->fetchAssociative('SELECT email_entered, profile_json FROM contact_import_candidate WHERE import_id = ? AND email_entered = ?', [$id, 'ordinary@example.test']);
            self::assertIsArray($candidate);
            $profile = json_decode($this->phase4String($candidate['profile_json']), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($profile);
            self::assertSame('+First', $profile['first_name']);
            self::assertSame('-Last', $profile['last_name']);
            self::assertSame(1, $this->phase4Int($db->fetchOne('SELECT COUNT(*) FROM contact_import_candidate WHERE import_id = ? AND email_entered = ?', [$id, '+leading@example.test'])));
        } finally {
            unlink($file);
        }
    }

    public function testUnauthenticatedUnauthorizedCsrfAndEmptyList(): void
    {
        $client = self::createClient();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $password = 'synthetic-list-auth-'.bin2hex(random_bytes(10));
        $admin = $this->createPhase4User($db, 'list-admin@example.test', $password, AdministrativeRole::Administrator);
        $this->createPhase4User($db, 'list-operator@example.test', $password, AdministrativeRole::Operator);
        $list = $this->phase4Service(ContactManager::class)->createList($admin, 'Empty list');
        $server = $this->phase4HttpsServer('2001:db8::222');
        $client->request('POST', '/admin/lists/'.$list.'/download', server: $server);
        self::assertResponseRedirects('https://localhost/login');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'list-operator@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        self::assertSelectorNotExists('form[action="/admin/lists/'.$list.'/download"]');
        $client->request('POST', '/admin/lists/'.$list.'/download', server: $server);
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $client = self::createClient();
        $server = $this->phase4HttpsServer('2001:db8::223');
        $crawler = $client->request('GET', '/login', server: $server);
        $client->submit($crawler->selectButton('Sign in')->form(['login_identifier' => 'list-admin@example.test', 'password' => $password, 'mfa_code' => '']), [], $server);
        $crawler = $client->request('GET', '/admin/reauthenticate?purpose=contact_data_access', server: $server);
        $client->submit($crawler->selectButton('Confirm current credentials')->form(['password' => $password, 'mfa_code' => '']), [], $server);
        $client->request('POST', '/admin/lists/'.$list.'/download', ['_token' => 'invalid'], server: $server);
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/admin/lists', server: $server);
        $client->submit($crawler->filter('form[action="/admin/lists/'.$list.'/download"]')->selectButton('Download CSV')->form(), [], $server);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame("email,first_name,last_name\r\n", $client->getInternalResponse()->getContent());
        $this->phase4Service(ContactManager::class)->archiveList($admin, $list);
        $client->request('POST', '/admin/lists/'.$list.'/download', ['_token' => $this->phase4String($crawler->filter('form[action="/admin/lists/'.$list.'/download"] input[name="_token"]')->attr('value'))], server: $server);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLargeExportUsesBoundedApplicationMemory(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $admin = $this->createPhase4User($db, 'list-admin@example.test', 'synthetic-'.bin2hex(random_bytes(10)), AdministrativeRole::Administrator);
        $contacts = $this->phase4Service(ContactManager::class);
        $list = $contacts->createList($admin, 'Large list');
        $now = gmdate('Y-m-d H:i:s.u');
        $emails = [];
        for ($i = 0; $i < 499; ++$i) {
            $emails[] = \sprintf('a-%03d@example.test', $i);
        }
        $emails[] = 'Boundary@example.test';
        $emails[] = 'boundary@example.test';
        for ($i = 0; $i < 1000; ++$i) {
            $emails[] = \sprintf('z-%04d@example.test', $i);
        }
        foreach ($emails as $email) {
            $id = \App\Shared\Identity::uuidV7();
            $db->insert('contact', ['id' => $id, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => null, 'first_name' => null, 'last_name' => null, 'locale' => null, 'time_zone' => null, 'status' => 'active', 'consent_source' => 'synthetic', 'consent_at' => null, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
            $db->insert('list_membership', ['contact_id' => $id, 'list_id' => $list, 'source' => 'synthetic', 'created_at' => $now]);
        }
        $exporter = $this->phase4Service(ListCsvExporter::class);
        $stream = fopen('php://temp/maxmemory:1024', 'w+b');
        self::assertIsResource($stream);
        if (!\in_array('emailer.assert_unbuffered', stream_get_filters(), true)) {
            self::assertTrue(stream_filter_register('emailer.assert_unbuffered', ListCsvUnbufferedFilter::class));
        }
        ListCsvUnbufferedFilter::$writes = 0;
        $filter = stream_filter_append($stream, 'emailer.assert_unbuffered', STREAM_FILTER_WRITE, $db->getNativeConnection());
        self::assertIsResource($filter);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        self::assertSame(1500, $exporter->write($admin, $list, $stream));
        self::assertGreaterThan(1, ListCsvUnbufferedFilter::$writes);
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        rewind($stream);
        $lines = 0;
        while (false !== fgetcsv($stream, escape: '')) {
            ++$lines;
        }
        fclose($stream);
        self::assertSame(1501, $lines);
    }

    public function testArchiveBetweenPreparationAndWriteStopsTheExport(): void
    {
        self::bootKernel();
        $db = $this->phase4Service(Connection::class);
        $this->preparePhase4State($db);
        $admin = $this->createPhase4User($db, 'list-admin@example.test', 'synthetic-'.bin2hex(random_bytes(10)), AdministrativeRole::Administrator);
        $contacts = $this->phase4Service(ContactManager::class);
        $list = $contacts->createList($admin, 'Archive race');
        $exporter = $this->phase4Service(ListCsvExporter::class);
        $exporter->prepare($admin, $list);
        $contacts->archiveList($admin, $list);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            $exporter->write($admin, $list, $stream);
            self::fail('Archived list unexpectedly exported.');
        } catch (\InvalidArgumentException) {
            rewind($stream);
            self::assertSame('', stream_get_contents($stream));
            self::assertSame(0, $db->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'contact.list.export.generated' AND target_id = ?", [$list]));
        } finally {
            fclose($stream);
        }
    }
}

final class ListCsvUnbufferedFilter extends \php_user_filter
{
    public static int $writes = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::assertUnbuffered($this->params);
            ++self::$writes;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    private static function assertUnbuffered(mixed $connection): void
    {
        if (!$connection instanceof \PDO || true !== \defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            throw new \LogicException('MySQL PDO connection is required.');
        }
        if (false !== $connection->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)) {
            throw new \LogicException('List export buffered its result set.');
        }
    }
}
