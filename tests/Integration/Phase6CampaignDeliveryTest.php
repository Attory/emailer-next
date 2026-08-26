<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Delivery\Application\DeliverCampaignRecipientHandler;
use App\Module\Delivery\Application\DeliverCampaignTestHandler;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\Delivery\Application\Message\DeliverCampaignTest;
use App\Module\Delivery\Infrastructure\WorkerHeartbeatSubscriber;
use App\Module\Delivery\Presentation\Cli\DeliveryQuiesceCommand;
use App\Module\Delivery\Presentation\Cli\DeliveryResumeCommand;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Module\Templates\Application\TemplateManager;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

final class Phase6CampaignDeliveryTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testApprovalRequiresAcceptedTestAndCreatesImmutableSnapshots(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-owner@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $detail = $campaigns->detail($actor, $campaignId);
        $review = $detail['review'] ?? null;
        self::assertIsArray($review);
        self::assertSame(1, $this->phase4Int($review['selected_count'] ?? null));
        self::assertSame(0, $this->phase4Int($review['suppressed_count'] ?? null));
        self::assertSame('Off', $review['tracking'] ?? null);
        self::assertSame('Off', $review['rsvp'] ?? null);
        $templateName = $detail['template_name'] ?? null;
        self::assertIsString($templateName);
        self::assertStringStartsWith('Phase 6 template ', $templateName);

        $this->expectException(\DomainException::class);
        try {
            $campaigns->approve($actor, $campaignId);
        } finally {
            self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM campaign_content_snapshot WHERE campaign_id = ?', [$campaignId])));
        }
    }

    public function testQueuedTestAndRecipientMessagesContainIdentifiersOnlyAndMailpitAccepts(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-mailpit@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId, $contactId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $transport = $this->transport();
        $testId = $campaigns->requestTest($actor, $campaignId);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $queuedTest = $envelopes[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignTest::class, $queuedTest);
        self::assertSame($testId, $queuedTest->testDeliveryId);
        self::assertStringNotContainsString('phase6-mailpit@example.test', serialize($queuedTest));
        ($this->phase4Service(DeliverCampaignTestHandler::class))($queuedTest);
        self::assertSame('accepted', $connection->fetchOne('SELECT state FROM campaign_test_delivery WHERE id = ?', [$testId]));

        $campaigns->approve($actor, $campaignId);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT recipient_count FROM campaign_audience_snapshot WHERE campaign_id = ?', [$campaignId])));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM campaign_content_snapshot WHERE campaign_id = ?', [$campaignId])));
        $transport->reset();
        $campaigns->queue($actor, $campaignId);
        $deliveries = $transport->getSent();
        self::assertCount(1, $deliveries);
        $queuedDelivery = $deliveries[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queuedDelivery);
        $serialized = serialize($queuedDelivery);
        self::assertStringNotContainsString('recipient@example.test', $serialized);
        self::assertStringNotContainsString('Phase 6 body', $serialized);
        self::assertSame($contactId, $connection->fetchOne('SELECT contact_id FROM campaign_recipient WHERE id = ?', [$queuedDelivery->recipientId]));
        ($this->phase4Service(DeliverCampaignRecipientHandler::class))($queuedDelivery);
        self::assertSame('accepted', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queuedDelivery->recipientId]));
        self::assertSame('completed', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM delivery_attempt WHERE campaign_recipient_id = ? AND state = 'accepted'", [$queuedDelivery->recipientId])));
        ($this->phase4Service(DeliverCampaignRecipientHandler::class))($queuedDelivery);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt WHERE campaign_recipient_id = ?', [$queuedDelivery->recipientId])));
    }

    public function testSuppressionAddedAfterQueuePreventsTransportAttempt(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-suppression@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $connection->insert('suppression', ['id' => Identity::uuidV7(), 'email_canonical' => 'recipient@example.test', 'reason' => 'manual', 'source' => 'phase6-test', 'created_at' => Identity::now()]);
        ($this->phase4Service(DeliverCampaignRecipientHandler::class))($queued);
        self::assertSame('suppressed', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt WHERE campaign_recipient_id = ?', [$queued->recipientId])));
    }

    public function testTransientFailureOpensCircuitAndExpiredWindowAllowsHalfOpenRecovery(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-transient@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $this->transport()->reset();
        $connection->executeStatement("UPDATE delivery_runtime SET circuit_failures = 4, circuit_state = 'closed' WHERE singleton_key = '1'");
        ($this->faultHandler($connection, 'transient'))($queued);
        self::assertSame('retry', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame('transient', $connection->fetchOne('SELECT state FROM delivery_attempt WHERE campaign_recipient_id = ?', [$queued->recipientId]));
        self::assertSame('open', $connection->fetchOne("SELECT circuit_state FROM delivery_runtime WHERE singleton_key = '1'"));
        $retryEnvelope = $this->transport()->getSent()[0];
        $delayStamp = $retryEnvelope->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $delayStamp);
        $delay = $delayStamp->getDelay();
        self::assertGreaterThanOrEqual(60_000, $delay);
        self::assertLessThanOrEqual(90_000, $delay);

        $connection->executeStatement("UPDATE delivery_runtime SET circuit_open_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE singleton_key = '1'");
        ($this->faultHandler($connection, 'accepted'))($queued);
        self::assertSame('accepted', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame('closed', $connection->fetchOne("SELECT circuit_state FROM delivery_runtime WHERE singleton_key = '1'"));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT circuit_failures FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testInterruptedHalfOpenProbeReopensCircuitForBoundedRecovery(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-half-open@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $old = (new \DateTimeImmutable('-11 minutes'))->format('Y-m-d H:i:s.u');
        $attemptId = Identity::uuidV7();
        $connection->update('campaign_recipient', ['state' => 'processing', 'attempt_count' => 1, 'claimed_at' => $old], ['id' => $queued->recipientId]);
        $connection->insert('delivery_attempt', ['id' => $attemptId, 'campaign_recipient_id' => $queued->recipientId, 'attempt_number' => 1, 'state' => 'started', 'failure_class' => null, 'provider_reference_hash' => null, 'started_at' => $old, 'finished_at' => null]);
        $connection->executeStatement("UPDATE delivery_runtime SET active_claims = 1, circuit_failures = 5, circuit_state = 'half_open', circuit_open_until = ?, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'", [$old]);

        try {
            $this->phase4Service(DeliveryScheduler::class)->run('phase6-half-open-recovery');
            self::fail('A freshly reopened circuit did not stop new scheduling.');
        } catch (\DomainException) {
        }
        self::assertSame('ambiguous', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1'")));
        self::assertSame('open', $connection->fetchOne("SELECT circuit_state FROM delivery_runtime WHERE singleton_key = '1'"));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT circuit_open_until > UTC_TIMESTAMP(6) FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testAmbiguousOutcomeIsTerminalAndNeverBlindlyRetried(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-ambiguous@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $this->transport()->reset();
        try {
            ($this->faultHandler($connection, 'ambiguous'))($queued);
            self::fail('Ambiguous delivery must be dead-lettered.');
        } catch (UnrecoverableMessageHandlingException $deadLetterFailure) {
            self::assertStringNotContainsString('recipient@example.test', serialize($deadLetterFailure));
        }
        self::assertSame('ambiguous', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame('completed', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
        self::assertCount(0, $this->transport()->getSent());
    }

    public function testDatabaseConcurrencyLimitDefersWithoutCreatingAttempt(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-concurrency@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $this->transport()->reset();
        $connection->executeStatement("UPDATE delivery_runtime SET active_claims = 4 WHERE singleton_key = '1'");
        ($this->faultHandler($connection, 'accepted'))($queued);
        self::assertSame('pending', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt WHERE campaign_recipient_id = ?', [$queued->recipientId])));
        self::assertInstanceOf(DelayStamp::class, $this->transport()->getSent()[0]->last(DelayStamp::class));
    }

    public function testSecureSmtpCredentialIsEncryptedAndLiveProviderIsRejectedInTest(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-config@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        $password = 'smtp-secret-'.bin2hex(random_bytes(16));
        $manager = $this->phase4Service(MailConfigurationManager::class);
        $manager->configureSecureSmtp($actor, 'smtp.provider.example', 587, 'starttls', 'provider-user', $password, 'sender@example.test', 'Phase 6 Sender', 'reply@example.test');
        $secret = $connection->fetchOne('SELECT envelope FROM encrypted_secret WHERE record_type = ?', ['smtp.credential']);
        self::assertIsString($secret);
        self::assertStringNotContainsString($password, $secret);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT tls_peer_verification FROM sender_identity WHERE singleton_key = '1'")));
        try {
            $this->phase4Service(DeliveryTransport::class)->send(new OutboundMessage('recipient@example.test', 'sender@example.test', 'Phase 6 Sender', null, 'Subject', '<p>Body</p>', 'Body', 'phase6-test@example.invalid'));
            self::fail('The test environment must reject a non-local SMTP provider.');
        } catch (DeliveryTransportFailure $failure) {
            self::assertSame('permanent', $failure->classification);
        }
    }

    public function testSchedulerRecoversInterruptedAttemptAsAmbiguousAndRuntimeCanQuiesce(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-scheduler@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $campaigns->schedule($actor, $campaignId, new \DateTimeImmutable('+5 minutes'));
        $connection->update('campaign', ['scheduled_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.u')], ['id' => $campaignId]);
        $interruptedTestId = Identity::uuidV7();
        $connection->insert('campaign_test_delivery', ['id' => $interruptedTestId, 'campaign_id' => $campaignId, 'requested_by' => $actor->id(), 'recipient_address' => 'phase6-scheduler@example.test', 'state' => 'processing', 'failure_class' => null, 'requested_at' => (new \DateTimeImmutable('-11 minutes'))->format('Y-m-d H:i:s.u'), 'accepted_at' => null]);
        $this->transport()->reset();
        $scheduler = $this->phase4Service(DeliveryScheduler::class);
        $result = $scheduler->run('phase6-scheduler-test');
        self::assertSame(1, $result['scheduled']);
        self::assertSame('ambiguous', $connection->fetchOne('SELECT failure_class FROM campaign_test_delivery WHERE id = ?', [$interruptedTestId]));
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $attemptId = Identity::uuidV7();
        $old = (new \DateTimeImmutable('-11 minutes'))->format('Y-m-d H:i:s.u');
        $connection->update('campaign_recipient', ['state' => 'processing', 'attempt_count' => 1, 'claimed_at' => $old], ['id' => $queued->recipientId]);
        $connection->insert('delivery_attempt', ['id' => $attemptId, 'campaign_recipient_id' => $queued->recipientId, 'attempt_number' => 1, 'state' => 'started', 'failure_class' => null, 'provider_reference_hash' => null, 'started_at' => $old, 'finished_at' => null]);
        $connection->executeStatement("UPDATE delivery_runtime SET active_claims = 1 WHERE singleton_key = '1'");
        $scheduler->run('phase6-scheduler-test');
        self::assertSame('ambiguous', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame('completed', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1'")));

        $quiesce = new CommandTester($this->phase4Service(DeliveryQuiesceCommand::class));
        self::assertSame(0, $quiesce->execute([]));
        self::assertStringContainsString('accepting_work=0 active_claims=0', $quiesce->getDisplay());
        $resume = new CommandTester($this->phase4Service(DeliveryResumeCommand::class));
        self::assertSame(0, $resume->execute([]));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'")));
    }

    public function testWorkerHeartbeatLifecycleIsDurable(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $subscriber = $this->phase4Service(WorkerHeartbeatSubscriber::class);
        $subscriber->started();
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM worker_heartbeat WHERE worker_kind = 'worker' AND stopped_at IS NULL")));
        $subscriber->running();
        $subscriber->stopped();
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM worker_heartbeat WHERE worker_kind = 'worker' AND stopped_at IS NOT NULL")));
    }

    public function testPermanentFailureIsTerminalAndDeadLetterEligible(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-permanent@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        try {
            ($this->faultHandler($connection, 'permanent'))($queued);
            self::fail('Permanent delivery failure must be dead-letter eligible.');
        } catch (UnrecoverableMessageHandlingException $deadLetterFailure) {
            self::assertStringNotContainsString('recipient@example.test', serialize($deadLetterFailure));
        }
        self::assertSame('permanent_failure', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame('completed', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
    }

    public function testPauseResumeAndCancelPreventUnclaimedDelivery(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-control@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $this->transport()->reset();
        $campaigns->queue($actor, $campaignId);
        $queued = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $queued);
        $campaigns->pause($actor, $campaignId);
        ($this->faultHandler($connection, 'accepted'))($queued);
        self::assertSame('pending', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        $campaigns->resume($actor, $campaignId);
        $campaigns->cancel($actor, $campaignId);
        ($this->faultHandler($connection, 'accepted'))($queued);
        self::assertSame('cancelled', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$queued->recipientId]));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt WHERE campaign_recipient_id = ?', [$queued->recipientId])));
    }

    public function testGlobalRateLimitIsSharedAcrossCampaigns(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $connection->executeStatement("UPDATE delivery_policy SET messages_per_minute = 1 WHERE singleton_key = '1'");
        $actor = $this->createPhase4User($connection, 'phase6-rate@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$firstCampaigns, $firstId] = $this->campaign($connection, $actor);
        $firstCampaigns->submitForReview($actor, $firstId);
        $firstCampaigns->approve($actor, $firstId, true);
        $this->transport()->reset();
        $firstCampaigns->queue($actor, $firstId);
        $firstMessage = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $firstMessage);
        ($this->faultHandler($connection, 'accepted'))($firstMessage);

        [$secondCampaigns, $secondId] = $this->campaign($connection, $actor);
        $secondCampaigns->submitForReview($actor, $secondId);
        $secondCampaigns->approve($actor, $secondId, true);
        $this->transport()->reset();
        $secondCampaigns->queue($actor, $secondId);
        $secondMessage = $this->transport()->getSent()[0]->getMessage();
        self::assertInstanceOf(DeliverCampaignRecipient::class, $secondMessage);
        $this->transport()->reset();
        ($this->faultHandler($connection, 'accepted'))($secondMessage);
        self::assertSame('pending', $connection->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$secondMessage->recipientId]));
        self::assertInstanceOf(DelayStamp::class, $this->transport()->getSent()[0]->last(DelayStamp::class));
    }

    public function testConcurrentMaterializationCreatesOneLedgerRow(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $actor = $this->createPhase4User($connection, 'phase6-concurrent@example.test', 'phase6-password-'.bin2hex(random_bytes(8)));
        [$campaigns, $campaignId] = $this->campaign($connection, $actor);
        $campaigns->submitForReview($actor, $campaignId);
        $campaigns->approve($actor, $campaignId, true);
        $connection->update('campaign', ['state' => 'queueing', 'operation_version' => 3], ['id' => $campaignId]);
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        $firstSignal = sys_get_temp_dir().'/emailer-phase6-materialize-'.bin2hex(random_bytes(8)).'-one';
        $secondSignal = $firstSignal.'-two';
        self::ensureKernelShutdown();
        $first = new Process([PHP_BINARY, 'tests/Support/phase6-materialize-worker.php', $campaignId, '3', $firstSignal, $secondSignal], $projectDirectory);
        $second = new Process([PHP_BINARY, 'tests/Support/phase6-materialize-worker.php', $campaignId, '3', $secondSignal, $firstSignal], $projectDirectory);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        @unlink($firstSignal);
        @unlink($secondSignal);
        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        self::assertSame('done', $first->getOutput());
        self::assertSame('done', $second->getOutput());

        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM campaign_recipient WHERE campaign_id = ?', [$campaignId])));
        self::assertSame('sending', $connection->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
    }

    /** @return array{CampaignManager, string, string} */
    private function campaign(Connection $connection, AuthenticatedUser $actor): array
    {
        $storedContactId = $connection->fetchOne('SELECT id FROM contact WHERE email_canonical = ?', ['recipient@example.test']);
        $contactId = \is_string($storedContactId) ? $storedContactId : Identity::uuidV7();
        $listId = Identity::uuidV7();
        $now = Identity::now();
        if (!\is_string($storedContactId)) {
            $connection->insert('contact', ['id' => $contactId, 'email_entered' => 'recipient@example.test', 'email_canonical' => 'recipient@example.test', 'display_name' => 'Recipient', 'first_name' => 'Rae', 'last_name' => null, 'locale' => 'en_AU', 'time_zone' => 'Australia/Sydney', 'status' => 'active', 'consent_source' => 'phase6-test', 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        }
        $connection->insert('contact_list', ['id' => $listId, 'name' => 'Phase 6 audience '.substr($listId, -6), 'created_at' => $now, 'updated_at' => $now, 'archived_at' => null]);
        $connection->insert('list_membership', ['contact_id' => $contactId, 'list_id' => $listId, 'source' => 'manual', 'created_at' => $now]);
        $templates = $this->phase4Service(TemplateManager::class);
        $templateId = $templates->create($actor, 'Phase 6 template '.substr($listId, -6), 'Hello {{ contact.first_name }}', '', '<p>Phase 6 body for {{ campaign.name }}</p>', 'Phase 6 body for {{ campaign.name }}');
        $versions = $templates->versions($actor, $templateId);
        self::assertNotEmpty($versions);
        $versionId = $versions[0]['id'];
        self::assertIsString($versionId);
        $templates->publish($actor, $templateId, $versionId);
        $campaigns = $this->phase4Service(CampaignManager::class);
        $campaignId = $campaigns->create($actor, 'Phase 6 campaign', $versionId, [$listId]);

        return [$campaigns, $campaignId, $contactId];
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function faultHandler(Connection $connection, string $outcome): DeliverCampaignRecipientHandler
    {
        return new DeliverCampaignRecipientHandler(
            $connection,
            $this->phase4Service(DeliveryConfiguration::class),
            $this->phase4Service(SuppressionLookup::class),
            new Phase6FaultTransport($outcome),
            $this->phase4Service(CampaignDeliveryGateway::class),
            $this->phase4Service(RecipientUnsubscribeLinks::class),
            $this->phase4Service(AuditSink::class),
            $this->phase4Service(\App\Module\Delivery\Application\Contract\SendingGate::class),
        );
    }
}

final readonly class Phase6FaultTransport implements DeliveryTransport
{
    public function __construct(private string $outcome)
    {
    }

    public function send(OutboundMessage $message): string
    {
        if ('accepted' === $this->outcome) {
            return $message->messageId;
        }
        throw new DeliveryTransportFailure($this->outcome);
    }
}
