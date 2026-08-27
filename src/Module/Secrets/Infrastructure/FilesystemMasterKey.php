<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\MasterKey;

final readonly class FilesystemMasterKey implements MasterKey
{
    private const DIRECTORY_TYPE = 0o040000;
    private const REGULAR_TYPE = 0o100000;
    private const TYPE_MASK = 0o170000;

    public function __construct(
        private string $keyFile,
        private string $publicDirectory,
        private string $projectDirectory,
        private string $environment,
    ) {
    }

    public function create(): void
    {
        $keyFile = $this->validatedKeyPath();
        if ($this->pathExists($keyFile)) {
            $this->load();

            return;
        }

        $directory = \dirname($keyFile);
        $this->createMissingDirectories($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $temporaryFile = $directory.'/.master-key-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        $temporaryMetadata = null;

        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master key cannot be created safely.');
            }

            try {
                if (!@chmod($temporaryFile, 0o600) || \strlen($encoded) !== fwrite($handle, $encoded) || !fflush($handle)) {
                    throw new \RuntimeException('Master key cannot be written safely.');
                }
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Master key has unsafe filesystem metadata.');
                }
                $temporaryMetadata = $metadata;
            } finally {
                fclose($handle);
            }

            $this->assertSnapshotUnchanged($ancestors);
            $published = @link($temporaryFile, $keyFile);
            if (!$published && !$this->pathExists($keyFile)) {
                throw new \RuntimeException('Master key cannot be published safely.');
            }
            try {
                $this->assertSnapshotUnchanged($ancestors);
            } catch (\Throwable $exception) {
                $publishedMetadata = @lstat($keyFile);
                if ($published && \is_array($publishedMetadata) && $this->sameObject($temporaryMetadata, $publishedMetadata)) {
                    @unlink($keyFile);
                }
                throw $exception;
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }

        $this->load();
    }

    public function load(): string
    {
        $keyFile = $this->validatedKeyPath();
        $ancestors = $this->validateAncestorChain(\dirname($keyFile), true);
        $before = $this->lstat($keyFile);

        if (!$this->isSafeKeyMetadata($before)) {
            throw new \RuntimeException('Master key is missing or has unsafe filesystem metadata.');
        }

        $handle = @fopen($keyFile, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Master key cannot be opened safely.');
        }

        try {
            $openedBefore = fstat($handle);
            if (false === $openedBefore
                || !$this->isSafeKeyMetadata($openedBefore)
                || !$this->sameObject($before, $openedBefore)
                || $openedBefore['size'] > 128
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }

            $contents = stream_get_contents($handle, 129);
            $openedAfter = fstat($handle);
            $after = $this->lstat($keyFile);
            if (false === $contents
                || false === $openedAfter
                || \strlen($contents) > 128
                || !$this->sameObject($openedBefore, $openedAfter)
                || !$this->sameObject($before, $after)
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }
            $this->assertSnapshotUnchanged($ancestors);
        } finally {
            fclose($handle);
        }

        try {
            $key = sodium_base642bin(rtrim($contents, "\r\n"), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException $exception) {
            throw new \RuntimeException('Master key is malformed.', previous: $exception);
        } finally {
            sodium_memzero($contents);
        }

        if (32 !== \strlen($key)) {
            sodium_memzero($key);
            throw new \RuntimeException('Master key is malformed.');
        }

        return $key;
    }

    public function exists(): bool
    {
        return $this->pathExists($this->validatedKeyPath());
    }

    public function recover(string $key): void
    {
        if (32 !== \strlen($key)) {
            throw new \InvalidArgumentException('Recovered master key is malformed.');
        }
        $keyFile = $this->validatedKeyPath();
        if ($this->pathExists($keyFile)) {
            throw new \RuntimeException('Master key already exists; recovery will not replace it.');
        }
        $directory = \dirname($keyFile);
        $this->createMissingDirectories($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $temporaryFile = $directory.'/.master-key-recovery-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Recovered master key cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600) || \strlen($encoded) !== fwrite($handle, $encoded) || !fflush($handle)) {
                    throw new \RuntimeException('Recovered master key cannot be written safely.');
                }
            } finally {
                fclose($handle);
            }
            $this->assertSnapshotUnchanged($ancestors);
            if (!@link($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Recovered master key cannot be published safely.');
            }
            try {
                $this->assertSnapshotUnchanged($ancestors);
            } catch (\Throwable $exception) {
                @unlink($keyFile);
                throw $exception;
            }
            if (!@unlink($temporaryFile)) {
                @unlink($keyFile);
                throw new \RuntimeException('Recovered master key staging cannot be removed safely.');
            }
            $publishedMetadata = @lstat($keyFile);
            try {
                $loaded = $this->load();
                try {
                    if (!hash_equals($key, $loaded)) {
                        throw new \RuntimeException('Recovered master key verification failed.');
                    }
                } finally {
                    sodium_memzero($loaded);
                }
            } catch (\Throwable $exception) {
                $currentMetadata = @lstat($keyFile);
                if (\is_array($publishedMetadata) && \is_array($currentMetadata) && $this->sameObject($publishedMetadata, $currentMetadata)) {
                    @unlink($keyFile);
                }
                throw $exception;
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    public function replace(string $expectedCurrentKey, string $replacementKey): void
    {
        if (32 !== \strlen($expectedCurrentKey) || 32 !== \strlen($replacementKey)) {
            throw new \InvalidArgumentException('Master-key replacement material is malformed.');
        }

        $current = $this->load();
        try {
            if (!hash_equals($expectedCurrentKey, $current)) {
                throw new \RuntimeException('Master key changed before replacement.');
            }
        } finally {
            sodium_memzero($current);
        }

        $keyFile = $this->validatedKeyPath();
        $directory = \dirname($keyFile);
        $ancestors = $this->validateAncestorChain($directory, true);
        $before = $this->lstat($keyFile);
        if (!$this->isSafeKeyMetadata($before)) {
            throw new \RuntimeException('Master key has unsafe filesystem metadata.');
        }

        $temporaryFile = $directory.'/.master-key-replacement-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($replacementKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        $published = false;
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Replacement master key cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600)
                    || \strlen($encoded) !== fwrite($handle, $encoded)
                    || !fflush($handle)
                    || (\function_exists('fsync') && !fsync($handle))
                ) {
                    throw new \RuntimeException('Replacement master key cannot be written safely.');
                }
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Replacement master key has unsafe filesystem metadata.');
                }
            } finally {
                fclose($handle);
            }

            $this->assertSnapshotUnchanged($ancestors);
            $currentMetadata = $this->lstat($keyFile);
            if (!$this->sameObject($before, $currentMetadata)
                || $before['mode'] !== $currentMetadata['mode']
                || $before['uid'] !== $currentMetadata['uid']
            ) {
                throw new \RuntimeException('Master key changed during replacement.');
            }
            if (!@rename($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Replacement master key cannot be published atomically.');
            }
            $published = true;
            $this->assertSnapshotUnchanged($ancestors);
            $loaded = $this->load();
            try {
                if (!hash_equals($replacementKey, $loaded)) {
                    throw new \RuntimeException('Replacement master-key verification failed.');
                }
            } finally {
                sodium_memzero($loaded);
            }
        } catch (\Throwable $exception) {
            if ($published) {
                try {
                    $this->restoreFailedReplacement($keyFile, $expectedCurrentKey, $ancestors);
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException('Master-key replacement failed and automatic filesystem rollback failed.', previous: $rollbackFailure);
                }
            }
            throw $exception;
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    /** @param array<string, array<mixed>> $ancestors */
    private function restoreFailedReplacement(string $keyFile, string $key, array $ancestors): void
    {
        $directory = \dirname($keyFile);
        $temporaryFile = $directory.'/.master-key-rollback-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master-key rollback cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600)
                    || \strlen($encoded) !== fwrite($handle, $encoded)
                    || !fflush($handle)
                    || (\function_exists('fsync') && !fsync($handle))
                ) {
                    throw new \RuntimeException('Master-key rollback cannot be written safely.');
                }
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Master-key rollback has unsafe filesystem metadata.');
                }
            } finally {
                fclose($handle);
            }
            $this->assertSnapshotUnchanged($ancestors);
            if (!@rename($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Master-key rollback cannot be published atomically.');
            }
            $loaded = $this->load();
            try {
                if (!hash_equals($key, $loaded)) {
                    throw new \RuntimeException('Master-key rollback verification failed.');
                }
            } finally {
                sodium_memzero($loaded);
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    private function validatedKeyPath(): string
    {
        if ('' === $this->keyFile || !str_starts_with($this->keyFile, '/') || str_contains($this->keyFile, "\0")) {
            throw new \RuntimeException('Master-key configuration must be an explicit absolute path.');
        }

        $key = $this->normalizeAbsolutePath($this->keyFile);
        if ('/' === $key) {
            throw new \RuntimeException('Master-key configuration is invalid.');
        }

        if ($this->isWithin($key, $this->normalizeAbsolutePath($this->publicDirectory))) {
            throw new \RuntimeException('Master key must remain outside the public directory.');
        }

        if (!\in_array($this->environment, ['dev', 'test'], true)
            && $this->isWithin($key, $this->normalizeAbsolutePath($this->projectDirectory))
        ) {
            throw new \RuntimeException('Production master key must remain outside the application release tree.');
        }

        return $key;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('Master-key configuration must be an explicit absolute path.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                throw new \RuntimeException('Master-key configuration must not contain parent traversal.');
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function isWithin(string $candidate, string $directory): bool
    {
        return $candidate === $directory || str_starts_with($candidate.'/', rtrim($directory, '/').'/');
    }

    private function createMissingDirectories(string $directory): void
    {
        $current = '';
        foreach (explode('/', ltrim($directory, '/')) as $segment) {
            $current .= '/'.$segment;
            if ($this->pathExists($current)) {
                $this->validateDirectory($current, $current === $directory);

                continue;
            }
            if (!@mkdir($current, 0o700) || !@chmod($current, 0o700)) {
                throw new \RuntimeException('Master-key directory cannot be created safely.');
            }
            $this->validateDirectory($current, true);
        }
    }

    /** @return array<string, array<mixed>> */
    private function validateAncestorChain(string $directory, bool $strictParent): array
    {
        $snapshot = [];
        $current = '/';
        $root = $this->lstat($current);
        $this->assertSafeAncestor($root, false);
        $snapshot[$current] = $root;

        foreach (explode('/', ltrim($directory, '/')) as $segment) {
            $current = '/' === $current ? '/'.$segment : $current.'/'.$segment;
            $metadata = $this->lstat($current);
            $this->assertSafeAncestor($metadata, $strictParent && $current === $directory);
            $snapshot[$current] = $metadata;
        }

        return $snapshot;
    }

    private function validateDirectory(string $directory, bool $strict): void
    {
        $this->assertSafeAncestor($this->lstat($directory), $strict);
    }

    /** @param array<mixed> $metadata */
    private function assertSafeAncestor(array $metadata, bool $strict): void
    {
        $mode = $metadata['mode'] ?? null;
        $owner = $metadata['uid'] ?? null;
        if (!\is_int($mode) || !\is_int($owner)) {
            throw new \RuntimeException('Master-key ancestor metadata is unsafe.');
        }
        if (self::DIRECTORY_TYPE !== ($mode & self::TYPE_MASK)) {
            throw new \RuntimeException('Master-key ancestor is not a real directory.');
        }

        $effectiveUser = $this->effectiveUserId();
        if (!\in_array($owner, [0, $effectiveUser], true)) {
            throw new \RuntimeException('Master-key ancestor ownership is unsafe.');
        }

        $permissions = $mode & 0o7777;
        if ($strict) {
            if ($effectiveUser !== $owner || 0o700 !== ($permissions & 0o777)) {
                throw new \RuntimeException('Master-key directory permissions or ownership are unsafe.');
            }

            return;
        }

        if (0 !== ($permissions & 0o022)
            && !(0 === $owner && 0 !== ($permissions & 0o1000))
        ) {
            throw new \RuntimeException('Master-key ancestor permissions are unsafe.');
        }
    }

    /** @param array<string, array<mixed>> $snapshot */
    private function assertSnapshotUnchanged(array $snapshot): void
    {
        foreach ($snapshot as $path => $before) {
            $after = $this->lstat($path);
            if (!$this->sameObject($before, $after) || $before['mode'] !== $after['mode'] || $before['uid'] !== $after['uid']) {
                throw new \RuntimeException('Master-key ancestor changed during validation.');
            }
        }
    }

    /** @param array<mixed> $metadata */
    private function isSafeKeyMetadata(array $metadata): bool
    {
        $mode = $metadata['mode'] ?? null;

        return \is_int($mode)
            && self::REGULAR_TYPE === ($mode & self::TYPE_MASK)
            && $this->effectiveUserId() === ($metadata['uid'] ?? null)
            && 1 === ($metadata['nlink'] ?? null)
            && 0o600 === ($mode & 0o777);
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private function sameObject(array $left, array $right): bool
    {
        return $left['ino'] === $right['ino'] && $left['dev'] === $right['dev'];
    }

    /** @return array<mixed> */
    private function lstat(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = @lstat($path);
        if (false === $metadata) {
            throw new \RuntimeException('Master-key filesystem path is missing or unsafe.');
        }

        return $metadata;
    }

    private function pathExists(string $path): bool
    {
        clearstatcache(true, $path);

        return false !== @lstat($path);
    }

    private function effectiveUserId(): int
    {
        if (!\function_exists('posix_geteuid')) {
            throw new \RuntimeException('Master-key ownership cannot be validated on this runtime.');
        }

        return posix_geteuid();
    }
}
