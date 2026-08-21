<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Contacts\Application\Contract\ImportStorage;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class ImportManager
{
    private const MAX_ROWS = 50_000;
    private const MAX_COLUMNS = 32;
    private const MAX_FIELD_BYTES = 500;
    private const MAPPING_KEYS = ['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone', 'consent_source'];

    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private ImportStorage $storage,
        private SuppressionLookup $suppressions,
    ) {
    }

    public function upload(AdministrativeActor $actor, string $listId, string $mode, string $sourcePath): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.import.upload');
        $this->assertUuid($listId);
        if (!\in_array($mode, ['headered', 'headerless'], true) || false === $this->connection->fetchOne('SELECT 1 FROM contact_list WHERE id = ? AND archived_at IS NULL', [$listId])) {
            throw new \InvalidArgumentException('Import target is invalid.');
        }
        $this->cleanupExpired();
        $stored = $this->storage->store($sourcePath);
        $id = Identity::uuidV7();
        try {
            $this->connection->transactional(function () use ($actor, $listId, $mode, $stored, $id): void {
                $this->connection->insert('contact_import', [
                    'id' => $id,
                    'actor_user_id' => $actor->id(),
                    'list_id' => $listId,
                    'file_token' => $stored['token'],
                    'file_sha256' => $stored['sha256'],
                    'mode' => $mode,
                    'mapping_json' => '{}',
                    'status' => 'uploaded',
                    'total_count' => 0,
                    'valid_count' => 0,
                    'duplicate_count' => 0,
                    'suppressed_count' => 0,
                    'invalid_count' => 0,
                    'rejected_count' => 0,
                    'report_token' => null,
                    'created_at' => Identity::now(),
                    'expires_at' => (new \DateTimeImmutable('+2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                    'files_deleted_at' => null,
                    'committed_at' => null,
                ]);
                $this->audit->append(new AuditRecord($actor->id(), 'contact.import.uploaded', $id, 'success', ['mode' => $mode, 'byte_size' => $stored['size']]));
            });
        } catch (\Throwable $exception) {
            $this->storage->delete($stored['token']);
            throw $exception;
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public function details(AdministrativeActor $actor, string $id): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.import.details');
        $this->cleanupExpired();
        $row = $this->import($id, $actor, ['uploaded', 'previewed', 'committed']);

        return array_intersect_key($row, array_flip(['id', 'list_id', 'mode', 'status', 'total_count', 'valid_count', 'duplicate_count', 'suppressed_count', 'invalid_count', 'rejected_count', 'created_at', 'expires_at', 'committed_at']));
    }

    /** @param array<string, int> $mapping
     * @return array<string, int|string|null>
     */
    public function preview(AdministrativeActor $actor, string $id, array $mapping): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.import.preview');
        $this->assertUuid($id);
        $this->cleanupExpired();
        $mapping = $this->mapping($mapping);
        $import = $this->import($id, $actor, ['uploaded', 'previewed']);
        $stream = $this->storage->open($this->stringValue($import['file_token']), $this->stringValue($import['file_sha256']));
        $report = [];
        $counts = ['total' => 0, 'valid' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0, 'rejected' => 0];
        $reportToken = bin2hex(random_bytes(24));
        $reportWritten = false;
        $previousReportToken = null;
        try {
            $this->connection->transactional(function () use ($stream, $actor, $id, $mapping, $reportToken, &$report, &$counts, &$reportWritten, &$previousReportToken): void {
                $import = $this->import($id, $actor, ['uploaded', 'previewed'], true);
                $previousReportToken = \is_string($import['report_token']) ? $import['report_token'] : null;
                $this->connection->delete('contact_import_candidate', ['import_id' => $id]);
                $rowNumber = 0;
                while (false !== ($row = fgetcsv($stream, 16_385, ',', '"', ''))) {
                    ++$rowNumber;
                    if ('headered' === $import['mode'] && 1 === $rowNumber) {
                        if (isset($row[0])) {
                            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
                        }
                        $this->validateRowShape($row);
                        continue;
                    }
                    ++$counts['total'];
                    if ($counts['total'] > self::MAX_ROWS) {
                        throw new \InvalidArgumentException('Import row limit exceeded.');
                    }
                    try {
                        $this->validateRowShape($row);
                    } catch (\InvalidArgumentException) {
                        ++$counts['rejected'];
                        $report[] = 'row '.$rowNumber.': rejected row content';
                        continue;
                    }
                    try {
                        $emailValue = $row[$mapping['email']] ?? '';
                        if (1 === $rowNumber && 'headerless' === $import['mode']) {
                            $emailValue = preg_replace('/^\xEF\xBB\xBF/', '', $emailValue) ?? $emailValue;
                        }
                        $address = new EmailAddress($emailValue);
                        $profile = [];
                        foreach ($mapping as $field => $column) {
                            if ('email' !== $field) {
                                $profile[$field] = $row[$column] ?? '';
                            }
                        }
                        $profile = $this->normalizeImportProfile($profile);
                        $inserted = $this->connection->executeStatement('INSERT IGNORE INTO contact_import_candidate (import_id, source_row, email_entered, email_canonical, profile_json) VALUES (?, ?, ?, ?, ?)', [$import['id'], $rowNumber, $address->entered, $address->canonical, json_encode($profile, JSON_THROW_ON_ERROR)]);
                        if (0 === $inserted) {
                            ++$counts['duplicate'];
                            $report[] = 'row '.$rowNumber.': duplicate in upload';
                            continue;
                        }
                        if ($this->suppressions->isSuppressed($address->canonical)) {
                            ++$counts['suppressed'];
                            $report[] = 'row '.$rowNumber.': globally suppressed';
                        } elseif (false !== $this->connection->fetchOne('SELECT 1 FROM contact c JOIN list_membership m ON m.contact_id = c.id WHERE c.email_canonical = ? AND m.list_id = ?', [$address->canonical, $import['list_id']])) {
                            ++$counts['duplicate'];
                            $report[] = 'row '.$rowNumber.': already in selected list';
                        } else {
                            ++$counts['valid'];
                        }
                    } catch (\InvalidArgumentException $exception) {
                        $reason = str_contains($exception->getMessage(), 'Email') ? 'invalid email address' : 'invalid profile data';
                        ++$counts['invalid'];
                        $report[] = 'row '.$rowNumber.': '.$reason;
                    }
                }
                if (!feof($stream)) {
                    throw new \InvalidArgumentException('Import CSV is malformed.');
                }
                if (0 === $counts['total']) {
                    throw new \InvalidArgumentException('Import contains no data rows.');
                }

                $this->storage->writeReport($reportToken, $report);
                $reportWritten = true;
                $updated = $this->connection->update('contact_import', [
                    'mapping_json' => json_encode($mapping, JSON_THROW_ON_ERROR),
                    'status' => 'previewed',
                    'total_count' => $counts['total'],
                    'valid_count' => $counts['valid'],
                    'duplicate_count' => $counts['duplicate'],
                    'suppressed_count' => $counts['suppressed'],
                    'invalid_count' => $counts['invalid'],
                    'rejected_count' => $counts['rejected'],
                    'report_token' => $reportToken,
                ], ['id' => $id]);
                if (1 !== $updated) {
                    throw new \RuntimeException('Import preview state could not be stored.');
                }
                $this->audit->append(new AuditRecord($actor->id(), 'contact.import.previewed', $id, 'success', $counts));
            });
            if (null !== $previousReportToken && $previousReportToken !== $reportToken) {
                $this->storage->deleteReport($previousReportToken);
            }
        } catch (\Throwable $exception) {
            if ($reportWritten) {
                $this->storage->deleteReport($reportToken);
            }

            throw $exception;
        } finally {
            fclose($stream);
        }

        return ['id' => $id, 'report_token' => $reportToken, ...$counts];
    }

    /** @return array<string, int> */
    public function commit(AdministrativeActor $actor, string $id): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.import.commit');
        $this->assertUuid($id);
        $this->cleanupExpired();
        $counts = ['valid' => 0, 'duplicate' => 0, 'suppressed' => 0, 'invalid' => 0, 'rejected' => 0];
        $import = $this->import($id, $actor, ['previewed']);
        $integrity = $this->storage->open($this->stringValue($import['file_token']), $this->stringValue($import['file_sha256']));
        fclose($integrity);
        $this->connection->transactional(function () use ($actor, $id, &$counts): void {
            $import = $this->connection->fetchAssociative('SELECT * FROM contact_import WHERE id = ? FOR UPDATE', [$id]);
            if (false === $import || 'previewed' !== $import['status']) {
                throw new \InvalidArgumentException('Import is no longer committable.');
            }
            $result = $this->connection->executeQuery('SELECT * FROM contact_import_candidate WHERE import_id = ? ORDER BY source_row', [$id]);
            while (false !== ($candidate = $result->fetchAssociative())) {
                $canonical = $this->stringValue($candidate['email_canonical']);
                if ($this->suppressions->isSuppressed($canonical)) {
                    ++$counts['suppressed'];
                    continue;
                }
                $contactId = $this->connection->fetchOne('SELECT id FROM contact WHERE email_canonical = ? FOR UPDATE', [$candidate['email_canonical']]);
                if (false === $contactId) {
                    /** @var array<string, string> $profile */
                    $profile = json_decode($this->stringValue($candidate['profile_json']), true, flags: JSON_THROW_ON_ERROR);
                    $now = Identity::now();
                    $this->connection->executeStatement('INSERT IGNORE INTO contact (id, email_entered, email_canonical, display_name, first_name, last_name, locale, time_zone, status, consent_source, consent_at, created_at, updated_at, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL)', [
                        Identity::uuidV7(),
                        $candidate['email_entered'],
                        $candidate['email_canonical'],
                        $this->safeImportText($profile['display_name'] ?? '', 160),
                        $this->safeImportText($profile['first_name'] ?? '', 100),
                        $this->safeImportText($profile['last_name'] ?? '', 100),
                        $this->safeLocale($profile['locale'] ?? ''),
                        $this->safeTimeZone($profile['time_zone'] ?? ''),
                        'active',
                        $this->safeImportText($profile['consent_source'] ?? 'csv_import', 120) ?? 'csv_import',
                        $now,
                        $now,
                    ]);
                    $contactId = $this->connection->fetchOne('SELECT id FROM contact WHERE email_canonical = ? FOR UPDATE', [$candidate['email_canonical']]);
                    if (!\is_string($contactId)) {
                        throw new \RuntimeException('Contact could not be created.');
                    }
                }
                $added = $this->connection->executeStatement('INSERT IGNORE INTO list_membership (contact_id, list_id, source, created_at) VALUES (?, ?, ?, ?)', [$contactId, $import['list_id'], 'csv_import', Identity::now()]);
                if (1 === $added) {
                    ++$counts['valid'];
                } else {
                    ++$counts['duplicate'];
                }
            }
            $this->connection->update('contact_import', ['status' => 'committed', 'valid_count' => $counts['valid'], 'duplicate_count' => $counts['duplicate'], 'suppressed_count' => $counts['suppressed'], 'committed_at' => Identity::now()], ['id' => $id]);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.import.committed', $id, 'success', $counts));
        });
        $this->storage->delete($this->stringValue($import['file_token']));

        return $counts;
    }

    /** @return resource */
    public function report(AdministrativeActor $actor, string $id)
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.import.report');
        $this->cleanupExpired();
        $import = $this->import($id, $actor, ['previewed', 'committed']);
        if (!\is_string($import['report_token'])) {
            throw new \InvalidArgumentException('Import report is unavailable.');
        }

        return $this->storage->openReport($import['report_token']);
    }

    /** @param list<string> $statuses
     * @return array<string, mixed>
     */
    private function import(string $id, AdministrativeActor $actor, array $statuses, bool $forUpdate = false): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM contact_import WHERE id = ?'.($forUpdate ? ' FOR UPDATE' : ''), [$id]);
        if (false === $row || !\is_string($row['status']) || !\in_array($row['status'], $statuses, true) || (!\is_string($row['actor_user_id']) && null !== $row['actor_user_id']) || ($row['actor_user_id'] !== $actor->id() && $actor->roleRank() < AdministrativeAccessLevel::Administrator->value) || ('committed' !== $row['status'] && $this->isExpired($row['expires_at'] ?? null))) {
            throw new \InvalidArgumentException('Import is unavailable.');
        }

        return $row;
    }

    /** @param array<string, int> $mapping
     * @return array<string, int>
     */
    private function mapping(array $mapping): array
    {
        if (!isset($mapping['email'])) {
            throw new \InvalidArgumentException('Email column is required.');
        }
        foreach ($mapping as $key => $column) {
            if (!\in_array($key, self::MAPPING_KEYS, true) || $column < 0 || $column >= self::MAX_COLUMNS) {
                throw new \InvalidArgumentException('Import mapping is invalid.');
            }
        }
        if (\count(array_unique($mapping)) !== \count($mapping)) {
            throw new \InvalidArgumentException('Import columns cannot be reused.');
        }

        return $mapping;
    }

    /** @param list<string|null> $row */
    private function validateRowShape(array $row): void
    {
        if (0 === \count($row) || \count($row) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('CSV row has an invalid column count.');
        }
        foreach ($row as $field) {
            if (!\is_string($field) || \strlen($field) > self::MAX_FIELD_BYTES || !mb_check_encoding($field, 'UTF-8') || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $field)) {
                throw new \InvalidArgumentException('CSV row contains invalid content.');
            }
        }
    }

    private function cleanupExpired(): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, status, file_token, report_token FROM contact_import WHERE files_deleted_at IS NULL AND expires_at < UTC_TIMESTAMP(6) LIMIT 100');
        foreach ($rows as $row) {
            $this->storage->delete($this->stringValue($row['file_token']));
            if (\is_string($row['report_token'])) {
                $this->storage->deleteReport($row['report_token']);
            }
            if ('committed' === $row['status']) {
                $this->connection->update('contact_import', ['report_token' => null, 'files_deleted_at' => Identity::now()], ['id' => $row['id']]);
            } else {
                $this->connection->delete('contact_import', ['id' => $row['id']]);
            }
        }
    }

    private function safeImportText(string $value, int $limit): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $limit || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Imported text is invalid.');
        }

        return $value;
    }

    private function safeLocale(string $value): ?string
    {
        if ('' === trim($value)) {
            return null;
        }
        $locale = \Locale::canonicalize(trim($value));

        return null !== $locale && 20 >= \strlen($locale) && 1 === preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $locale) ? $locale : null;
    }

    private function safeTimeZone(string $value): ?string
    {
        $value = trim($value);

        return '' !== $value && \in_array($value, \DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    /** @param array<string, string> $profile
     * @return array<string, string>
     */
    private function normalizeImportProfile(array $profile): array
    {
        foreach (['display_name' => 160, 'first_name' => 100, 'last_name' => 100, 'consent_source' => 120] as $field => $limit) {
            $value = $this->safeImportText($profile[$field] ?? '', $limit);
            $profile[$field] = $value ?? (('consent_source' === $field) ? 'csv_import' : '');
        }
        $locale = trim($profile['locale'] ?? '');
        if ('' !== $locale && null === $this->safeLocale($locale)) {
            throw new \InvalidArgumentException('Imported locale is invalid.');
        }
        $profile['locale'] = $this->safeLocale($locale) ?? '';
        $timeZone = trim($profile['time_zone'] ?? '');
        if ('' !== $timeZone && null === $this->safeTimeZone($timeZone)) {
            throw new \InvalidArgumentException('Imported time zone is invalid.');
        }
        $profile['time_zone'] = $this->safeTimeZone($timeZone) ?? '';

        return $profile;
    }

    private function assertUuid(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw new \InvalidArgumentException('Identifier is invalid.');
        }
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Database text value is invalid.');
        }

        return $value;
    }

    private function isExpired(mixed $value): bool
    {
        if (!\is_string($value)) {
            return true;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }
    }
}
