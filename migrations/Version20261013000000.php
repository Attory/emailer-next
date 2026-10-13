<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default every installation to High security and constrain installation-wide levels.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organisation_settings ADD security_level VARCHAR(6) NOT NULL DEFAULT 'high', ADD CONSTRAINT chk_organisation_security_level CHECK (BINARY security_level IN (BINARY 'high', BINARY 'normal'))");
    }
}
