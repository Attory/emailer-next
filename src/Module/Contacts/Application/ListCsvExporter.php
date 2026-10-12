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
    private const PAGE_SIZE = 500;

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
    public function write(AdministrativeActor $actor, string $listId, $stream): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.lists.export');
        $this->connection->executeStatement('SET TRANSACTION READ ONLY');
        $this->connection->beginTransaction();
        try {
            $this->writeRows($listId, $stream);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param resource $stream */
    private function writeRows(string $listId, $stream): void
    {
        $this->writeRow($stream, ['email', 'first_name', 'last_name']);

        $cursorEmail = null;
        $cursorId = null;
        $previousEmail = null;
        do {
            $where = '';
            $parameters = [$listId];
            if (null !== $cursorEmail && null !== $cursorId) {
                $where = ' AND (LOWER(TRIM(c.email_entered)) COLLATE utf8mb4_bin > ? OR (LOWER(TRIM(c.email_entered)) COLLATE utf8mb4_bin = ? AND c.id > ?))';
                array_push($parameters, $cursorEmail, $cursorEmail, $cursorId);
            }
            $rows = $this->connection->fetchAllAssociative(
                'SELECT c.id, c.email_entered, c.first_name, c.last_name, LOWER(TRIM(c.email_entered)) AS normalized_email
                 FROM list_membership m JOIN contact c ON c.id = m.contact_id
                 WHERE m.list_id = ?'.$where.' ORDER BY normalized_email COLLATE utf8mb4_bin, c.id LIMIT '.self::PAGE_SIZE,
                $parameters,
            );
            foreach ($rows as $row) {
                $normalized = $row['normalized_email'];
                if (!\is_string($normalized) || !\is_string($row['id']) || !\is_string($row['email_entered'])) {
                    throw new \UnexpectedValueException('List export row is invalid.');
                }
                $cursorEmail = $normalized;
                $cursorId = $row['id'];
                if ($normalized === $previousEmail) {
                    continue;
                }
                $previousEmail = $normalized;
                $this->writeRow($stream, [
                    $this->neutralize($row['email_entered']),
                    $this->neutralize($row['first_name']),
                    $this->neutralize($row['last_name']),
                ]);
            }
        } while (self::PAGE_SIZE === \count($rows));
    }

    private function neutralize(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('List export value is invalid.');
        }
        if ('' !== $value && (str_contains('=+-@', $value[0]) || "\t" === $value[0] || "\r" === $value[0] || "\n" === $value[0])) {
            return "'".$value;
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
