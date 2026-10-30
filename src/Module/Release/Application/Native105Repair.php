<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** One-time 1.3.4 repair input, not a continuing 1.0.5 support window. */
final class Native105Repair
{
    /** @param array<string, mixed> $manifest */
    public static function assertSource(array $manifest): void
    {
        if ('1.0.5' !== ($manifest['version'] ?? null)
            || '3927f17d73190b9e99861a8e3b34b572226665a1' !== ($manifest['source_commit'] ?? null)
            || '20261013000000' !== ($manifest['schema_version'] ?? null)
            || '24e2c8652f61b3b18fe1a1d07e2b3eca5657dcfacebe5dd5635c5bac45aa4ef8' !== ($manifest['_verified_archive_sha256'] ?? null)
        ) {
            throw new \RuntimeException('The one-time repair requires the exact original signed 1.0.5 package.');
        }
    }
}
