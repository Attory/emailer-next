<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Return a root-created verified backup to its existing dedicated service. */
final class SafetyBackupOwnership
{
    public static function transfer(string $path, string $environment): void
    {
        if ('prod' !== $environment) {
            return;
        }
        $before = @lstat($path);
        $parent = @lstat(\dirname($path));
        if (!\is_array($before)
            || !\is_array($parent)
            || 0o100000 !== ($before['mode'] & 0o170000)
            || 0 !== $before['uid']
            || 0o600 !== ($before['mode'] & 0o777)
            || 1 !== $before['nlink']
            || 0o040000 !== ($parent['mode'] & 0o170000)
            || 0 === $parent['uid']
            || 0o700 !== ($parent['mode'] & 0o777)
            || !\function_exists('lchown')
            || !\function_exists('lchgrp')
            || !@lchown($path, $parent['uid'])
            || !@lchgrp($path, $parent['gid'])
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership cannot be returned to the service identity.');
        }
        $after = @lstat($path);
        if ($before['ino'] !== $after['ino']
            || $before['dev'] !== $after['dev']
            || $parent['uid'] !== $after['uid']
            || $parent['gid'] !== $after['gid']
            || 0o600 !== ($after['mode'] & 0o777)
            || 1 !== $after['nlink']
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership changed unsafely.');
        }
    }
}
