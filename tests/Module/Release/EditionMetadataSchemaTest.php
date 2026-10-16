<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\EditionSchemaProof;
use PHPUnit\Framework\TestCase;

final class EditionMetadataSchemaTest extends TestCase
{
    public function testOnlyKnownDoctrineLedgerDefaultCollationsAreCanonicalized(): void
    {
        $original = 'CREATE TABLE `doctrine_migration_versions` (`version` varchar(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        foreach (['general_ci', 'unicode_ci', 'bin', 'uca1400_ai_ci'] as $collation) {
            self::assertSame($original, EditionSchemaProof::baselineTable('doctrine_migration_versions', str_replace('utf8mb4_bin', 'utf8mb4_'.$collation, $original)));
        }
        self::assertNotSame($original, EditionSchemaProof::baselineTable('doctrine_migration_versions', str_replace('varchar(191)', 'varchar(190)', $original)));
        self::assertNotSame($original, EditionSchemaProof::baselineTable('doctrine_migration_versions', str_replace('utf8mb4_bin', 'utf8mb4_unicode_520_ci', $original)));
        $application = str_replace('doctrine_migration_versions', 'app_user', $original);
        self::assertNotSame($application, EditionSchemaProof::baselineTable('app_user', str_replace('utf8mb4_bin', 'utf8mb4_unicode_ci', $application)));
        self::assertNotSame($application, EditionSchemaProof::baselineTable('app_user', str_replace('utf8mb4_bin', 'utf8mb4_uca1400_ai_ci', $application)));
    }
}
