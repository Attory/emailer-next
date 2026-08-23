<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record bounded Phase 8 dead-letter retention evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 8 requires MariaDB/MySQL.');
        $this->addSql('ALTER TABLE housekeeping_run ADD deleted_dead_letter_rows INT UNSIGNED NOT NULL AFTER deleted_queue_rows');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Phase 8 operational evidence is forward-only. Restore a verified pre-migration backup instead.');
    }
}
