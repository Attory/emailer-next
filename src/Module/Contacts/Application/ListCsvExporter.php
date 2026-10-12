<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use Doctrine\DBAL\Connection;

final readonly class ListCsvExporter
{
    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
    ) {
    }

    public function prepare(AdministrativeActor $actor, string $listId): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.lists.export');
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $listId)) {
            throw new \InvalidArgumentException('List is unavailable.');
        }
        $name = $this->connection->fetchOne('SELECT name FROM contact_list WHERE id = ? AND archived_at IS NULL', [$listId]);
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('List is unavailable.');
        }

        $this->audit->append(new AuditRecord($actor->id(), 'contact.list.export.requested', $listId, 'success', ['format' => 'csv', 'scope' => 'selected_list']));

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        $slug = trim(substr($slug, 0, 48), '-');
        if ('' === $slug) {
            $slug = 'list';
        }

        return $slug.'-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.csv';
    }

    /** @param resource $stream */
    public function write(AdministrativeActor $actor, string $listId, $stream): int
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.lists.export');
        $native = $this->connection->getNativeConnection();
        if (!$native instanceof \PDO || !\defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')) {
            throw new \RuntimeException('Unbuffered list export is unavailable.');
        }
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->connection->beginTransaction();
        try {
            $native->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
            try {
                $count = $this->writeRows($listId, $stream);
            } finally {
                $native->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
        $this->audit->append(new AuditRecord($actor->id(), 'contact.list.export.generated', $listId, 'success', ['row_count' => $count, 'format' => 'csv']));

        return $count;
    }

    /** @param resource $stream */
    private function writeRows(string $listId, $stream): int
    {
        $name = $this->connection->fetchOne('SELECT name FROM contact_list WHERE id = ? AND archived_at IS NULL', [$listId]);
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('List is unavailable.');
        }
        $this->writeRow($stream, ['email', 'first_name', 'last_name']);
        $count = 0;
        $previousEmail = null;
        $result = $this->connection->executeQuery(
            'SELECT c.email_entered, c.first_name, c.last_name, LOWER(TRIM(c.email_entered)) AS normalized_email
             FROM list_membership m JOIN contact c ON c.id = m.contact_id
             WHERE m.list_id = ? ORDER BY normalized_email COLLATE utf8mb4_bin, c.id',
            [$listId],
        );
        try {
            while (false !== ($row = $result->fetchAssociative())) {
                $normalized = $row['normalized_email'] ?? null;
                if (!\is_string($normalized) || !\is_string($row['email_entered'] ?? null)) {
                    throw new \UnexpectedValueException('List export row is invalid.');
                }
                if ($normalized === $previousEmail) {
                    continue;
                }
                $previousEmail = $normalized;
                $this->writeRow($stream, [
                    ListCsvFormulaGuard::protect($row['email_entered']),
                    ListCsvFormulaGuard::protect($this->stringValue($row['first_name'] ?? null)),
                    ListCsvFormulaGuard::protect($this->stringValue($row['last_name'] ?? null)),
                ]);
                ++$count;
            }
        } finally {
            $result->free();
        }

        return $count;
    }

    private function stringValue(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('List export value is invalid.');
        }

        return $value;
    }

    /** @param resource $stream
     * @param list<string> $row
     */
    private function writeRow($stream, array $row): void
    {
        if (false === fputcsv($stream, $row, ',', '"', '', "\r\n")) {
            throw new \RuntimeException('List export could not be written.');
        }
    }
}
