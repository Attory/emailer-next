<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\LightSourcePackage;
use PHPUnit\Framework\TestCase;

final class LightSourceProfileTest extends TestCase
{
    public function testNewLightUsesTheExactExistingDraftSchemaAndBothMigrationLedgers(): void
    {
        self::assertContains('1.2.7', LightSourcePackage::SUPPORTED_VERSIONS);
        self::assertSame('20261025000000', LightSourcePackage::schemaFor('1.2.7'));
        self::assertSame('1.2.6', LightSourcePackage::proofFor('1.2.7'));
        self::assertSame(LightSourcePackage::migrationsFor('1.2.6'), LightSourcePackage::migrationsFor('1.2.7'));
        self::assertCount(2, LightSourcePackage::migrationsFor('1.2.7'));
        self::assertSame('20261014000000', LightSourcePackage::schemaFor('1.0.0'));
        self::assertContains('1.2.8', LightSourcePackage::SUPPORTED_VERSIONS);
        self::assertSame('20261025000000', LightSourcePackage::schemaFor('1.2.8'));
        self::assertSame(LightSourcePackage::migrationsFor('1.2.7'), LightSourcePackage::migrationsFor('1.2.8'));
        self::assertContains('1.2.9', LightSourcePackage::SUPPORTED_VERSIONS);
        self::assertSame(LightSourcePackage::migrationsFor('1.2.8'), LightSourcePackage::migrationsFor('1.2.9'));
        self::assertContains('1.2.10', LightSourcePackage::SUPPORTED_VERSIONS);
        self::assertContains('1.2.11', LightSourcePackage::SUPPORTED_VERSIONS);
        self::assertSame(LightSourcePackage::migrationsFor('1.2.10'), LightSourcePackage::migrationsFor('1.2.11'));
        self::assertSame(LightSourcePackage::migrationsFor('1.2.9'), LightSourcePackage::migrationsFor('1.2.10'));
        $this->expectException(\RuntimeException::class);
        LightSourcePackage::schemaFor('1.2.12');
    }
}
