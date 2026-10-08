<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class Phase9MvpLoadTest extends KernelTestCase
{
    use Phase4State;

    private const RECIPIENTS = 2000;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testRepresentativeCampaignMaterialisesWithinMvpAcceptanceBudget(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase9-load-owner@example.test', 'phase9-load-'.bin2hex(random_bytes(10)));
        $listId = Identity::uuidV7();
        $now = Identity::now();
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 9 bounded load', 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->transactional(function (Connection $connection) use ($listId, $now): void {
            for ($index = 0; $index < self::RECIPIENTS; ++$index) {
                $contactId = Identity::uuidV7();
                $email = \sprintf('phase9-load-%04d@example.test', $index);
                $connection->insert('contact', ['id' => $contactId, 'email_entered' => $email, 'email_canonical' => $email, 'display_name' => 'Synthetic Recipient', 'first_name' => 'Synthetic', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase9-load-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
                $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'phase9-load-test', 'created_at' => $now]);
            }
        });

        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($actor, 'Phase 9 load template', 'Hello {{ contact.first_name }}', '', '<p>Bounded synthetic campaign</p>', 'Bounded synthetic campaign');
        $versions = $templates->versions($actor, $templateId);
        self::assertNotEmpty($versions);
        $versionId = $versions[0]['id'] ?? null;
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);

        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 9 bounded load', $versionId, [$listId]);
        $campaigns->submitForReview($actor, $campaignId);
        $memoryBefore = memory_get_usage(true);
        $started = microtime(true);
        $campaigns->approve($actor, $campaignId, $this->phase4Int($campaigns->detail($actor, $campaignId)['operation_version']), true);
        $campaigns->queue($actor, $campaignId);
        $elapsed = microtime(true) - $started;
        $memoryDelta = memory_get_usage(true) - $memoryBefore;

        self::assertSame(self::RECIPIENTS, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient WHERE campaign_id = ?', [$campaignId])));
        self::assertSame(self::RECIPIENTS, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM campaign_recipient WHERE campaign_id = ?', [$campaignId])));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(120, $transport->getSent(), 'The bounded dispatch batch must enqueue one installation-rate window of the durable 2,000-recipient ledger rows.');
        self::assertLessThan(45.0, $elapsed, 'The 2,000-recipient reference campaign exceeded the 45-second CI acceptance budget.');
        self::assertLessThan(134217728, $memoryDelta, 'The 2,000-recipient reference campaign exceeded the 128 MiB incremental memory budget.');
    }
}
