<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Application\Contract\BackupContributor;
use App\Module\Configuration\Application\AppearanceSettings;
use App\Module\Configuration\Application\Contract\PublicWebsiteClient;
use App\Module\Configuration\Application\WebsiteBranding;
use App\Module\Configuration\Infrastructure\AppearanceBackupContributor;
use App\Module\Configuration\Presentation\Http\AppearanceController;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthorizationService;
use App\Module\Identity\Application\RoutePolicyRegistry;
use App\Module\Identity\Presentation\Http\SecurityHeadersSubscriber;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Real application services, encrypted archives and filesystem; no DB tables or network are used. */
final class AppearanceBackupIntegrationTest extends TestCase
{
    private string $directory;
    private string $key;
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-appearance-backup-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o700));
        self::assertTrue(mkdir($this->directory.'/locks', 0o700));
        $this->key = random_bytes(32);
        $this->locks = new LockFactory(new FlockStore($this->directory.'/locks'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    public function testSavedLogoBrandingAndAdministratorPreferencesSurviveEncryptedRestoreAndRemainAuthenticated(): void
    {
        $icon = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($icon);
        $source = ob_get_clean();
        unset($icon);
        $client = $this->createMock(PublicWebsiteClient::class);
        $client->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $url, ?string $origin, ?float $deadline) use ($source): array {
            self::assertNotNull($deadline);
            if ('https://example.com/' === $url) {
                self::assertNull($origin);

                return ['url' => $url, 'type' => 'text/html', 'body' => '<meta name="theme-color" content="#345678"><link rel="icon" href="/icon.png">'];
            }
            self::assertSame('https://example.com/icon.png', $url);
            self::assertSame('https://example.com/', $origin);

            return ['url' => $url, 'type' => 'image/png', 'body' => $source.'untrusted-source-metadata'];
        });
        $settings = $this->settings($client);
        $owner = $this->actor('owner');
        $other = $this->actor('other', AdministrativeRole::Auditor);
        $settings->initializeFresh();
        $settings->save($owner, 'warm', 'reduced');
        $branding = $settings->changeBranding($owner, 'refresh', 'https://example.com/', []);
        self::assertSame('/admin/appearance/logo.png', $branding['logo_url']);
        $logo = $settings->logo();
        self::assertNotNull($logo);
        self::assertStringNotContainsString('untrusted-source-metadata', $logo);
        $engine = $this->engine([new AppearanceBackupContributor($this->directory.'/appearance')]);
        $backup = $engine->create();
        $settings->save($owner, 'classic', 'system');
        $settings->changeBranding($owner, 'save', '', WebsiteBranding::defaults());
        self::assertNull($settings->logo());
        $engine->restore($backup['path']);
        self::assertSame('warm', $settings->view($owner)['skin']);
        self::assertSame('reduced', $settings->view($owner)['motion']);
        self::assertSame('studio', $settings->view($other)['skin']);
        self::assertSame($branding, $settings->view($owner)['branding']);
        self::assertSame($logo, $settings->logo());
        self::assertSame(0o700, fileperms($this->directory.'/appearance') & 0o777);
        self::assertSame(0o600, fileperms($this->directory.'/appearance/branding.json') & 0o777);
        // The application can still save after restore, whose root/lock inodes changed.
        self::assertSame('website', $settings->save($owner, 'website', 'system')['skin']);

        $policy = (new RoutePolicyRegistry())->policy('admin_appearance_logo');
        self::assertNotNull($policy);
        self::assertSame(AdministrativeRole::Auditor, $policy['role']);
        self::assertSame(['GET', 'HEAD'], $policy['methods']);
        $controller = new AppearanceController($settings);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($other, 'main', ['ROLE_AUDITOR']));
        $container = new Container();
        $container->set('security.token_storage', $tokens);
        $controller->setContainer($container);
        $response = $controller->logo();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($logo, $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        $request = Request::create('https://emailer.example.test/admin/appearance/logo.png');
        $request->attributes->set('_route', 'admin_appearance_logo');
        (new SecurityHeadersSubscriber())->harden(new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString("object-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $tokens->setToken(null);
        try {
            $controller->logo();
            self::fail('Anonymous logo access was accepted.');
        } catch (AccessDeniedException) {
            self::assertSame($logo, $settings->logo());
        }
        // A later backup still accepts the restored private metadata.
        $engine->verify($engine->create()['path']);
    }

    public function testPriorFormatBackupWithoutAppearanceResetsTheActualReaderToClassic(): void
    {
        // Same encrypted codec and schema as the predecessor; no appearance prefix.
        // This is a codec regression fixture, not a published-runtime rehearsal.
        $legacy = $this->engine([], '1.2.2');
        $backup = $legacy->create();
        $settings = $this->settings(self::createStub(PublicWebsiteClient::class));
        $owner = $this->actor('owner');
        $settings->initializeFresh();
        $settings->save($owner, 'website', 'reduced');
        $settings->changeBranding($owner, 'save', 'https://example.com/', WebsiteBranding::defaults());
        $current = $this->engine([new AppearanceBackupContributor($this->directory.'/appearance')]);
        $current->restore($backup['path']);
        self::assertSame('classic', $settings->view($owner)['skin']);
        self::assertSame('system', $settings->view($owner)['motion']);
        self::assertSame('', $settings->view($owner)['branding']['website_url']);
        self::assertNull($settings->logo());
        self::assertFileDoesNotExist($this->directory.'/appearance/default.json');
        self::assertSame(0o700, fileperms($this->directory.'/appearance') & 0o777);
        self::assertSame('warm', $settings->save($owner, 'warm', 'system')['skin']);
    }

    private function settings(PublicWebsiteClient $client): AppearanceSettings
    {
        $audit = self::createStub(AuditSink::class);

        return new AppearanceSettings($this->directory.'/appearance', new WebsiteBranding($client), new AuthorizationService($audit), $audit, new MaintenanceOperationLock($this->locks));
    }

    /** @param list<BackupContributor> $contributors */
    private function engine(array $contributors, string $sourceVersion = '1.3.0'): BackupEngine
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('DoctrineMigrations\\Version20261015000000');
        $connection->method('fetchAssociative')->willReturn(['accepting_work' => 0, 'active_claims' => 0]);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->expects(self::never())->method('executeQuery');
        $key = self::createStub(MasterKey::class);
        $key->method('load')->willReturn($this->key);

        return new BackupEngine($connection, $key, $this->locks, new MaintenanceOperationLock($this->locks), $contributors, $this->directory.'/backups', $sourceVersion);
    }

    private function actor(string $id, AdministrativeRole $role = AdministrativeRole::Owner): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id.'@example.test', 'synthetic-unusable-hash', $role, true);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                self::assertInstanceOf(\SplFileInfo::class, $entry);
                $this->removeTree($entry->getPathname());
            }
            rmdir($path);
        }
    }
}
