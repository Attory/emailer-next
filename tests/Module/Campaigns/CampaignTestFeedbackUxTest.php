<?php

declare(strict_types=1);

namespace App\Tests\Module\Campaigns;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Campaigns\Application\CampaignRecipientCsv;
use App\Module\Campaigns\Application\Contract\SendingGate;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Contacts\Application\Contract\AudienceProvider;
use App\Module\Contacts\Application\Contract\CampaignAudiencePreparation;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\DeliverCampaignTestHandler;
use App\Module\Delivery\Application\Message\DeliverCampaignTest;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Module\Templates\Application\Contract\CampaignTemplatePreparation;
use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use App\Shared\Identity;
use App\Tests\Support\AdminUxSqlite;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class CampaignTestFeedbackUxTest extends TestCase
{
    private Connection $db;
    private AdministrativeAuthorization $authorization;
    private AuditSink $audit;
    private AuthenticatedUser $actor;
    private string $campaign;
    private PublishedTemplateProvider $templates;
    private DeliveryConfiguration $configuration;
    private SendingGate $gate;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => AdminUxSqlite::class]);
        foreach ([
            'CREATE TABLE campaign(id TEXT PRIMARY KEY,name TEXT,state TEXT,operation_version INTEGER,template_version_id TEXT,rsvp_enabled INTEGER)',
            'CREATE TABLE campaign_test_delivery(id TEXT PRIMARY KEY,campaign_id TEXT,requested_by TEXT,recipient_address TEXT,state TEXT,failure_class TEXT,requested_at TEXT,accepted_at TEXT,draft_version INTEGER)',
            'CREATE TABLE delivery_runtime(singleton_key TEXT,schema_version INTEGER,accepting_work INTEGER)',
            'CREATE TABLE worker_heartbeat(worker_kind TEXT,heartbeat_at TEXT)',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->db->insert('delivery_runtime', ['singleton_key' => '1', 'schema_version' => 1, 'accepting_work' => 1]);
        $this->db->insert('worker_heartbeat', ['worker_kind' => 'worker', 'heartbeat_at' => Identity::now()]);
        $this->campaign = Identity::uuidV7();
        $this->db->insert('campaign', ['id' => $this->campaign, 'name' => 'Synthetic campaign', 'state' => 'draft', 'operation_version' => 2, 'template_version_id' => Identity::uuidV7(), 'rsvp_enabled' => 0]);
        $this->actor = new AuthenticatedUser(Identity::uuidV7(), 'requester@example.test', 'unused', AdministrativeRole::Operator, true);
        $this->authorization = self::createStub(AdministrativeAuthorization::class);
        $this->audit = self::createStub(AuditSink::class);
        $this->templates = self::createStub(PublishedTemplateProvider::class);
        $this->templates->method('get')->willReturn(['id' => Identity::uuidV7(), 'subject' => 'Synthetic subject', 'preheader' => null, 'html' => '<p>Fixture</p>', 'text' => 'Fixture', 'merge_fields' => [], 'links' => [], 'content_sha256' => str_repeat('a', 64)]);
        $this->configuration = self::createStub(DeliveryConfiguration::class);
        $this->configuration->method('current')->willReturn(['installation_id' => Identity::uuidV7(), 'sender_address' => 'sender@example.test', 'sender_name' => 'Fixture', 'reply_to' => null]);
        $this->gate = self::createStub(SendingGate::class);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function manager(CampaignDeliveryGateway $gateway): CampaignManager
    {
        return new CampaignManager($this->db, $this->authorization, $this->audit, self::createStub(AudienceProvider::class), self::createStub(CampaignAudiencePreparation::class), $this->templates, self::createStub(CampaignTemplatePreparation::class), $this->configuration, $gateway, $this->gate, new CampaignRecipientCsv($this->db, $this->authorization, $this->audit));
    }

    public function testOneDispatchForRepeatedRequestAndPendingClicksThenAcceptedActualDestination(): void
    {
        $gateway = $this->createMock(CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchTest');
        $manager = $this->manager($gateway);
        $nonce = Identity::uuidV7();
        $id = $manager->requestTest($this->actor, $this->campaign, $nonce);
        self::assertSame($nonce, $id);
        self::assertSame($id, $manager->requestTest($this->actor, $this->campaign, $nonce));
        self::assertSame($id, $manager->requestTest($this->actor, $this->campaign, Identity::uuidV7()));
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery'));
        $pendingResult = $manager->testResult($this->actor, $this->campaign, $id);
        self::assertSame('pending', $pendingResult['state']);
        $transport = $this->createMock(DeliveryTransport::class);
        $transport->expects(self::once())->method('send')->with(self::callback(static fn (\App\Module\Delivery\Application\Contract\OutboundMessage $message): bool => 'requester@example.test' === $message->recipient))->willReturn('synthetic-receipt');
        $this->handler($transport)(new DeliverCampaignTest($id));
        $result = $manager->testResult($this->actor, $this->campaign, $id);
        self::assertSame('accepted', $result['state']);
        self::assertTrue($result['terminal']);
        self::assertSame('Test email sent to requester@example.test.', $result['message']);
        self::assertSame($id, $manager->requestTest($this->actor, $this->campaign, $nonce));
        self::assertNotNull($this->db->fetchOne('SELECT accepted_at FROM campaign_test_delivery WHERE id=?', [$id]));
        $other = new AuthenticatedUser(Identity::uuidV7(), 'other@example.test', 'unused', AdministrativeRole::Operator, true);
        $this->expectException(\InvalidArgumentException::class);
        $manager->testResult($other, $this->campaign, $id);
    }

    public function testWaitingPauseAndProcessingShowRealStateWithoutDispatchingAgain(): void
    {
        $manager = $this->manager(self::createStub(CampaignDeliveryGateway::class));
        $id = $manager->requestTest($this->actor, $this->campaign);
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');
        self::assertStringContainsString('Delivery is paused', $manager->testResult($this->actor, $this->campaign, $id)['message']);
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=1');
        $this->db->executeStatement('DELETE FROM worker_heartbeat');
        self::assertStringContainsString('no recent heartbeat', $manager->testResult($this->actor, $this->campaign, $id)['message']);
        $this->db->update('campaign_test_delivery', ['state' => 'processing'], ['id' => $id]);
        self::assertStringContainsString('worker is processing', $manager->testResult($this->actor, $this->campaign, $id)['message']);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery'));
    }

    #[DataProvider('safeFailures')]
    public function testWorkerFailureIsActionableAndNeverExposesTransportDetail(string $reason, string $expected): void
    {
        $manager = $this->manager(self::createStub(CampaignDeliveryGateway::class));
        $id = $manager->requestTest($this->actor, $this->campaign);
        $transport = $this->createMock(DeliveryTransport::class);
        $transport->expects(self::once())->method('send')->willThrowException(new DeliveryTransportFailure('permanent', 'Synthetic credential must not appear', reason: $reason));
        try {
            $this->handler($transport)(new DeliverCampaignTest($id));
            self::fail('Failure must terminate worker handling.');
        } catch (UnrecoverableMessageHandlingException) {
            $result = $manager->testResult($this->actor, $this->campaign, $id);
            self::assertSame('failed', $result['state']);
            self::assertTrue($result['terminal']);
            self::assertStringContainsString($expected, $result['message']);
            self::assertStringNotContainsString('Synthetic credential', $result['message']);
            $this->handler($transport)(new DeliverCampaignTest($id)); // Duplicate worker envelope never invokes transport again.
        }
    }

    /** @return iterable<array{string, string}> */
    public static function safeFailures(): iterable
    {
        yield ['smtp_auth', 'authentication failed'];
        yield ['smtp_tls', 'secure SMTP'];
        yield ['smtp_connect', 'could not be reached'];
        yield ['smtp_rejected', 'server rejected'];
        yield ['ambiguous', 'before considering another send'];
    }

    public function testSupersededAndSuppressedTestsNeverInvokeTransport(): void
    {
        $manager = $this->manager(self::createStub(CampaignDeliveryGateway::class));
        $id = $manager->requestTest($this->actor, $this->campaign);
        $transport = $this->createMock(DeliveryTransport::class);
        $transport->expects(self::never())->method('send');
        $this->db->executeStatement('UPDATE campaign SET operation_version=3');
        $this->handler($transport)(new DeliverCampaignTest($id));
        self::assertStringContainsString('draft changed', $manager->testResult($this->actor, $this->campaign, $id)['message']);
        $id = $manager->requestTest($this->actor, $this->campaign);
        try {
            $this->handler($transport, true)(new DeliverCampaignTest($id));
            self::fail('Suppression must terminate the test.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertStringContainsString('unsubscribed or suppressed', $manager->testResult($this->actor, $this->campaign, $id)['message']);
        }
    }

    public function testHttpPostRetainsCsrfAndReturnsSpecificAcceptedOrPendingResult(): void
    {
        $gateway = $this->createMock(CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchTest')->willReturnCallback(function (string $id): void {
            $this->db->update('campaign_test_delivery', ['state' => 'accepted', 'accepted_at' => Identity::now()], ['id' => $id]);
        });
        $manager = $this->manager($gateway);
        $controller = new \App\Module\Campaigns\Presentation\Http\CampaignsController($manager, (new \ReflectionClass(\App\Module\Identity\Application\ReauthenticationService::class))->newInstanceWithoutConstructor(), self::createStub(CampaignAudiencePreparation::class), self::createStub(CampaignTemplatePreparation::class));
        $container = new \Symfony\Component\DependencyInjection\Container();
        $storage = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $storage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($this->actor, 'admin', ['ROLE_OPERATOR']));
        $container->set('security.token_storage', $storage);
        $authorization = self::createStub(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authorization);
        $csrf = self::createStub(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (\Symfony\Component\Security\Csrf\CsrfToken $token): bool => 'synthetic-valid' === $token->getValue());
        $container->set('security.csrf.token_manager', $csrf);
        $controller->setContainer($container);
        $request = \Symfony\Component\HttpFoundation\Request::create('/not-dispatched', 'POST', ['_token' => 'invalid', 'request_id' => Identity::uuidV7()], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        self::assertSame(400, $controller->test($request, $this->campaign)->getStatusCode());
        self::assertSame(0, $this->db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery'));
        $request->request->set('_token', 'synthetic-valid');
        $response = $controller->test($request, $this->campaign);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $content = $response->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['id']);
        self::assertSame('Test email sent to requester@example.test.', $data['message']);
        self::assertSame($request->request->get('request_id'), $data['id']);
        self::assertSame(200, $controller->testResult($this->campaign, $data['id'])->getStatusCode());
        $this->db->update('campaign_test_delivery', ['state' => 'pending', 'accepted_at' => null], ['id' => $data['id']]);
        $response = $controller->test($request, $this->campaign); // Same ID waits briefly, then exposes real pending state.
        self::assertSame(202, $response->getStatusCode());
        $content = $response->getContent();
        self::assertIsString($content);
        $pending = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($pending);
        self::assertFalse($pending['terminal']);
        self::assertSame(1, $this->db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery'));
    }

    private function handler(DeliveryTransport $transport, bool $suppressed = false): DeliverCampaignTestHandler
    {
        $lookup = self::createStub(SuppressionLookup::class);
        $lookup->method('isSuppressed')->willReturn($suppressed);

        return new DeliverCampaignTestHandler($this->db, $this->templates, $this->configuration, $transport, $lookup, self::createStub(\App\Module\Delivery\Application\Contract\SendingGate::class), self::createStub(CampaignDeliveryGateway::class));
    }
}
