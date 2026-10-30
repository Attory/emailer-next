<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\Native105Repair;
use App\Module\Release\Application\NativeIdentityBinding;
use PHPUnit\Framework\TestCase;

final class Native105RepairTest extends TestCase
{
    public function testOnlyTheAuthenticatedOriginal105PackageCanEnterTheOneTimeRoute(): void
    {
        $manifest = ['version' => '1.0.5', 'source_commit' => '3927f17d73190b9e99861a8e3b34b572226665a1',
            'schema_version' => '20261013000000', '_verified_archive_sha256' => '24e2c8652f61b3b18fe1a1d07e2b3eca5657dcfacebe5dd5635c5bac45aa4ef8'];
        Native105Repair::assertSource($manifest);
        foreach (array_keys($manifest) as $field) {
            try {
                Native105Repair::assertSource(array_replace($manifest, [$field => 'different']));
                self::fail('A different repair source was accepted.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheLegacyLockExceptionRequiresExplicitOneTime105Context(): void
    {
        $uuid = '01990101-0000-7000-8000-000000000099';
        NativeIdentityBinding::source('1.0.5', '20261013000000', $uuid, ['installation_uuid' => $uuid], null, false, false, true);
        NativeIdentityBinding::source('1.0.5', '20261013000000', $uuid, ['installation_uuid' => $uuid, 'schema_version' => 1], null, false, false, true);
        $this->expectException(\RuntimeException::class);
        NativeIdentityBinding::source('1.0.5', '20261013000000', $uuid, ['installation_uuid' => $uuid], null, false);
    }
}
