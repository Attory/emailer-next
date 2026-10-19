<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\AppearanceSettings;
use App\Module\Configuration\Application\Contract\PublicWebsiteClient;
use App\Module\Configuration\Application\WebsiteBranding;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Shared\MaintenanceOperationLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AppearanceSettingsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-appearance-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*', GLOB_ERR);
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        if (file_exists($this->directory.'/.lock')) {
            unlink($this->directory.'/.lock');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testUpdateKeepsClassicFreshDefaultsToStudioAndChoicesArePerUserAndPersisted(): void
    {
        $owner = $this->actor('owner');
        $other = $this->actor('other');
        $settings = $this->settings();
        self::assertSame('classic', $settings->view($owner)['skin']);
        $settings->initializeFresh();
        self::assertSame('studio', $settings->view($owner)['skin']);
        $settings->save($owner, 'warm', 'reduced');
        $settings->initializeFresh();
        self::assertSame('warm', $this->settings()->view($owner)['skin']);
        self::assertSame('reduced', $this->settings()->view($owner)['motion']);
        self::assertSame('studio', $settings->view($other)['skin']);
        self::assertSame(0o700, fileperms($this->directory) & 0o777);
        $files = glob($this->directory.'/*.json', GLOB_ERR);
        foreach (false === $files ? [] : $files as $file) {
            self::assertSame(0o600, fileperms($file) & 0o777);
        }
    }

    public function testRefreshManualCorrectionsAndOfflineFallbackAreCachedWithoutViewNetworkRequests(): void
    {
        $client = $this->createMock(PublicWebsiteClient::class);
        $client->expects(self::once())->method('get')->willReturn(['url' => 'https://example.com/', 'type' => 'text/html', 'body' => '<meta name="theme-color" content="#765432">']);
        $settings = $this->settings($client);
        $owner = $this->actor('owner');
        $saved = $settings->changeBranding($owner, 'refresh', 'https://example.com/', []);
        for ($i = 0; 5 > $i; ++$i) {
            self::assertSame($saved, $settings->view($owner)['branding']);
        }
        $tokens = $saved['tokens'];
        $tokens['accent'] = '#234567';
        $corrected = $settings->changeBranding($owner, 'save', 'https://example.com/', $tokens);
        self::assertSame($saved['refreshed_at'], $corrected['refreshed_at']);
        $failedClient = $this->createMock(PublicWebsiteClient::class);
        $failedClient->expects(self::once())->method('get')->willThrowException(new \RuntimeException('Synthetic outage'));
        unlink($this->directory.'/fetch-budget.json');
        try {
            $this->settings($failedClient)->changeBranding($owner, 'refresh', 'https://example.com/', []);
            self::fail('Outage was not reported');
        } catch (\RuntimeException) {
            self::assertSame($corrected, $settings->view($owner)['branding']);
        }
    }

    public function testAuditorMayChangeOwnSkinButCannotChangeSharedBranding(): void
    {
        $actor = $this->actor('auditor', AdministrativeRole::Auditor);
        $settings = $this->settings();
        self::assertSame('website', $settings->save($actor, 'website', 'system')['skin']);
        $this->expectException(AccessDeniedException::class);
        $settings->changeBranding($actor, 'save', '', WebsiteBranding::defaults());
    }

    public function testRefreshIsRateLimitedAcrossAdministrators(): void
    {
        $client = $this->createMock(PublicWebsiteClient::class);
        $client->expects(self::once())->method('get')->willReturn(['url' => 'https://example.com/', 'type' => 'text/html', 'body' => '<html></html>']);
        $settings = $this->settings($client);
        $settings->changeBranding($this->actor('first'), 'refresh', 'https://example.com/', []);
        $this->expectException(\InvalidArgumentException::class);
        $settings->changeBranding($this->actor('second'), 'refresh', 'https://example.com/', []);
    }

    public function testUnsafeStorageDoesNotOverwriteExistingFiles(): void
    {
        $settings = $this->settings();
        $owner = $this->actor('owner');
        $settings->initializeFresh();
        $external = $this->directory.'/external.json';
        file_put_contents($external, 'preserve');
        chmod($external, 0o600);
        symlink($external, $this->directory.'/user-'.hash('sha256', $owner->id()).'.json');
        try {
            $settings->save($owner, 'warm', 'system');
            self::fail('Unsafe symlink accepted');
        } catch (\RuntimeException) {
            self::assertSame('preserve', file_get_contents($external));
            self::assertSame('studio', $settings->view($owner)['skin']);
        }
    }

    private function actor(string $id, AdministrativeRole $role = AdministrativeRole::Owner): AuthenticatedUser
    {
        return new AuthenticatedUser($id, $id.'@example.test', 'synthetic-unusable-hash', $role, true);
    }

    private function settings(?PublicWebsiteClient $client = null): AppearanceSettings
    {
        $authorization = self::createStub(AdministrativeAuthorization::class);
        $authorization->method('assertAtLeastRank')->willReturnCallback(static function (AdministrativeActor $actor, AdministrativeAccessLevel $rank): void {
            if (!$actor->active() || $actor->roleRank() < $rank->value) {
                throw new AccessDeniedException();
            }
        });

        return new AppearanceSettings($this->directory, new WebsiteBranding($client ?? self::createStub(PublicWebsiteClient::class)), $authorization, self::createStub(AuditSink::class), new MaintenanceOperationLock(new LockFactory(new FlockStore())));
    }
}
