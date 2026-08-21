<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use Doctrine\DBAL\Connection;

final readonly class ContactExporter
{
    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private SuppressionLookup $suppressions,
    ) {
    }

    /** @param resource $stream */
    public function export(AdministrativeActor $actor, $stream): int
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.export.full');
        $this->writeRow($stream, ['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone', 'status', 'consent_source', 'suppressed']);
        $count = 0;
        $result = $this->connection->executeQuery('SELECT c.email_entered, c.email_canonical, c.display_name, c.first_name, c.last_name, c.locale, c.time_zone, c.status, c.consent_source FROM contact c ORDER BY c.created_at, c.id');
        while (false !== ($row = $result->fetchNumeric())) {
            $canonical = $row[1] ?? null;
            if (!\is_string($canonical)) {
                throw new \UnexpectedValueException('Export address is invalid.');
            }
            unset($row[1]);
            $row[] = $this->suppressions->isSuppressed($canonical);
            $this->writeRow($stream, array_map($this->neutralize(...), array_values($row)));
            ++$count;
        }
        $this->audit->append(new AuditRecord($actor->id(), 'contact.exported', null, 'success', ['row_count' => $count, 'full_address' => true]));

        return $count;
    }

    private function neutralize(mixed $value): string
    {
        if (null === $value) {
            $value = '';
        } elseif (\is_scalar($value)) {
            $value = (string) $value;
        } else {
            throw new \UnexpectedValueException('Export value is invalid.');
        }
        if ('' !== $value && (str_contains('=+-@', $value[0]) || "\t" === $value[0] || "\r" === $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    /** @param resource $stream
     * @param list<string> $row
     */
    private function writeRow($stream, array $row): void
    {
        if (false === fputcsv($stream, $row, ',', '"', '')) {
            throw new \RuntimeException('Contact export could not be written.');
        }
    }
}
