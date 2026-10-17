<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Release\Application\EditionRecoverySet;
use App\Shared\Identity;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class Ripe106RecoveryJournalTest extends KernelTestCase
{
    use Phase4State;

    public function testDatabaseAndPrivateRestoreCannotRewindTheDurableRecoveryPhase(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        self::assertNotNull(self::$kernel);
        $root = self::$kernel->getProjectDir().'/var/edition-state-'.Identity::uuidV7();
        $checkpoint = self::$kernel->getProjectDir().'/var/edition-checkpoint-'.Identity::uuidV7();
        EditionRecoverySet::directory($root);
        EditionRecoverySet::write($root.'/edition-delivery-hold.json', '{"phase":"backing_up"}');
        EditionRecoverySet::write($root.'/synthetic-private-state', 'source bytes');
        chmod($root.'/synthetic-private-state', 0o640);
        chmod($root, 0o750);
        mkdir($root.'/template-assets', 0o755);
        file_put_contents($root.'/template-assets/synthetic-image', 'source image bytes');
        chmod($root.'/template-assets/synthetic-image', 0o644);
        $recovery = new EditionRecoverySet($connection);
        $attempts = $connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt');
        try {
            $recovery->create($checkpoint, ['private' => $root]);
            EditionRecoverySet::write($root.'/edition-delivery-hold.json', '{"phase":"recovering"}');
            EditionRecoverySet::write($root.'/synthetic-private-state', 'target bytes', true);
            file_put_contents($root.'/template-assets/synthetic-image', 'target changed image bytes');
            EditionRecoverySet::write($root.'/synthetic-target-addition', 'retained target bytes');
            $connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
            $recovery->restore($checkpoint, $connection, true);
            self::assertSame('{"phase":"recovering"}', file_get_contents($root.'/edition-delivery-hold.json'));
            self::assertSame('source bytes', file_get_contents($root.'/synthetic-private-state'));
            self::assertSame('source image bytes', file_get_contents($root.'/template-assets/synthetic-image'));
            self::assertSame(0o755, fileperms($root.'/template-assets') & 0o777);
            self::assertSame(0o644, fileperms($root.'/template-assets/synthetic-image') & 0o777);
            self::assertSame(0o750, fileperms($root) & 0o777);
            self::assertSame(0o640, fileperms($root.'/synthetic-private-state') & 0o777);
            self::assertFileDoesNotExist($root.'/synthetic-target-addition');
            self::assertSame('retained target bytes', file_get_contents($checkpoint.'/post-checkpoint-private/synthetic-target-addition'));
            self::assertSame($attempts, $connection->fetchOne('SELECT COUNT(*) FROM delivery_attempt'));
            // Replaying recovery after an interruption preserves the same operation ownership.
            $recovery->restore($checkpoint, $connection, true);
            self::assertSame('{"phase":"recovering"}', file_get_contents($root.'/edition-delivery-hold.json'));
            self::assertSame(0o600, fileperms($checkpoint.'/recovery.key') & 0o777);
        } finally {
            foreach ([$root, $checkpoint] as $directory) {
                if (is_dir($directory)) {
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($files as $file) {
                        self::assertInstanceOf(\SplFileInfo::class, $file);
                        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                    }
                    rmdir($directory);
                }
            }
        }
    }
}
