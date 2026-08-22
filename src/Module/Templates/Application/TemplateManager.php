<?php

declare(strict_types=1);

namespace App\Module\Templates\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class TemplateManager
{
    private const SYNTHETIC = [
        'contact.first_name' => 'Alex',
        'contact.display_name' => 'Alex Example',
        'campaign.name' => 'Synthetic campaign',
        'unsubscribe_url' => 'https://example.test/r/synthetic/unsubscribe',
        'view_in_browser_url' => 'https://example.test/r/synthetic/view',
        'rsvp_url' => 'https://example.test/r/synthetic/rsvp',
    ];

    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private TemplateContentPolicy $content,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.list');

        return $this->connection->fetchAllAssociative('SELECT t.id, t.name, t.status, t.published_version_id, t.updated_at, COUNT(v.id) AS version_count FROM email_template t LEFT JOIN template_version v ON v.template_id = t.id GROUP BY t.id, t.name, t.status, t.published_version_id, t.updated_at ORDER BY t.updated_at DESC');
    }

    public function create(AdministrativeActor $actor, string $name, string $subject, string $preheader, string $html, string $text): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.create');
        $name = $this->name($name);
        $templateId = Identity::uuidV7();
        $this->connection->transactional(function () use ($actor, $name, $subject, $preheader, $html, $text, $templateId): void {
            $now = Identity::now();
            $this->connection->insert('email_template', ['id' => $templateId, 'name' => $name, 'status' => 'draft', 'published_version_id' => null, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
            $this->insertVersion($actor, $templateId, 1, $subject, $preheader, $html, $text);
            $this->audit->append(new AuditRecord($actor->id(), 'template.created', $templateId, 'success'));
        });

        return $templateId;
    }

    /** @return list<array<string, mixed>> */
    public function versions(AdministrativeActor $actor, string $templateId): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.versions.list');
        $this->assertUuid($templateId);

        return $this->connection->fetchAllAssociative('SELECT id, version_number, subject, preheader, content_sha256, created_at, published_at FROM template_version WHERE template_id = ? ORDER BY version_number DESC', [$templateId]);
    }

    public function addVersion(AdministrativeActor $actor, string $templateId, string $subject, string $preheader, string $html, string $text): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.version.create');
        $this->assertUuid($templateId);
        $versionId = '';
        $this->connection->transactional(function () use ($actor, $templateId, $subject, $preheader, $html, $text, &$versionId): void {
            $template = $this->connection->fetchAssociative('SELECT status FROM email_template WHERE id = ? FOR UPDATE', [$templateId]);
            if (false === $template || 'archived' === $template['status']) {
                throw new \InvalidArgumentException('Template is unavailable.');
            }
            $storedNumber = $this->connection->fetchOne('SELECT COALESCE(MAX(version_number), 0) FROM template_version WHERE template_id = ?', [$templateId]);
            if (!\is_int($storedNumber) && !(\is_string($storedNumber) && ctype_digit($storedNumber))) {
                throw new \UnexpectedValueException('Template version number is invalid.');
            }
            $number = 1 + (int) $storedNumber;
            $versionId = $this->insertVersion($actor, $templateId, $number, $subject, $preheader, $html, $text);
            $this->connection->update('email_template', ['updated_at' => Identity::now()], ['id' => $templateId]);
            $this->audit->append(new AuditRecord($actor->id(), 'template.version.created', $versionId, 'success', ['version_number' => $number]));
        });

        return $versionId;
    }

    public function publish(AdministrativeActor $actor, string $templateId, string $versionId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.publish');
        $this->assertUuid($templateId);
        $this->assertUuid($versionId);
        $this->connection->transactional(function () use ($actor, $templateId, $versionId): void {
            $template = $this->connection->fetchAssociative('SELECT status FROM email_template WHERE id = ? FOR UPDATE', [$templateId]);
            $version = $this->connection->fetchAssociative('SELECT published_at FROM template_version WHERE id = ? AND template_id = ? FOR UPDATE', [$versionId, $templateId]);
            if (false === $template || false === $version || 'archived' === $template['status']) {
                throw new \InvalidArgumentException('Template version is unavailable.');
            }
            if (null === $version['published_at']) {
                $this->connection->executeStatement('UPDATE template_version SET published_at = ? WHERE id = ? AND published_at IS NULL', [Identity::now(), $versionId]);
            }
            $this->connection->update('email_template', ['status' => 'published', 'published_version_id' => $versionId, 'updated_at' => Identity::now()], ['id' => $templateId]);
            $this->audit->append(new AuditRecord($actor->id(), 'template.published', $templateId, 'success', ['version_id' => $versionId]));
        });
    }

    public function archive(AdministrativeActor $actor, string $templateId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.archive');
        $this->assertUuid($templateId);
        $this->connection->transactional(function () use ($actor, $templateId): void {
            if (1 !== $this->connection->update('email_template', ['status' => 'archived', 'archived_at' => Identity::now(), 'updated_at' => Identity::now()], ['id' => $templateId])) {
                throw new \InvalidArgumentException('Template is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'template.archived', $templateId, 'success'));
        });
    }

    /** @return array<string, mixed> */
    public function preview(AdministrativeActor $actor, string $versionId): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.preview');
        $this->assertUuid($versionId);
        $version = $this->connection->fetchAssociative('SELECT id, subject, preheader, html_body, text_body FROM template_version WHERE id = ?', [$versionId]);
        if (false === $version) {
            throw new \InvalidArgumentException('Template version is unavailable.');
        }
        $version['subject'] = $this->renderText($this->stringValue($version['subject']));
        $version['preheader'] = $this->renderText(null === $version['preheader'] ? '' : $this->stringValue($version['preheader']));
        $version['html_body'] = $this->content->renderHtml($this->stringValue($version['html_body']), self::SYNTHETIC);
        $version['text_body'] = $this->renderText($this->stringValue($version['text_body']));

        return $version;
    }

    public function fakeTest(AdministrativeActor $actor, string $versionId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'templates.test.fake');
        $this->preview($actor, $versionId);
        $this->audit->append(new AuditRecord($actor->id(), 'template.test.validated', $versionId, 'success', ['transport' => 'fake_only']));
    }

    private function insertVersion(AdministrativeActor $actor, string $templateId, int $number, string $subject, string $preheader, string $html, string $text): string
    {
        $prepared = $this->content->prepare($subject, $preheader, $html, $text);
        $id = Identity::uuidV7();
        $digest = hash('sha256', implode("\0", [$subject, $preheader, $prepared['html'], $prepared['text'], json_encode($prepared['fields'], JSON_THROW_ON_ERROR), json_encode($prepared['links'], JSON_THROW_ON_ERROR)]));
        $this->connection->insert('template_version', [
            'id' => $id,
            'template_id' => $templateId,
            'version_number' => $number,
            'subject' => $subject,
            'preheader' => '' === trim($preheader) ? null : $preheader,
            'html_body' => $prepared['html'],
            'text_body' => $prepared['text'],
            'merge_fields_json' => json_encode($prepared['fields'], JSON_THROW_ON_ERROR),
            'link_manifest_json' => json_encode($prepared['links'], JSON_THROW_ON_ERROR),
            'content_sha256' => $digest,
            'created_by' => $actor->id(),
            'created_at' => Identity::now(),
            'published_at' => null,
        ]);

        return $id;
    }

    private function renderText(string $value): string
    {
        foreach (self::SYNTHETIC as $field => $replacement) {
            $value = preg_replace('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', $replacement, $value) ?? $value;
        }

        return $value;
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ('' === $name || 160 < mb_strlen($name, 'UTF-8') || 1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException('Template name is invalid.');
        }

        return $name;
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
}
