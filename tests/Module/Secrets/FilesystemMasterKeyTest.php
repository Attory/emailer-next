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
        self::assertTrue(mkdir($this->root.'/public', 0o755));
    }

    protected function tearDown(): void
    {
        foreach ([$this->root.'/secrets/master.link', $this->root.'/secrets/master.key', $this->root.'/public/master.key'] as $file) {
            if (file_exists($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->root.'/secrets')) {
            rmdir($this->root.'/secrets');
        }
        rmdir($this->root.'/public');
        rmdir($this->root);
    }

    public function testCreationProducesRestrictiveExternalKey(): void
    {
        $provider = $this->provider();
        $provider->create();
        $key = $provider->load();

        self::assertSame(32, \strlen($key));
        sodium_memzero($key);
        self::assertSame(0o700, fileperms($this->root.'/secrets') & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/secrets/master.key') & 0o777);
        $metadata = stat($this->root.'/secrets/master.key');
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['nlink']);
    }

    public function testMissingMalformedUnsafeSymlinkHardlinkAndPublicLocationFailClosed(): void
    {
        $this->expectLoadFailure($this->provider());

        self::assertTrue(mkdir($this->root.'/secrets', 0o700));
        self::assertNotFalse(file_put_contents($this->root.'/secrets/master.key', 'malformed'));
        self::assertTrue(chmod($this->root.'/secrets/master.key', 0o600));
        $this->expectLoadFailure($this->provider());

        self::assertNotFalse(file_put_contents($this->root.'/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/secrets/master.key', 0o644));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(unlink($this->root.'/secrets/master.key'));
        self::assertTrue(symlink('/dev/null', $this->root.'/secrets/master.key'));
        $this->expectLoadFailure($this->provider());
        self::assertTrue(unlink($this->root.'/secrets/master.key'));

        self::assertNotFalse(file_put_contents($this->root.'/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/secrets/master.key', 0o600));
        self::assertTrue(link($this->root.'/secrets/master.key', $this->root.'/secrets/master.link'));
        $this->expectLoadFailure($this->provider());

        $publicProvider = new FilesystemMasterKey($this->root.'/public/master.key', $this->root.'/public');
        $this->expectLoadFailure($publicProvider);
    }

    public function testUnsafeParentDirectoryFailsClosed(): void
    {
        $this->provider()->create();
        self::assertTrue(chmod($this->root.'/secrets', 0o755));
        $this->expectLoadFailure($this->provider());
    }

    private function provider(): FilesystemMasterKey
    {
        return new FilesystemMasterKey($this->root.'/secrets/master.key', $this->root.'/public');
    }

    private function expectLoadFailure(FilesystemMasterKey $provider): void
    {
        try {
            $provider->load();
            self::fail('Unsafe master key was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Master', $exception->getMessage());
        }
    }
}
