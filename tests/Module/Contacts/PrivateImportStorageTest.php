<?php

declare(strict_types=1);

namespace App\Tests\Module\Contacts;

use App\Module\Contacts\Infrastructure\PrivateImportStorage;
use PHPUnit\Framework\TestCase;

final class PrivateImportStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-import-storage-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $entries = scandir($this->directory);
            self::assertIsArray($entries);
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    unlink($this->directory.'/'.$entry);
                }
            }
            rmdir($this->directory);
        }
    }

    public function testFilesArePrivateIntegrityCheckedAndReportsContainOnlySafeReasons(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'emailer-import-source-');
        self::assertIsString($source);
        file_put_contents($source, "email\nperson@example.test\n");
        $storage = new PrivateImportStorage($this->directory);
        try {
            $stored = $storage->store($source);
            $path = $this->directory.'/'.$stored['token'].'.csv';
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(hash_file('sha256', $path), $stored['sha256']);
            file_put_contents($path, 'tampered', FILE_APPEND);
            $this->expectException(\RuntimeException::class);
            $storage->open($stored['token'], $stored['sha256']);
        } finally {
            unlink($source);
        }
    }

    public function testSymlinkUploadsAndSensitiveReportLinesAreRejected(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'emailer-import-source-');
        self::assertIsString($source);
        file_put_contents($source, 'safe');
        $link = $source.'.link';
        symlink($source, $link);
        $storage = new PrivateImportStorage($this->directory);
        try {
            try {
                $storage->store($link);
                self::fail('A symlink import was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            try {
                $storage->open('../'.str_repeat('a', 45), str_repeat('0', 64));
                self::fail('A traversal-shaped import token was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            $this->expectException(\RuntimeException::class);
            $storage->writeReport(str_repeat('a', 48), ['row 2: victim@example.test']);
        } finally {
            unlink($link);
            unlink($source);
        }
    }
}
