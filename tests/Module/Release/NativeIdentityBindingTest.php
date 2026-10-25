<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\NativeIdentityBinding;
use PHPUnit\Framework\TestCase;

final class NativeIdentityBindingTest extends TestCase
{
    public function testStrictCurrentSourceAndTargetTuplesRejectEachDriftedField(): void
    {
        $uuid = '01990101-0000-7000-8000-000000000099';
        $lock = ['product_id' => 'emailer-ripe', 'product_version' => '1.3.2', 'schema_version' => '20261015000000', 'installation_uuid' => $uuid];
        $row = ['singleton_key' => '1', ...$lock];
        NativeIdentityBinding::source('1.3.2', '20261015000000', $uuid, $lock, $row, false);
        NativeIdentityBinding::source('1.3.2', '20261015000000', $uuid, $lock, null, false); // Genuine native Ripe has no converted identity table.
        foreach (['product_id', 'product_version', 'schema_version', 'installation_uuid'] as $field) {
            $changed = $lock;
            $changed[$field] = 'drift';
            try {
                NativeIdentityBinding::source('1.3.2', '20261015000000', $uuid, $changed, $row, false);
                self::fail('A drifted source lock was accepted.');
            } catch (\RuntimeException $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
            $changed = $row;
            $changed[$field] = 'drift';
            try {
                NativeIdentityBinding::source('1.3.2', '20261015000000', $uuid, $lock, $changed, false);
                self::fail('A converted identity drift was silently repaired.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('No metadata was repaired', $failure->getMessage());
            }
        }
        NativeIdentityBinding::row([...$row, 'product_version' => '1.3.3', 'schema_version' => '20261025000000'], '1.3.3', '20261025000000', $uuid);
    }

    public function testOnlyHistorical106MayHaveMissingLegacyFieldsOrProven105Metadata(): void
    {
        $uuid = '01990101-0000-7000-8000-000000000099';
        NativeIdentityBinding::source('1.0.6', '20261015000000', $uuid, ['installation_uuid' => $uuid], null, false);
        NativeIdentityBinding::source('1.0.6', '20261015000000', $uuid, ['installation_uuid' => $uuid, 'product_version' => '1.0.5', 'schema_version' => '20261013000000'], null, true);
        $this->expectException(\RuntimeException::class);
        NativeIdentityBinding::source('1.3.2', '20261015000000', $uuid, ['installation_uuid' => $uuid], null, true);
    }

    public function testLegacy105VersionRequiresTheActualPriorSuccessfulTransition(): void
    {
        $this->expectException(\RuntimeException::class);
        NativeIdentityBinding::source('1.0.6', '20261015000000', 'synthetic', ['installation_uuid' => 'synthetic', 'product_version' => '1.0.5', 'schema_version' => '20261013000000'], null, false);
    }
}
