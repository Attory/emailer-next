<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\BackupValidator;

final readonly class RecentBackupValidator implements BackupValidator
{
    private const MAX_AGE_SECONDS = 86400;

    public function __construct(private string $publicDirectory)
    {
    }

    public function isValid(string $backupPath, int $now): bool
    {
        $metadata = @lstat($backupPath);

        if (
            false === $metadata
            || 'file' !== @filetype($backupPath)
            || 0 !== ($metadata['mode'] & 0o022)
            || $metadata['mtime'] > $now
            || $metadata['mtime'] < $now - self::MAX_AGE_SECONDS
        ) {
            return false;
        }

        $resolvedBackup = realpath($backupPath);
        $resolvedPublic = realpath($this->publicDirectory);

        if (false === $resolvedBackup || false === $resolvedPublic) {
            return false;
        }

        return !str_starts_with(
            $resolvedBackup.DIRECTORY_SEPARATOR,
            rtrim($resolvedPublic, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
        );
    }
}
