<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Campaigns\Application\Contract\SendingGate;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Contacts\Application\Contract\AudienceProvider;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class CampaignManager
{
    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private AudienceProvider $audiences,
        private PublishedTemplateProvider $templates,
        private DeliveryConfiguration $configuration,
        private CampaignDeliveryGateway $delivery,
        private SendingGate $sendingGate,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Auditor, 'campaign.list');

        return $this->connection->fetchAllAssociative("SELECT c.id, c.name, c.state, c.scheduled_at, c.approved_at, c.updated_at, COALESCE(a.recipient_count, 0) recipient_count, SUM(r.state = 'accepted') accepted_count, SUM(r.state IN ('permanent_failure', 'ambiguous')) failure_count FROM campaign c LEFT JOIN campaign_audience_snapshot a ON a.campaign_id = c.id LEFT JOIN campaign_recipient r ON r.campaign_id = c.id GROUP BY c.id, c.name, c.state, c.scheduled_at, c.approved_at, c.updated_at, a.recipient_count ORDER BY c.updated_at DESC");
    }

    /** @return array{templates: list<array<string, mixed>>, lists: list<array<string, mixed>>} */
    public function options(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.options');

        return [
            'templates' => $this->connection->fetchAllAssociative("SELECT v.id, t.name, v.version_number FROM email_template t INNER JOIN template_version v ON v.id = t.published_version_id WHERE t.status = 'published' ORDER BY t.name"),
            'lists' => $this->connection->fetchAllAssociative('SELECT id, name FROM contact_list WHERE archived_at IS NULL ORDER BY name'),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(AdministrativeActor $actor, string $id): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Auditor, 'campaign.view');
        $this->uuid($id);
        $row = $this->connection->fetchAssociative('SELECT c.*, COALESCE(a.recipient_count, 0) recipient_count, a.audience_sha256, t.name template_name, v.version_number template_version, s.open_tracking snapshot_open_tracking, s.click_tracking snapshot_click_tracking, s.rsvp_enabled snapshot_rsvp_enabled, s.rsvp_repeat_policy snapshot_rsvp_repeat_policy FROM campaign c INNER JOIN template_version v ON v.id = c.template_version_id INNER JOIN email_template t ON t.id = v.template_id LEFT JOIN campaign_audience_snapshot a ON a.campaign_id = c.id LEFT JOIN campaign_content_snapshot s ON s.campaign_id = c.id WHERE c.id = ?', [$id]);
        if (false === $row) {
            throw new \InvalidArgumentException('Campaign is unavailable.');
        }
        $row['delivery_counts'] = $this->connection->fetchAllAssociative('SELECT state, COUNT(*) count FROM campaign_recipient WHERE campaign_id = ? GROUP BY state ORDER BY state', [$id]);
        $row['latest_test'] = $this->connection->fetchAssociative('SELECT state, failure_class, requested_at, accepted_at FROM campaign_test_delivery WHERE campaign_id = ? ORDER BY requested_at DESC LIMIT 1', [$id]);
        $audience = $this->connection->fetchAssociative("SELECT COUNT(DISTINCT c.id) selected_count, COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN c.id END) suppressed_count FROM campaign_list_selection x INNER JOIN contact_list l ON l.id = x.list_id AND l.archived_at IS NULL INNER JOIN list_membership m ON m.list_id = x.list_id INNER JOIN contact c ON c.id = m.contact_id AND c.status = 'active' AND c.archived_at IS NULL LEFT JOIN suppression s ON s.email_canonical = c.email_canonical WHERE x.campaign_id = ?", [$id]);
        $selected = false === $audience ? 0 : $this->intValue($audience['selected_count']);
        $suppressed = false === $audience ? 0 : $this->intValue($audience['suppressed_count']);
        $configuration = $this->configuration->current();
        $openTracking = is_numeric($row['snapshot_open_tracking']) ? 1 === $this->intValue($row['snapshot_open_tracking']) : $configuration['open_tracking'];
        $clickTracking = is_numeric($row['snapshot_click_tracking']) ? 1 === $this->intValue($row['snapshot_click_tracking']) : $configuration['click_tracking'];
        $timeZone = $this->connection->fetchOne("SELECT time_zone FROM organisation_settings WHERE singleton_key = '1'");
        $row['review'] = [
            'selected_count' => $selected,
            'suppressed_count' => $suppressed,
            'eligible_count' => max(0, $selected - $suppressed),
            'sender_address' => $configuration['sender_address'],
            'sender_name' => $configuration['sender_name'],
            'one_click' => $configuration['one_click_dkim_confirmed'] ? 'Enabled by provider DKIM attestation' : 'Disabled',
            'tracking' => $openTracking || $clickTracking ? \sprintf('Open: %s; click: %s', $openTracking ? 'on' : 'off', $clickTracking ? 'on' : 'off') : 'Off',
            'rsvp' => (is_numeric($row['snapshot_rsvp_enabled']) ? 1 === $this->intValue($row['snapshot_rsvp_enabled']) : 1 === $this->intValue($row['rsvp_enabled'])) ? 'Enabled; repeat policy: '.(\is_string($row['snapshot_rsvp_repeat_policy']) ? $row['snapshot_rsvp_repeat_policy'] : $configuration['rsvp_repeat_policy']) : 'Off',
            'time_zone' => \is_string($timeZone) && '' !== $timeZone ? $timeZone : 'UTC',
        ];

        return $row;
    }

    /** @param list<string> $listIds */
    public function create(AdministrativeActor $actor, string $name, string $templateVersionId, array $listIds): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.create');
        $name = trim($name);
        if ('' === $name || 160 < mb_strlen($name) || 1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException('Campaign name is invalid.');
        }
        $template = $this->templates->get($templateVersionId);
        $listIds = array_values(array_unique($listIds));
        $this->audiences->snapshot($listIds);
        $id = Identity::uuidV7();
        $this->connection->transactional(function () use ($actor, $id, $name, $templateVersionId, $listIds): void {
            $now = Identity::now();
            $this->connection->insert('campaign', ['id' => $id, 'name' => $name, 'template_version_id' => $templateVersionId, 'state' => 'draft', 'scheduled_at' => null, 'approved_at' => null, 'approved_by' => null, 'approval_waived_test' => 0, 'rsvp_enabled' => 0, 'operation_version' => 1, 'created_by' => $actor->id(), 'created_at' => $now, 'updated_at' => $now]);
            foreach ($listIds as $listId) {
                $this->connection->insert('campaign_list_selection', ['campaign_id' => $id, 'list_id' => $listId]);
            }
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.created', $id, 'success', ['list_count' => \count($listIds)]));
        });

        return $id;
    }

    public function configureRsvp(AdministrativeActor $actor, string $id, bool $enabled): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.rsvp.configure');
        $this->uuid($id);
        $this->connection->transactional(function () use ($actor, $id, $enabled): void {
            $updated = $this->connection->executeStatement("UPDATE campaign SET rsvp_enabled = ?, operation_version = operation_version + 1, updated_at = ? WHERE id = ? AND state = 'draft'", [$enabled ? 1 : 0, Identity::now(), $id]);
            if (1 !== $updated) {
                throw new \DomainException('Campaign RSVP can only be configured while draft.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.rsvp.configured', $id, 'success', ['enabled' => $enabled]));
        });
    }

    public function submitForReview(AdministrativeActor $actor, string $id): void
    {
        $this->transition($actor, $id, ['draft'], 'review', 'campaign.review.submitted');
    }

    public function requestTest(AdministrativeActor $actor, string $campaignId): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.test.request');
        $this->uuid($campaignId);
        $address = new EmailAddress($actor->loginIdentifier());
        $id = Identity::uuidV7();
        $this->connection->transactional(function () use ($actor, $campaignId, $address, $id): void {
            $state = $this->connection->fetchOne('SELECT state FROM campaign WHERE id = ? FOR UPDATE', [$campaignId]);
            if (!\in_array($state, ['draft', 'review'], true)) {
                throw new \DomainException('Campaign test is not available in this state.');
            }
            $this->connection->insert('campaign_test_delivery', ['id' => $id, 'campaign_id' => $campaignId, 'requested_by' => $actor->id(), 'recipient_address' => $address->canonical, 'state' => 'pending', 'failure_class' => null, 'requested_at' => Identity::now(), 'accepted_at' => null]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.test.queued', $campaignId, 'success'));
        });
        $this->delivery->dispatchTest($id);

        return $id;
    }

    public function approve(AdministrativeActor $actor, string $id, bool $waiveTest = false): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'campaign.approve');
        $this->uuid($id);
        $this->connection->transactional(function () use ($actor, $id, $waiveTest): void {
            $campaign = $this->connection->fetchAssociative('SELECT state, template_version_id, rsvp_enabled FROM campaign WHERE id = ? FOR UPDATE', [$id]);
            if (false === $campaign || 'review' !== $campaign['state'] || !\is_string($campaign['template_version_id'])) {
                throw new \DomainException('Campaign is not awaiting approval.');
            }
            if (!$waiveTest && false === $this->connection->fetchOne("SELECT 1 FROM campaign_test_delivery WHERE campaign_id = ? AND state = 'accepted'", [$id])) {
                throw new \DomainException('An accepted campaign test is required before approval.');
            }
            $template = $this->templates->get($campaign['template_version_id']);
            $storedListIds = $this->connection->fetchFirstColumn('SELECT list_id FROM campaign_list_selection WHERE campaign_id = ? ORDER BY list_id', [$id]);
            $listIds = [];
            foreach ($storedListIds as $storedListId) {
                if (\is_string($storedListId)) {
                    $listIds[] = $storedListId;
                }
            }
            $recipients = $this->audiences->snapshot($listIds);
            $configuration = $this->configuration->current();
            $rsvpEnabled = 1 === $this->intValue($campaign['rsvp_enabled']);
            $templateUsesRsvp = \in_array('rsvp_url', $template['merge_fields'], true);
            if ($rsvpEnabled && !$configuration['rsvp_enabled']) {
                throw new \DomainException('RSVP is disabled by the installation privacy policy.');
            }
            if ($rsvpEnabled !== $templateUsesRsvp) {
                throw new \DomainException('Campaign RSVP selection and template must agree.');
            }
            if ([] === $recipients || \count($recipients) > $configuration['max_campaign_size']) {
                throw new \DomainException('Campaign audience size is outside the configured limit.');
            }
            $now = Identity::now();
            $this->connection->insert('campaign_content_snapshot', ['campaign_id' => $id, 'template_version_id' => $template['id'], 'subject' => $template['subject'], 'preheader' => $template['preheader'], 'html_body' => $template['html'], 'text_body' => $template['text'], 'unsubscribe_footer' => $configuration['unsubscribe_footer'], 'one_click_dkim_confirmed' => $configuration['one_click_dkim_confirmed'] ? 1 : 0, 'merge_fields_json' => json_encode($template['merge_fields'], JSON_THROW_ON_ERROR), 'link_manifest_json' => json_encode($template['links'], JSON_THROW_ON_ERROR), 'open_tracking' => $configuration['open_tracking'] ? 1 : 0, 'click_tracking' => $configuration['click_tracking'] ? 1 : 0, 'rsvp_enabled' => $rsvpEnabled ? 1 : 0, 'rsvp_repeat_policy' => $configuration['rsvp_repeat_policy'], 'rsvp_form_schema_json' => json_encode(['fields' => ['response', 'guests', 'note'], 'choices' => ['yes', 'no', 'maybe'], 'max_guests' => 20, 'max_note_length' => 500], JSON_THROW_ON_ERROR), 'content_sha256' => $template['content_sha256'], 'sender_address' => $configuration['sender_address'], 'sender_name' => $configuration['sender_name'], 'reply_to_address' => $configuration['reply_to'], 'created_at' => $now]);
            $digestParts = [];
            foreach ($recipients as $recipient) {
                $digestParts[] = $recipient['contact_id'].'\0'.$recipient['email'];
                $this->connection->insert('campaign_audience_recipient', ['campaign_id' => $id, 'contact_id' => $recipient['contact_id'], 'email_canonical' => $recipient['email'], 'profile_json' => json_encode($recipient['profile'], JSON_THROW_ON_ERROR)]);
            }
            $this->connection->insert('campaign_audience_snapshot', ['campaign_id' => $id, 'recipient_count' => \count($recipients), 'audience_sha256' => hash('sha256', implode("\n", $digestParts)), 'created_at' => $now]);
            $this->connection->update('campaign', ['state' => 'approved', 'approved_at' => $now, 'approved_by' => $actor->id(), 'approval_waived_test' => $waiveTest ? 1 : 0, 'operation_version' => 2, 'updated_at' => $now], ['id' => $id]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.approved', $id, 'success', ['recipient_count' => \count($recipients), 'test_waived' => $waiveTest]));
        });
    }

    public function schedule(AdministrativeActor $actor, string $id, \DateTimeImmutable $at): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.schedule');
        $this->sendingGate->assertReady();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($at <= $now || $at > $now->modify('+1 year')) {
            throw new \InvalidArgumentException('Campaign schedule is invalid.');
        }
        $this->uuid($id);
        $this->connection->transactional(function () use ($actor, $id, $at): void {
            $count = $this->connection->executeStatement("UPDATE campaign SET state = 'scheduled', scheduled_at = ?, operation_version = operation_version + 1, updated_at = ? WHERE id = ? AND state = 'approved'", [$at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), Identity::now(), $id]);
            if (1 !== $count) {
                throw new \DomainException('Campaign cannot be scheduled.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.scheduled', $id, 'success'));
        });
    }

    public function queue(AdministrativeActor $actor, string $id): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.queue');
        $this->sendingGate->assertReady();
        $this->uuid($id);
        $version = 0;
        $this->connection->transactional(function () use ($actor, $id, &$version): void {
            $row = $this->connection->fetchAssociative('SELECT state, operation_version FROM campaign WHERE id = ? FOR UPDATE', [$id]);
            if (false === $row || !\in_array($row['state'], ['approved', 'scheduled'], true)) {
                throw new \DomainException('Campaign cannot be queued.');
            }
            $version = 1 + $this->intValue($row['operation_version']);
            $this->connection->update('campaign', ['state' => 'queueing', 'scheduled_at' => null, 'operation_version' => $version, 'updated_at' => Identity::now()], ['id' => $id]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.queued', $id, 'success'));
        });
        $this->delivery->materializeAndDispatch($id, $version);
    }

    public function pause(AdministrativeActor $actor, string $id): void
    {
        $this->transition($actor, $id, ['queueing', 'sending'], 'paused', 'campaign.paused');
    }

    public function resume(AdministrativeActor $actor, string $id): void
    {
        $this->sendingGate->assertReady();
        $this->transition($actor, $id, ['paused'], 'sending', 'campaign.resumed');
        $this->delivery->dispatchDue($id);
    }

    public function cancel(AdministrativeActor $actor, string $id): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, 'campaign.cancel');
        $this->uuid($id);
        $this->connection->transactional(function () use ($actor, $id): void {
            $updated = $this->connection->executeStatement("UPDATE campaign SET state = 'cancelled', operation_version = operation_version + 1, updated_at = ? WHERE id = ? AND state IN ('approved', 'scheduled', 'queueing', 'sending', 'paused')", [Identity::now(), $id]);
            if (1 !== $updated) {
                throw new \DomainException('Campaign cannot be cancelled.');
            }
            $this->connection->executeStatement("UPDATE campaign_recipient SET state = 'cancelled', terminal_at = ?, updated_at = ? WHERE campaign_id = ? AND state IN ('pending', 'retry')", [Identity::now(), Identity::now(), $id]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.cancelled', $id, 'success'));
        });
    }

    /** @param list<string> $from */
    private function transition(AdministrativeActor $actor, string $id, array $from, string $to, string $action): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Operator, $action);
        $this->uuid($id);
        $this->connection->transactional(function () use ($actor, $id, $from, $to, $action): void {
            $placeholders = implode(', ', array_fill(0, \count($from), '?'));
            $updated = $this->connection->executeStatement("UPDATE campaign SET state = ?, operation_version = operation_version + 1, updated_at = ? WHERE id = ? AND state IN ($placeholders)", [$to, Identity::now(), $id, ...$from]);
            if (1 !== $updated) {
                throw new \DomainException('Campaign state transition is not available.');
            }
            $this->audit->append(new AuditRecord($actor->id(), $action, $id, 'success'));
        });
    }

    private function uuid(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw new \InvalidArgumentException('Campaign identity is invalid.');
        }
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Campaign numeric state is invalid.');
        }

        return (int) $value;
    }
}
