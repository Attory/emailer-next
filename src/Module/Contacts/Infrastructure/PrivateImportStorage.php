<?php

declare(strict_types=1);

namespace App\Module\Contacts\Infrastructure;

use App\Module\Contacts\Application\Contract\ImportStorage;

final readonly class PrivateImportStorage implements ImportStorage
{
    private const MAX_BYTES = 10_485_760;

    public function __construct(private string $directory)
    {
    }

    public function store(string $sourcePath): array
    {
        $stat = @lstat($sourcePath);
        if (false === $stat || !is_file($sourcePath) || is_link($sourcePath) || 1 !== $stat['nlink'] || $stat['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Import upload is invalid.');
        }
        $this->ensureDirectory();
        $token = bin2hex(random_bytes(24));
        $target = $this->path($token, '.csv');
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($target, 'x+b');
        if (false === $input || false === $output) {
            if (\is_resource($input)) {
                fclose($input);
            }
            if (\is_resource($output)) {
                fclose($output);
                @unlink($target);
            }
            throw new \RuntimeException('Import upload could not be stored.');
        }
        $openedStat = fstat($input);
        if (false === $openedStat || $openedStat['dev'] !== $stat['dev'] || $openedStat['ino'] !== $stat['ino'] || 1 !== $openedStat['nlink']) {
            fclose($input);
            fclose($output);
            @unlink($target);
            throw new \RuntimeException('Import upload changed during validation.');
        }
        @chmod($target, 0600);
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if (false === $chunk) {
                    throw new \RuntimeException('Import upload could not be read.');
                }
                $size += \strlen($chunk);
                if ($size > self::MAX_BYTES || \strlen($chunk) !== fwrite($output, $chunk)) {
                    throw new \RuntimeException('Import upload exceeds its safe bound.');
                }
                hash_update($hash, $chunk);
            }
            fflush($output);
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($target);
            throw $exception;
        }
        fclose($input);
        fclose($output);

        return ['token' => $token, 'sha256' => hash_final($hash), 'size' => $size];
    }

    public function open(string $token, string $expectedSha256)
    {
        $path = $this->path($token, '.csv');
        $stat = @lstat($path);
        if (false === $stat || !is_file($path) || is_link($path) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('Stored import failed integrity validation.');
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Stored import is unavailable.');
        }
        $openedStat = fstat($handle);
        if (false === $openedStat || $openedStat['dev'] !== $stat['dev'] || $openedStat['ino'] !== $stat['ino'] || 1 !== $openedStat['nlink']) {
            fclose($handle);
            throw new \RuntimeException('Stored import failed integrity validation.');
        }
        $hash = hash_init('sha256');
        $hashedBytes = hash_update_stream($hash, $handle);
        if ($hashedBytes !== $openedStat['size']) {
            fclose($handle);
            throw new \RuntimeException('Stored import failed integrity validation.');
        }
        $actualHash = hash_final($hash);
        if (!hash_equals($expectedSha256, $actualHash) || 0 !== fseek($handle, 0)) {
            fclose($handle);
            throw new \RuntimeException('Stored import failed integrity validation.');
        }

        return $handle;
    }

    /** @param iterable<string> $lines */
    public function writeReport(string $token, iterable $lines): void
    {
        $this->ensureToken($token);
        $this->ensureDirectory();
        $path = $this->path($token, '.report');
        $handle = @fopen($path, 'x+b');
        if (false === $handle) {
            throw new \RuntimeException('Private rejection report could not be created.');
        }
        @chmod($path, 0600);
        try {
            foreach ($lines as $line) {
                if (1 !== preg_match('/^row [0-9]+: [a-z ]{1,80}$/', $line) || false === fwrite($handle, $line."\n")) {
                    throw new \RuntimeException('Private rejection report is invalid.');
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function openReport(string $token)
    {
        $path = $this->path($token, '.report');
        $stat = @lstat($path);
        if (false === $stat || !is_file($path) || is_link($path) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('Private rejection report is unavailable.');
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Private rejection report is unavailable.');
        }
        $openedStat = fstat($handle);
        if (false === $openedStat || $openedStat['dev'] !== $stat['dev'] || $openedStat['ino'] !== $stat['ino'] || 1 !== $openedStat['nlink']) {
            fclose($handle);
            throw new \RuntimeException('Private rejection report failed integrity validation.');
        }

        return $handle;
    }

    public function delete(string $token): void
    {
        @unlink($this->path($token, '.csv'));
    }

    public function deleteReport(string $token): void
    {
        @unlink($this->path($token, '.report'));
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Private import directory is unavailable.');
        }
        if (is_link($this->directory)) {
            throw new \RuntimeException('Private import directory is unsafe.');
        }
        @chmod($this->directory, 0700);
    }

    private function path(string $token, string $suffix): string
    {
        $this->ensureToken($token);

        return rtrim($this->directory, '/').'/'.$token.$suffix;
    }

    private function ensureToken(string $token): void
    {
        if (1 !== preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new \InvalidArgumentException('Private file token is invalid.');
        }
    }
}
