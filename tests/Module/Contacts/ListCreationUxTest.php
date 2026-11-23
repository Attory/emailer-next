<?php

declare(strict_types=1);

namespace App\Tests\Module\Contacts;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\ImportManager;
use App\Module\Contacts\Infrastructure\PrivateImportStorage;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\AddressCorrectionSuppression;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Shared\Identity;
use App\Tests\Support\AdminUxSqlite;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ListCreationUxTest extends TestCase
{
    private Connection $db;
    private ContactManager $contacts;
    private ImportManager $imports;
    private AuthenticatedUser $actor;
    private string $directory;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => AdminUxSqlite::class]);
        foreach ([
            'CREATE TABLE contact_list(id TEXT PRIMARY KEY,name TEXT UNIQUE,created_at TEXT,updated_at TEXT,archived_at TEXT)',
            'CREATE TABLE list_membership(list_id TEXT,contact_id TEXT,source TEXT,created_at TEXT,PRIMARY KEY(list_id,contact_id))',
            'CREATE TABLE contact(id TEXT PRIMARY KEY,email_entered TEXT,email_canonical TEXT UNIQUE,display_name TEXT,first_name TEXT,last_name TEXT,locale TEXT,time_zone TEXT,status TEXT,consent_source TEXT,consent_at TEXT,created_at TEXT,updated_at TEXT,archived_at TEXT)',
            'CREATE TABLE contact_import(id TEXT PRIMARY KEY,actor_user_id TEXT,list_id TEXT,file_token TEXT,file_sha256 TEXT,mode TEXT,mapping_json TEXT,status TEXT,total_count INTEGER,valid_count INTEGER,duplicate_count INTEGER,suppressed_count INTEGER,invalid_count INTEGER,rejected_count INTEGER,report_token TEXT,created_at TEXT,expires_at TEXT,files_deleted_at TEXT,committed_at TEXT)',
            'CREATE TABLE contact_import_candidate(import_id TEXT,source_row INTEGER,email_entered TEXT,email_canonical TEXT,profile_json TEXT,PRIMARY KEY(import_id,email_canonical))',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
        $authorization = self::createStub(AdministrativeAuthorization::class);
        $audit = self::createStub(AuditSink::class);
        $suppression = self::createStub(SuppressionLookup::class);
        $suppression->method('isSuppressed')->willReturnCallback(static fn (string $address): bool => 'unsubscribed@example.test' === $address);
        $this->contacts = new ContactManager($this->db, $authorization, $audit, $suppression, self::createStub(AddressCorrectionSuppression::class));
        $this->directory = sys_get_temp_dir().'/list-ux-'.bin2hex(random_bytes(8));
        $this->imports = new ImportManager($this->db, $authorization, $audit, new PrivateImportStorage($this->directory), $suppression);
        $this->actor = new AuthenticatedUser(Identity::uuidV7(), 'operator@example.test', 'unused', AdministrativeRole::Operator, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = glob($this->directory.'/*');
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        $this->db->close();
    }

    public function testEmptyListAndOverlappingSourcesPlusMappedCsvPreserveSourcesAndSuppression(): void
    {
        $a = $this->contacts->createList($this->actor, 'Source A');
        $b = $this->contacts->createList($this->actor, 'Source B');
        $empty = $this->contacts->createList($this->actor, 'Empty');
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM list_membership WHERE list_id=?', [$empty]));
        $ids = [];
        foreach (['shared', 'first', 'second'] as $name) {
            $id = Identity::uuidV7();
            $ids[$name] = $id;
            $this->db->insert('contact', ['id' => $id, 'email_entered' => $name.'@example.test', 'email_canonical' => $name.'@example.test', 'status' => 'active']);
        }
        foreach ([[$a, 'shared'], [$a, 'first'], [$b, 'shared'], [$b, 'second']] as [$list, $name]) {
            $this->db->insert('list_membership', ['list_id' => $list, 'contact_id' => $ids[$name], 'source' => 'fixture']);
        }
        $before = $this->db->fetchAllAssociative('SELECT * FROM list_membership ORDER BY list_id,contact_id');
        $new = $this->contacts->createList($this->actor, 'Combined', [$b, $a, $a]);
        self::assertSame(3, $this->db->fetchOne('SELECT COUNT(*) FROM list_membership WHERE list_id=?', [$new]));
        $source = tempnam(sys_get_temp_dir(), 'list-ux-source-');
        self::assertIsString($source);
        file_put_contents($source, "Name,Email\nAlready,shared@example.test\nNew,new@example.test\nRepeat,new@example.test\nNo,unsubscribed@example.test\nBad,not-an-address\nMismatch\n".str_repeat('x', 501).",rejected@example.test\n");
        try {
            $import = $this->imports->upload($this->actor, $new, 'headered', $source);
            $preview = $this->imports->preview($this->actor, $import, ['email' => 1, 'display_name' => 0]);
            self::assertSame(1, $preview['valid']);
            self::assertSame(2, $preview['duplicate']);
            self::assertSame(1, $preview['suppressed']);
            self::assertSame(2, $preview['invalid']);
            self::assertSame(1, $preview['rejected']);
            $report = $this->imports->report($this->actor, $import);
            $text = stream_get_contents($report);
            fclose($report);
            self::assertStringContainsString('row 6: invalid email address', $text);
            self::assertStringContainsString('row 7: invalid email address', $text);
            self::assertStringContainsString('row 8: rejected row content', $text);
            $committed = $this->imports->commit($this->actor, $import);
            self::assertSame(1, $committed['valid']);
            self::assertSame(4, $this->db->fetchOne('SELECT COUNT(*) FROM list_membership WHERE list_id=?', [$new]));
            self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM list_membership WHERE list_id IN (?,?) ORDER BY list_id,contact_id', [$a, $b]));
            self::assertFalse($this->db->fetchOne('SELECT id FROM contact WHERE email_canonical=?', ['unsubscribed@example.test']));
        } finally {
            unlink($source);
        }
    }

    public function testUnavailableSourceRollsBackCreation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->contacts->createList($this->actor, 'Must not exist', [Identity::uuidV7()]);
        } finally {
            self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM contact_list'));
        }
    }
}
