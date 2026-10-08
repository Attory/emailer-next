<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter campaign pacing with a durable campaign-specific rate window.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof MariaDBPlatform, 'A supported MariaDB server is required.');
        // MariaDB DDL can survive an interrupted update even when its data backup is restored.
        // Repeating this exact additive migration must therefore be safe.
        $this->addSql('ALTER TABLE campaign ADD COLUMN IF NOT EXISTS messages_per_minute SMALLINT UNSIGNED DEFAULT NULL, ADD COLUMN IF NOT EXISTS rate_window_started_at DATETIME(6) DEFAULT NULL, ADD COLUMN IF NOT EXISTS rate_window_count INT UNSIGNED NOT NULL DEFAULT 0');
        if (false === $this->connection->fetchOne("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign' AND CONSTRAINT_NAME = 'chk_campaign_rate' AND CONSTRAINT_TYPE = 'CHECK'")) {
            $this->addSql('ALTER TABLE campaign ADD CONSTRAINT chk_campaign_rate CHECK (messages_per_minute IS NULL OR messages_per_minute BETWEEN 1 AND 600)');
        }
        $this->addSql('ALTER TABLE campaign_test_delivery ADD COLUMN IF NOT EXISTS draft_version INT UNSIGNED NOT NULL DEFAULT 1');
        $this->addSql("UPDATE campaign_test_delivery t INNER JOIN campaign c ON c.id = t.campaign_id SET t.draft_version = c.operation_version WHERE c.state IN ('draft', 'review')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore the verified pre-update backup to recover the previous schema.');
    }
}
