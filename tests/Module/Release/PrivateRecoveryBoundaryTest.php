<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\EditionRecoverySet;
use PHPUnit\Framework\TestCase;

final class PrivateRecoveryBoundaryTest extends TestCase
{
    public function testReadableAssetDirectoryRequiresAnIntactPrivateAncestor(): void
    {
        $root = sys_get_temp_dir().'/emailer-recovery-boundary-'.bin2hex(random_bytes(8));
        mkdir($root, 0o700);
        mkdir($root.'/template-assets', 0o755);
        mkdir($root.'/outside', 0o755);
        mkdir($root.'/template-assets-other', 0o755);
        file_put_contents($root.'/template-assets/image', 'synthetic image');
        chmod($root.'/template-assets/image', 0o644);
        file_put_contents($root.'/outside/private-state', 'synthetic state');
        chmod($root.'/outside/private-state', 0o644);
        try {
            EditionRecoverySet::safePrivate($root.'/template-assets', true, $root);
            EditionRecoverySet::safePrivate($root.'/template-assets/image', false, $root);
            foreach ([$root.'/outside', $root.'/template-assets'] as $path) {
                try {
                    EditionRecoverySet::safePrivate($path, true);
                    self::fail('Readable directory was accepted without a private ancestor.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('metadata is unsafe', $exception->getMessage());
                }
            }
            foreach ([$root.'/outside', $root.'/template-assets-other'] as $path) {
                try {
                    EditionRecoverySet::safePrivate($path, true, $root);
                    self::fail('Readable non-asset directory was accepted beneath protected root.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('metadata is unsafe', $exception->getMessage());
                }
            }
            foreach ([$root.'/outside/private-state', $root.'/template-assets/../outside/private-state'] as $path) {
                try {
                    EditionRecoverySet::safePrivate($path, false, $root);
                    self::fail('Asset permission allowance escaped its own tree.');
                } catch (\RuntimeException) {
                    self::assertTrue(true);
                }
            }
            link($root.'/template-assets/image', $root.'/template-assets/linked-image');
            try {
                EditionRecoverySet::safePrivate($root.'/template-assets/image', false, $root);
                self::fail('Hardlinked asset was accepted.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
            unlink($root.'/template-assets/linked-image');
            symlink($root.'/outside/private-state', $root.'/template-assets/outside-image');
            try {
                EditionRecoverySet::safePrivate($root.'/template-assets/outside-image', false, $root);
                self::fail('Linked asset was accepted.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
            unlink($root.'/template-assets/outside-image');
            chmod($root, 0o755);
            try {
                EditionRecoverySet::safePrivate($root.'/template-assets', true, $root);
                self::fail('Publicly traversable private root was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('metadata is unsafe', $exception->getMessage());
            }
            chmod($root, 0o700);
            chmod($root.'/template-assets', 0o775);
            try {
                EditionRecoverySet::safePrivate($root.'/template-assets', true, $root);
                self::fail('Group-writable descendant was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('metadata is unsafe', $exception->getMessage());
            }
            chmod($root.'/template-assets', 0o700);
            $this->expectExceptionMessage('escapes its protected root');
            EditionRecoverySet::safePrivate($root.'/outside', true, $root.'/template-assets');
        } finally {
            unlink($root.'/template-assets/image');
            unlink($root.'/outside/private-state');
            rmdir($root.'/template-assets');
            rmdir($root.'/outside');
            rmdir($root.'/template-assets-other');
            rmdir($root);
        }
    }
}
