<?php

declare(strict_types=1);

namespace App\Module\Analytics\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use Doctrine\DBAL\Connection;

final readonly class AnalyticsManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private AuditSink $audit)
    {
    }

    /** @return array{policy: array<string, mixed>, campaigns: list<array<string, mixed>>} */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Auditor, 'analytics.view');
        $policy = $this->connection->fetchAssociative("SELECT open_tracking, click_tracking, tracking_retention_days FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $policy) {
            throw new \RuntimeException('Analytics policy is unavailable.');
        }

        return ['policy' => $policy, 'campaigns' => $this->connection->fetchAllAssociative("SELECT c.id, c.name, s.open_tracking, s.click_tracking, COUNT(DISTINCT CASE WHEN a.event_type = 'open' THEN a.campaign_recipient_id END) approximate_opens, COUNT(DISTINCT CASE WHEN a.event_type = 'click' THEN a.campaign_recipient_id END) approximate_clicks FROM campaign c INNER JOIN campaign_content_snapshot s ON s.campaign_id = c.id LEFT JOIN campaign_recipient r ON r.campaign_id = c.id LEFT JOIN analytics_aggregate a ON a.campaign_recipient_id = r.id GROUP BY c.id, c.name, s.open_tracking, s.click_tracking ORDER BY c.created_at DESC LIMIT 100")];
    }

    public function configure(AdministrativeActor $actor, bool $open, bool $click, int $retentionDays): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'analytics.configure');
        if (1 > $retentionDays || 365 < $retentionDays) {
            throw new \InvalidArgumentException('Tracking retention must be between 1 and 365 days.');
        }
        $this->connection->transactional(function () use ($actor, $open, $click, $retentionDays): void {
            if (1 !== $this->connection->update('privacy_policy', ['open_tracking' => $open ? 1 : 0, 'click_tracking' => $click ? 1 : 0, 'tracking_retention_days' => $retentionDays], ['singleton_key' => '1'])) {
                throw new \RuntimeException('Analytics policy is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'analytics.policy.updated', null, 'success', ['open_tracking' => $open, 'click_tracking' => $click, 'retention_days' => $retentionDays, 'effective_for' => 'future_campaign_approvals']));
        });
    }
}
