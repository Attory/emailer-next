<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
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
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'contacts.list');
        $rows = $this->connection->fetchAllAssociative('SELECT c.* FROM contact c WHERE c.archived_at IS NULL ORDER BY c.updated_at DESC, c.id DESC LIMIT 500');
        foreach ($rows as &$row) {
            $address = new EmailAddress($this->stringValue($row['email_entered']));
            $row['email_display'] = $address->masked();
            $row['suppressed'] = $this->suppressions->isSuppressed($this->stringValue($row['email_canonical']));
            unset($row['email_entered'], $row['email_canonical']);
        }

        return $rows;
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
            ], ['id' => $id, 'status' => 'active']);
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
