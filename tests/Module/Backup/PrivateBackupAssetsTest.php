<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup;

use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Application\Contract\BackupContributor;
use App\Module\Backup\Application\Contract\PrivateBackupAssets;
use App\Module\Backup\Application\RestoreDrillEvidence;
use App\Module\Release\Application\EditionRecoverySet;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Real encrypted archives/filesystem; DB is stubbed because these contributors own no tables. */
final class PrivateBackupAssetsTest extends TestCase
{
    private string $directory;
    private string $key;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-private-backup-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o700));
        $this->key = random_bytes(32);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    public function testEncryptedRestoreKeepsPrivateConfigurationAndPublicTemplateMetadataDistinct(): void
    {
        $private = $this->directory.'/appearance';
        $templates = $this->directory.'/template-assets';
        self::assertTrue(mkdir($private, 0o700));
        self::assertTrue(mkdir($private.'/administrators', 0o700));
        $this->put($private.'/state.json', '{"default":"studio"}', 0o600);
        $this->put($private.'/administrators/synthetic.json', '{"skin":"classic"}', 0o600);
        self::assertTrue(mkdir($templates, 0o755));
        $this->put($templates.'/synthetic.png', 'synthetic raster bytes', 0o644);
        $engine = $this->engine([$this->privateContributor(['appearance' => $private]), $this->publicContributor(['template-assets' => $templates])]);
        $backup = $engine->create();
        $this->put($private.'/state.json', '{"default":"classic"}', 0o600);
        $this->put($private.'/stale.json', 'stale', 0o600);
        unlink($templates.'/synthetic.png');
        $this->restoreAndValidate($engine, $backup['path']);
        self::assertSame('{"default":"studio"}', file_get_contents($private.'/state.json'));
        self::assertSame('{"skin":"classic"}', file_get_contents($private.'/administrators/synthetic.json'));
        self::assertFileDoesNotExist($private.'/stale.json');
        self::assertSame('synthetic raster bytes', file_get_contents($templates.'/synthetic.png'));
        $this->assertMode($private, 0o700);
        $this->assertMode($private.'/administrators', 0o700);
        $this->assertMode($private.'/state.json', 0o600);
        $this->assertMode($private.'/administrators/synthetic.json', 0o600);
        $this->assertMode($templates.'/synthetic.png', 0o644);
        // No recovery exception or broad permission relaxation is needed.
        EditionRecoverySet::safePrivate($private.'/state.json', false, $this->directory);
        EditionRecoverySet::safePrivate($private.'/administrators', true, $this->directory);
        $next = $engine->create();
        self::assertSame(['appearance', 'template-assets'], $this->logicalRoots($engine->verify($next['path'])));
    }

    public function testHistoricalBackupWithoutAppearanceResetsToEmptyPrivateRoot(): void
    {
        $legacy = $this->engine([]);
        $backup = $legacy->create();
        $private = $this->directory.'/appearance';
        self::assertTrue(mkdir($private, 0o700));
        $this->put($private.'/state.json', '{"default":"studio"}', 0o600);
        $current = $this->engine([$this->privateContributor(['appearance' => $private])]);
        self::assertSame([], $current->verify($backup['path'])['tables']);
        $this->restoreAndValidate($current, $backup['path']);
        self::assertSame([], array_values(array_diff(scandir($private), ['.', '..'])));
        $this->assertMode($private, 0o700);
        // An empty/missing state means Classic; backup does not manufacture a fresh-install marker.
        self::assertFileDoesNotExist($private.'/state.json');
        $current->create();
    }

    public function testMissingPrivateRootAndSimilarArchivePrefixCannotRestoreStaleOrForeignState(): void
    {
        $private = $this->directory.'/appearance';
        $other = $this->directory.'/appearance-other';
        self::assertTrue(mkdir($other, 0o700));
        $this->put($other.'/foreign.json', 'other logical root', 0o600);
        $old = $this->engine([$this->privateContributor(['appearance-other' => $other])]);
        $backup = $old->create();
        $current = $this->engine([$this->privateContributor(['appearance' => $private])]);
        $this->restoreAndValidate($current, $backup['path']);
        self::assertDirectoryExists($private);
        self::assertFileDoesNotExist($private.'/foreign.json');
        self::assertSame('other logical root', file_get_contents($other.'/foreign.json'));
        $this->assertMode($private, 0o700);
    }

    public function testPrivateMetadataLinksAndUnprotectedParentFailBeforeReplacingAnything(): void
    {
        $private = $this->directory.'/appearance';
        self::assertTrue(mkdir($private, 0o700));
        $this->put($private.'/state.json', 'retained original', 0o600);
        $engine = $this->engine([$this->privateContributor(['appearance' => $private])]);
        $backup = $engine->create();
        foreach (['loose-file', 'loose-directory', 'hardlink', 'symlink', 'linked-root', 'linked-ancestor', 'parent'] as $kind) {
            if ('loose-file' === $kind) {
                chmod($private.'/state.json', 0o644);
            } elseif ('loose-directory' === $kind) {
                chmod($private, 0o755);
            } elseif ('hardlink' === $kind) {
                link($private.'/state.json', $this->directory.'/hardlink');
            } elseif ('symlink' === $kind) {
                symlink($private.'/state.json', $private.'/linked.json');
            } elseif ('linked-root' === $kind) {
                rename($private, $this->directory.'/retained');
                symlink($this->directory.'/retained', $private);
            } elseif ('linked-ancestor' === $kind) {
                symlink($this->directory, $this->directory.'/linked-parent');
                $engine = $this->engine([$this->privateContributor(['appearance' => $this->directory.'/linked-parent/appearance'])]);
            } else {
                chmod($this->directory, 0o777);
            }
            $this->assertRejected(static fn () => $engine->create());
            $this->assertRejected(static fn () => $engine->restore($backup['path']));
            self::assertSame('retained original', file_get_contents($private.'/state.json'));
            if ('linked-root' === $kind) {
                unlink($private);
                rename($this->directory.'/retained', $private);
            }
            if (is_link($this->directory.'/linked-parent')) {
                unlink($this->directory.'/linked-parent');
            }
            @unlink($this->directory.'/hardlink');
            @unlink($private.'/linked.json');
            chmod($private, 0o700);
            chmod($private.'/state.json', 0o600);
            chmod($this->directory, 0o700);
            clearstatcache();
            $engine = $this->engine([$this->privateContributor(['appearance' => $private])]);
        }
    }

    public function testInvalidAuthenticatedAssetPathRollsBackToVerifiedPrivateSafetyBackup(): void
    {
        $private = $this->directory.'/appearance';
        self::assertTrue(mkdir($private, 0o700));
        $this->put($private.'/state.json', 'old snapshot', 0o600);
        $engine = $this->engine([$this->privateContributor(['appearance' => $private])]);
        $backup = $engine->create();
        $plain = $this->directory.'/synthetic-malformed.zip';
        (new \ReflectionMethod(BackupEngine::class, 'decrypt'))->invoke($engine, $backup['path'], $plain);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($plain));
        $manifestBytes = $archive->getFromName('manifest.json');
        self::assertIsString($manifestBytes);
        $manifest = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['entries']);
        $entry = 'assets/appearance/./escape.json';
        self::assertTrue($archive->addFromString($entry, 'unsafe relative path'));
        $manifest['entries'][$entry] = hash('sha256', 'unsafe relative path');
        self::assertTrue($archive->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($archive->close());
        $malformed = $this->directory.'/backups/synthetic-malformed.enxbackup';
        (new \ReflectionMethod(BackupEngine::class, 'encrypt'))->invoke($engine, $plain, $malformed);
        // Cryptographic verification succeeds; publication still rejects its path.
        $engine->verify($malformed);
        $this->put($private.'/state.json', 'current state preserved by safety backup', 0o600);
        try {
            $engine->restore($malformed);
            self::fail('Unsafe relative path was restored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Restore failed; the pre-restore backup was reapplied.', $exception->getMessage());
            self::assertSame('Restore asset path is unsafe.', $exception->getPrevious()?->getMessage());
        }
        self::assertSame('current state preserved by safety backup', file_get_contents($private.'/state.json'));
        self::assertFileDoesNotExist($private.'/escape.json');
        self::assertFileDoesNotExist($this->directory.'/escape.json');
        $this->assertMode($private, 0o700);
        $this->assertMode($private.'/state.json', 0o600);
        self::assertSame([], glob($this->directory.'/.restore-*'));
        self::assertSame([], glob($this->directory.'/.rollback-*'));
        $engine->create();
    }

    public function testInvalidOwnershipDeclarationsAndOverlappingRootsFailClosed(): void
    {
        $private = $this->directory.'/appearance';
        foreach ([
            [$this->privateContributor(['appearance' => $private], ['unowned'])],
            [$this->privateContributor(['appearance' => $private], ['appearance', 'appearance'])],
            [$this->privateContributor(['../appearance' => $private])],
            [$this->privateContributor(['appearance' => $private.'/../other'])],
            [$this->privateContributor(['appearance' => 'relative/path'])],
            [$this->privateContributor(['appearance' => $private]), $this->publicContributor(['appearance' => $this->directory.'/other'])],
            [$this->privateContributor(['appearance' => $private]), $this->publicContributor(['assets' => $private])],
            [$this->privateContributor(['appearance' => $private]), $this->publicContributor(['assets' => $private.'/nested'])],
        ] as $contributors) {
            $this->assertRejected(fn () => $this->engine($contributors)->create());
            self::assertFileDoesNotExist($private);
        }
    }

    private function restoreAndValidate(BackupEngine $engine, string $path): void
    {
        $engine->restore($path);
        $validation = new \ReflectionMethod(BackupEngine::class, 'validateRestoredSnapshot');
        $result = $validation->invoke($engine, $path);
        self::assertIsArray($result);
        self::assertSame(0, $result['tables']);
    }

    /** @param list<BackupContributor> $contributors */
    private function engine(array $contributors): BackupEngine
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('DoctrineMigrations\\Version20261015000000');
        $connection->method('fetchAssociative')->willReturn(['accepting_work' => 0, 'active_claims' => 0]);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $key = self::createStub(MasterKey::class);
        $key->method('load')->willReturn($this->key);
        $locks = new LockFactory(new FlockStore($this->directory));

        return new BackupEngine($connection, $key, $locks, new MaintenanceOperationLock($locks), $contributors, $this->directory.'/backups', '1.2.3', new RestoreDrillEvidence($key));
    }

    /** @param array<string, string> $roots
     * @param list<string>|null $private
     */
    private function privateContributor(array $roots, ?array $private = null): PrivateBackupAssets
    {
        return new readonly class($roots, $private ?? array_keys($roots)) implements PrivateBackupAssets {
            /** @param array<string, string> $roots
             * @param list<string> $private
             */
            public function __construct(private array $roots, private array $private)
            {
            }

            public function tables(): array
            {
                return [];
            }

            public function assetRoots(): array
            {
                return $this->roots;
            }

            public function privateAssetRoots(): array
            {
                return $this->private;
            }
        };
    }

    /** @param array<string, string> $roots */
    private function publicContributor(array $roots): BackupContributor
    {
        return new readonly class($roots) implements BackupContributor {
            /** @param array<string, string> $roots */
            public function __construct(private array $roots)
            {
            }

            public function tables(): array
            {
                return [];
            }

            public function assetRoots(): array
            {
                return $this->roots;
            }
        };
    }

    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function logicalRoots(array $manifest): array
    {
        $entries = $manifest['entries'];
        self::assertIsArray($entries);
        $roots = [];
        foreach (array_keys($entries) as $name) {
            self::assertIsString($name);
            if (str_starts_with($name, 'assets/')) {
                $roots[] = explode('/', $name)[1];
            }
        }

        return array_values(array_unique($roots));
    }

    private function assertMode(string $path, int $expected): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        self::assertNotFalse($stat);
        self::assertSame($expected, $stat['mode'] & 0o777);
    }

    private function put(string $path, string $bytes, int $mode): void
    {
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, $mode));
    }

    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe private backup assets were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
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
