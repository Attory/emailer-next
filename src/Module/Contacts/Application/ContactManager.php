<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\AddressCorrectionSuppression;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class ContactManager
{
    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private SuppressionLookup $suppressions,
        private AddressCorrectionSuppression $corrections,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.list');
        $rows = $this->connection->fetchAllAssociative('SELECT c.* FROM contact c WHERE c.archived_at IS NULL ORDER BY c.updated_at DESC, c.id DESC LIMIT 500');
        foreach ($rows as &$row) {
            $address = new EmailAddress($this->stringValue($row['email_entered']));
            $row['email_display'] = $actor->roleRank() >= AdministrativeAccessLevel::Owner->value ? $address->entered : $address->masked();
            $row['suppressed'] = $this->suppressions->isSuppressed($this->stringValue($row['email_canonical']));
            unset($row['email_entered'], $row['email_canonical']);
        }

        if ($actor->roleRank() >= AdministrativeAccessLevel::Owner->value) {
            $this->audit->append(new AuditRecord($actor->id(), 'contact.addresses.viewed', null, 'success', ['count' => \count($rows)]));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function contactForEdit(AdministrativeActor $actor, string $id): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Owner, 'contacts.edit.view');
        $this->assertUuid($id);
        $row = $this->connection->fetchAssociative('SELECT * FROM contact WHERE id = ?', [$id]);
        if (false === $row) {
            throw new \InvalidArgumentException('Contact is unavailable.');
        }
        $row['attributes'] = $this->connection->fetchAllKeyValue('SELECT field_key, value_text FROM contact_attribute WHERE contact_id = ?', [$id]);
        $this->audit->append(new AuditRecord($actor->id(), 'contact.address.revealed', $id, 'success'));

        return $row;
    }

    public function reveal(AdministrativeActor $actor, string $id): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.reveal.full');
        $this->assertUuid($id);
        $email = $this->connection->fetchOne('SELECT email_entered FROM contact WHERE id = ?', [$id]);
        if (!\is_string($email)) {
            throw new \InvalidArgumentException('Contact is unavailable.');
        }
        $this->audit->append(new AuditRecord($actor->id(), 'contact.address.revealed', $id, 'success'));

        return $email;
    }

    /** @param array<string, string> $profile
     * @param array<string, string> $attributes
     */
    public function create(AdministrativeActor $actor, string $email, array $profile, array $attributes = []): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.create');
        $address = new EmailAddress($email);
        $id = Identity::uuidV7();
        $now = Identity::now();
        $this->connection->transactional(function () use ($id, $address, $profile, $attributes, $actor, $now): void {
            $this->connection->insert('contact', [
                'id' => $id,
                'email_entered' => $address->entered,
                'email_canonical' => $address->canonical,
                'display_name' => $this->optionalText($profile['display_name'] ?? '', 160),
                'first_name' => $this->optionalText($profile['first_name'] ?? '', 100),
                'last_name' => $this->optionalText($profile['last_name'] ?? '', 100),
                'locale' => $this->locale($profile['locale'] ?? ''),
                'time_zone' => $this->timeZone($profile['time_zone'] ?? ''),
                'status' => 'active',
                'consent_source' => $this->requiredText($profile['consent_source'] ?? 'manual', 120),
                'consent_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'archived_at' => null,
            ]);
            $this->replaceAttributes($id, $attributes);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.created', $id, 'success'));
        });

        return $id;
    }

    /** @param array<string, string> $profile
     * @param array<string, string> $attributes
     */
    public function update(AdministrativeActor $actor, string $id, string $email, array $profile, array $attributes = []): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.update');
        $this->assertUuid($id);
        $address = new EmailAddress($email);
        $this->connection->transactional(function () use ($actor, $id, $address, $profile, $attributes): void {
            $previous = $this->connection->fetchOne('SELECT email_canonical FROM contact WHERE id = ?'.($actor->roleRank() >= AdministrativeAccessLevel::Owner->value ? '' : " AND status = 'active'").' FOR UPDATE', [$id]);
            if (!\is_string($previous)) {
                throw new \InvalidArgumentException('Contact is unavailable.');
            }
            if ($previous !== $address->canonical) {
                $this->corrections->preserve($actor, $previous, $address->canonical);
            }
            $changed = $this->connection->update('contact', [
                'email_entered' => $address->entered,
                'email_canonical' => $address->canonical,
                'display_name' => $this->optionalText($profile['display_name'] ?? '', 160),
                'first_name' => $this->optionalText($profile['first_name'] ?? '', 100),
                'last_name' => $this->optionalText($profile['last_name'] ?? '', 100),
                'locale' => $this->locale($profile['locale'] ?? ''),
                'time_zone' => $this->timeZone($profile['time_zone'] ?? ''),
                'consent_source' => $this->requiredText($profile['consent_source'] ?? 'manual', 120),
                'updated_at' => Identity::now(),
            ], ['id' => $id]);
            if (1 !== $changed) {
                throw new \InvalidArgumentException('Contact is unavailable.');
            }
            $this->replaceAttributes($id, $attributes);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.updated', $id, 'success'));
        });
    }

    public function archive(AdministrativeActor $actor, string $id): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.archive');
        $this->assertUuid($id);
        $this->connection->transactional(function () use ($actor, $id): void {
            if (1 !== $this->connection->update('contact', ['status' => 'archived', 'archived_at' => Identity::now(), 'updated_at' => Identity::now()], ['id' => $id, 'status' => 'active'])) {
                throw new \InvalidArgumentException('Contact is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'contact.archived', $id, 'success'));
        });
    }

    /** @return list<array<string, mixed>> */
    public function fields(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.fields.list');

        return $this->connection->fetchAllAssociative('SELECT field_key, label, field_type, max_length FROM contact_field_definition WHERE active = 1 ORDER BY field_key');
    }

    public function defineField(AdministrativeActor $actor, string $key, string $label, string $type, ?int $maxLength): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'contacts.fields.define');
        $key = strtolower(trim($key));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,47}$/', $key) || !\in_array($type, ['text', 'integer', 'boolean', 'date'], true)) {
            throw new \InvalidArgumentException('Custom field definition is invalid.');
        }
        $label = $this->requiredText($label, 100);
        $maxLength = 'text' === $type ? max(1, min($maxLength ?? 160, 500)) : null;
        $this->connection->transactional(function () use ($actor, $key, $label, $type, $maxLength): void {
            $this->connection->insert('contact_field_definition', ['field_key' => $key, 'label' => $label, 'field_type' => $type, 'max_length' => $maxLength, 'active' => 1, 'created_at' => Identity::now()]);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.field.created', $key, 'success', ['field_type' => $type]));
        });
    }

    /** @return list<array<string, mixed>> */
    public function lists(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.list');

        return $this->connection->fetchAllAssociative('SELECT l.id, l.name, l.created_at, COUNT(m.contact_id) AS member_count FROM contact_list l LEFT JOIN list_membership m ON m.list_id = l.id WHERE l.archived_at IS NULL GROUP BY l.id, l.name, l.created_at ORDER BY l.name');
    }

    /** @return array<string, mixed> */
    public function listInfo(AdministrativeActor $actor, string $id): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.view');
        $this->assertUuid($id);
        $row = $this->connection->fetchAssociative('SELECT id, name FROM contact_list WHERE id = ? AND archived_at IS NULL', [$id]);
        if (false === $row) {
            throw new \InvalidArgumentException('List is unavailable.');
        }
        $row['member_count'] = $this->intValue($this->connection->fetchOne('SELECT COUNT(*) FROM list_membership WHERE list_id = ?', [$id]));

        return $row;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public function listPage(AdministrativeActor $actor, string $id, int $page = 1, string $query = '', bool $available = false): array
    {
        $this->listInfo($actor, $id);
        if ($page < 1 || $page > 1000000 || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query, 'UTF-8') > 160 || 0 !== preg_match('/[\x00-\x1F\x7F]/', $query)) {
            throw new \InvalidArgumentException('List search is invalid.');
        }
        $owner = $actor->roleRank() >= AdministrativeAccessLevel::Owner->value;
        $membership = 'EXISTS (SELECT 1 FROM list_membership m WHERE m.contact_id = c.id AND m.list_id = ?)';
        $where = $available ? 'NOT '.$membership." AND c.archived_at IS NULL AND c.status = 'active'" : $membership;
        $parameters = [$id];
        if ('' !== trim($query)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($query)).'%';
            $columns = ['display_name', 'first_name', 'last_name'];
            if ($owner) {
                $columns[] = 'email_entered';
            }
            $conditions = [];
            foreach ($columns as $column) {
                $conditions[] = "LOWER(c.$column) LIKE LOWER(?) ESCAPE '!'";
                $parameters[] = $pattern;
            }
            $where .= ' AND ('.implode(' OR ', $conditions).')';
        }
        $total = $this->intValue($this->connection->fetchOne('SELECT COUNT(*) FROM contact c WHERE '.$where, $parameters));
        $pages = max(1, (int) ceil($total / 100));
        $page = min($page, $pages);
        $rows = $this->connection->fetchAllAssociative('SELECT c.id, c.email_entered, c.display_name, c.first_name, c.last_name, c.status, c.archived_at, c.email_canonical FROM contact c WHERE '.$where.' ORDER BY c.email_canonical, c.id LIMIT 100 OFFSET '.(($page - 1) * 100), $parameters);
        foreach ($rows as &$row) {
            $address = new EmailAddress($this->stringValue($row['email_entered']));
            $row['email_display'] = $owner ? $address->entered : $address->masked();
            $row['suppressed'] = $this->suppressions->isSuppressed($this->stringValue($row['email_canonical']));
            unset($row['email_entered'], $row['email_canonical']);
        }
        unset($row);
        if ($owner) {
            $this->audit->append(new AuditRecord($actor->id(), 'contact.list.addresses.viewed', $id, 'success', ['count' => \count($rows), 'page' => $page, 'available' => $available]));
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function renameList(AdministrativeActor $actor, string $id, string $name): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.rename');
        $this->assertUuid($id);
        $name = $this->requiredText($name, 160);
        $this->connection->transactional(function () use ($actor, $id, $name): void {
            if (1 !== $this->connection->executeStatement('UPDATE contact_list SET name = ?, updated_at = ? WHERE id = ? AND archived_at IS NULL', [$name, Identity::now(), $id])) {
                throw new \InvalidArgumentException('List is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'contact.list.renamed', $id, 'success'));
        });
    }

    public function duplicateList(AdministrativeActor $actor, string $id, string $name): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.duplicate');
        $this->assertUuid($id);
        $name = $this->requiredText($name, 160);
        $newId = Identity::uuidV7();
        $this->connection->transactional(function () use ($actor, $id, $name, $newId): void {
            if (false === $this->connection->fetchOne('SELECT id FROM contact_list WHERE id = ? AND archived_at IS NULL FOR UPDATE', [$id])) {
                throw new \InvalidArgumentException('List is unavailable.');
            }
            $now = Identity::now();
            $this->connection->insert('contact_list', ['id' => $newId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
            $count = $this->connection->executeStatement('INSERT INTO list_membership (list_id, contact_id, source, created_at) SELECT ?, contact_id, ?, ? FROM list_membership WHERE list_id = ?', [$newId, 'duplicate', $now, $id]);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.list.duplicated', $newId, 'success', ['source_list' => $id, 'count' => $count]));
        });

        return $newId;
    }

    public function createList(AdministrativeActor $actor, string $name): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.create');
        $id = Identity::uuidV7();
        $now = Identity::now();
        $name = $this->requiredText($name, 160);
        $this->connection->transactional(function () use ($actor, $id, $name, $now): void {
            $this->connection->insert('contact_list', ['id' => $id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.list.created', $id, 'success'));
        });

        return $id;
    }

    public function archiveList(AdministrativeActor $actor, string $id): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.lists.archive');
        $this->assertUuid($id);
        $this->connection->transactional(function () use ($actor, $id): void {
            if (1 !== $this->connection->executeStatement('UPDATE contact_list SET archived_at = ?, updated_at = ? WHERE id = ? AND archived_at IS NULL', [Identity::now(), Identity::now(), $id])) {
                throw new \InvalidArgumentException('List is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'contact.list.archived', $id, 'success'));
        });
    }

    public function addMembership(AdministrativeActor $actor, string $listId, string $contactId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.membership.add');
        $this->assertUuid($listId);
        $this->assertUuid($contactId);
        $this->connection->transactional(function () use ($actor, $listId, $contactId): void {
            $this->connection->executeStatement('INSERT IGNORE INTO list_membership (contact_id, list_id, source, created_at) SELECT c.id, l.id, ?, ? FROM contact c JOIN contact_list l ON l.id = ? AND l.archived_at IS NULL WHERE c.id = ? AND c.archived_at IS NULL', ['manual', Identity::now(), $listId, $contactId]);
            if (false === $this->connection->fetchOne('SELECT 1 FROM list_membership WHERE contact_id = ? AND list_id = ?', [$contactId, $listId])) {
                throw new \InvalidArgumentException('Membership target is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'contact.membership.added', $listId, 'success'));
        });
    }

    public function removeMembership(AdministrativeActor $actor, string $listId, string $contactId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.membership.remove');
        $this->assertUuid($listId);
        $this->assertUuid($contactId);
        $this->connection->transactional(function () use ($actor, $listId, $contactId): void {
            $this->connection->delete('list_membership', ['contact_id' => $contactId, 'list_id' => $listId]);
            $this->audit->append(new AuditRecord($actor->id(), 'contact.membership.removed', $listId, 'success'));
        });
    }

    /** @param array<string, string> $attributes */
    private function replaceAttributes(string $contactId, array $attributes): void
    {
        $this->connection->delete('contact_attribute', ['contact_id' => $contactId]);
        $definitions = $this->connection->fetchAllAssociativeIndexed('SELECT field_key, field_type, max_length FROM contact_field_definition WHERE active = 1');
        foreach ($attributes as $key => $value) {
            if (!isset($definitions[$key])) {
                throw new \InvalidArgumentException('Unknown custom field.');
            }
            $value = trim($value);
            if ('' === $value) {
                continue;
            }
            $definition = $definitions[$key];
            $valid = match ($definition['field_type']) {
                'text' => mb_strlen($value, 'UTF-8') <= $this->intValue($definition['max_length']) && 0 === preg_match('/[\x00-\x1F\x7F]/', $value),
                'integer' => 1 === preg_match('/^-?[0-9]{1,18}$/', $value),
                'boolean' => \in_array($value, ['0', '1', 'true', 'false'], true),
                'date' => $this->validDate($value),
                default => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException('Custom field value is invalid.');
            }
            $this->connection->insert('contact_attribute', ['contact_id' => $contactId, 'field_key' => $key, 'value_text' => $value]);
        }
    }

    private function optionalText(string $value, int $limit): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $this->requiredText($value, $limit);
    }

    private function requiredText(string $value, int $limit): string
    {
        $value = trim($value);
        if ('' === $value || mb_strlen($value, 'UTF-8') > $limit || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Text value is invalid.');
        }

        return $value;
    }

    private function locale(string $value): ?string
    {
        if ('' === trim($value)) {
            return null;
        }
        $value = \Locale::canonicalize(trim($value));
        if (null === $value || 20 < \strlen($value) || 1 !== preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $value)) {
            throw new \InvalidArgumentException('Locale is invalid.');
        }

        return $value;
    }

    private function timeZone(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (!\in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Time zone is invalid.');
        }

        return $value;
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

    private function intValue(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            throw new \UnexpectedValueException('Database integer value is invalid.');
        }

        return (int) $value;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) && $date->format('Y-m-d') === $value;
    }
}
