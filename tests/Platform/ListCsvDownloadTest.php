<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\ListCsvExporter;
use App\Module\Identity\Application\AdministrativeRole;
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
        $csv = $client->getInternalResponse()->getContent();
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
        $before = memory_get_peak_usage(true);
        $exporter->write($admin, $list, $stream);
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        rewind($stream);
        $lines = 0;
        while (false !== fgetcsv($stream, escape: '')) {
            ++$lines;
        }
        fclose($stream);
        self::assertSame(1501, $lines);
    }
}
