<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use App\Module\Secrets\Infrastructure\FilesystemMasterKey;
use PHPUnit\Framework\TestCase;

final class FilesystemMasterKeyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/emailer-key-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700));
        self::assertTrue(mkdir($this->root.'/release', 0o700));
        self::assertTrue(mkdir($this->root.'/release/public', 0o700));
        self::assertTrue(mkdir($this->root.'/external', 0o700));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCreationProducesRestrictiveExternalKey(): void
    {
        $provider = $this->provider();
        $provider->create();
        $key = $provider->load();

        self::assertSame(32, \strlen($key));
        sodium_memzero($key);
        self::assertSame(0o700, fileperms($this->root.'/external/secrets') & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/external/secrets/master.key') & 0o777);
        $metadata = stat($this->root.'/external/secrets/master.key');
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['nlink']);
    }

    public function testOmittedRelativeReleaseAndPublicConfigurationFailClosed(): void
    {
        foreach ([
            new FilesystemMasterKey('', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey('secrets/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey($this->root.'/release/private/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey($this->root.'/release/public/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
        ] as $provider) {
            $this->expectCreateFailure($provider);
        }
    }

    public function testExplicitTestPathMayBeIsolatedInsideProjectTree(): void
    {
        $provider = new FilesystemMasterKey(
            $this->root.'/release/var/test-secrets/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'test',
        );
        $provider->create();
        $key = $provider->load();
        self::assertSame(32, \strlen($key));
        sodium_memzero($key);
    }

    public function testMissingMalformedUnsafeSymlinkHardlinkAndNestedSymlinkEscapeFailClosed(): void
    {
        $this->expectLoadFailure($this->provider());

        self::assertTrue(mkdir($this->root.'/external/secrets', 0o700));
        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', 'malformed'));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o600));
        $this->expectLoadFailure($this->provider());

        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o644));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(unlink($this->root.'/external/secrets/master.key'));
        self::assertTrue(symlink('/dev/null', $this->root.'/external/secrets/master.key'));
        $this->expectLoadFailure($this->provider());
        self::assertTrue(unlink($this->root.'/external/secrets/master.key'));

        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o600));
        self::assertTrue(link($this->root.'/external/secrets/master.key', $this->root.'/external/secrets/master.link'));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(mkdir($this->root.'/escape', 0o700));
        self::assertTrue(symlink($this->root.'/escape', $this->root.'/external/nested-link'));
        $escapeProvider = new FilesystemMasterKey(
            $this->root.'/external/nested-link/child/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'prod',
        );
        $this->expectCreateFailure($escapeProvider);
    }

    public function testUnsafeImmediateAndEarlierAncestorDirectoriesFailClosed(): void
    {
        $this->provider()->create();
        self::assertTrue(chmod($this->root.'/external/secrets', 0o755));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(chmod($this->root.'/external/secrets', 0o700));
        self::assertTrue(chmod($this->root.'/external', 0o777));
        $this->expectLoadFailure($this->provider());
    }

    private function provider(): FilesystemMasterKey
    {
        return new FilesystemMasterKey(
            $this->root.'/external/secrets/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'prod',
        );
    }

    private function expectCreateFailure(FilesystemMasterKey $provider): void
    {
        try {
            $provider->create();
            self::fail('Unsafe master-key configuration was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('master', strtolower($exception->getMessage()));
        }
    }

    private function expectLoadFailure(FilesystemMasterKey $provider): void
    {
        try {
            $provider->load();
            self::fail('Unsafe master key was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('master', strtolower($exception->getMessage()));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }

            return;
        }

        $entries = scandir($directory);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->removeDirectory($directory.'/'.$entry);
                }
            }
        }
        rmdir($directory);
    }
}
