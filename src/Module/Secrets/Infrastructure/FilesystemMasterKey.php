<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\MasterKey;

final readonly class FilesystemMasterKey implements MasterKey
{
    public function __construct(
        private string $keyFile,
        private string $publicDirectory,
    ) {
    }

    public function create(): void
    {
        if ($this->exists()) {
            $this->load();

            return;
        }

        $this->assertOutsidePublic();
        $directory = \dirname($this->keyFile);

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Master-key directory cannot be created safely.');
        }

        if (!@chmod($directory, 0o700)) {
            throw new \RuntimeException('Master-key directory permissions cannot be secured.');
        }

        $directoryMetadata = @lstat($directory);
        if (false === $directoryMetadata || 'dir' !== @filetype($directory) || 0 !== ($directoryMetadata['mode'] & 0o077)) {
            throw new \RuntimeException('Master-key directory permissions are unsafe.');
        }

        $temporaryFile = $directory.'/.master-key-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";

        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master key cannot be created safely.');
            }

            try {
                if (!@chmod($temporaryFile, 0o600) || \strlen($encoded) !== fwrite($handle, $encoded) || !fflush($handle)) {
                    throw new \RuntimeException('Master key cannot be written safely.');
                }
            } finally {
                fclose($handle);
            }

            if (!@link($temporaryFile, $this->keyFile)) {
                if (!$this->exists()) {
                    throw new \RuntimeException('Master key cannot be published safely.');
                }
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }

        $this->load();
    }

    public function load(): string
    {
        $this->assertOutsidePublic();
        $directoryMetadata = @lstat(\dirname($this->keyFile));
        if (false === $directoryMetadata || 'dir' !== @filetype(\dirname($this->keyFile)) || 0 !== ($directoryMetadata['mode'] & 0o077)) {
            throw new \RuntimeException('Master-key directory permissions are unsafe.');
        }
        $before = @lstat($this->keyFile);

        if (false === $before || 'file' !== @filetype($this->keyFile) || 1 !== $before['nlink'] || 0 !== ($before['mode'] & 0o077)) {
            throw new \RuntimeException('Master key is missing or has unsafe filesystem metadata.');
        }

        $handle = @fopen($this->keyFile, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Master key cannot be opened safely.');
        }

        try {
            $openedBefore = fstat($handle);
            if (false === $openedBefore
                || $before['ino'] !== $openedBefore['ino']
                || $before['dev'] !== $openedBefore['dev']
                || 1 !== $openedBefore['nlink']
                || 0 !== ($openedBefore['mode'] & 0o077)
                || $openedBefore['size'] > 128
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }

            $contents = stream_get_contents($handle, 129);
            $openedAfter = fstat($handle);
            $after = @lstat($this->keyFile);
            if (false === $contents
                || false === $openedAfter
                || \strlen($contents) > 128
                || $openedBefore['ino'] !== $openedAfter['ino']
                || $openedBefore['dev'] !== $openedAfter['dev']
                || $before['ino'] !== $after['ino']
                || $before['dev'] !== $after['dev']
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }
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
        return file_exists($this->keyFile) || is_link($this->keyFile);
    }

    private function assertOutsidePublic(): void
    {
        $key = $this->normalizePath($this->keyFile);
        $public = rtrim($this->normalizePath($this->publicDirectory), '/').'/';

        if (str_starts_with($key.'/', $public)) {
            throw new \RuntimeException('Master key must remain outside the public directory.');
        }
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
