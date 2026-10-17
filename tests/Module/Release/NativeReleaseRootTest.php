<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\NativeReleaseRoot;
use PHPUnit\Framework\TestCase;

final class NativeReleaseRootTest extends TestCase
{
    public function testRecoveryRetainsConvertedRootAndRejectsOutsideOrSymlinkSubstitution(): void
    {
        $root = sys_get_temp_dir().'/emailer-native-root-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700));
        foreach (['releases', 'outside', 'releases/1.1.0', 'releases/ripe-1.1.0'] as $path) {
            self::assertTrue(mkdir($root.'/'.$path, 0o700));
        }
        try {
            foreach (['1.1.0', 'ripe-1.1.0'] as $name) {
                self::assertSame($root.'/releases/'.$name, NativeReleaseRoot::source($root.'/releases', '1.1.0', $root.'/releases/'.$name));
            }
            foreach ([$root.'/outside', $root.'/releases/1.0.6', $root.'/releases/ripe-1.1.0/../1.1.0', null] as $path) {
                try {
                    NativeReleaseRoot::source($root.'/releases', '1.1.0', $path);
                    self::fail('Recovery accepted an unrelated or missing source root.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('outside its exact immutable release directory', $exception->getMessage());
                }
            }
            self::assertTrue(rmdir($root.'/releases/1.1.0'));
            self::assertTrue(symlink($root.'/outside', $root.'/releases/1.1.0'));
            $this->expectExceptionMessage('outside its exact immutable release directory');
            NativeReleaseRoot::source($root.'/releases', '1.1.0', $root.'/releases/1.1.0');
        } finally {
            if (is_link($root.'/releases/1.1.0')) {
                unlink($root.'/releases/1.1.0');
            } else {
                rmdir($root.'/releases/1.1.0');
            }
            rmdir($root.'/releases/ripe-1.1.0');
            rmdir($root.'/releases');
            rmdir($root.'/outside');
            rmdir($root);
        }
    }
}
