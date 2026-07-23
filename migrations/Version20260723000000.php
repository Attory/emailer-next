<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 platform baseline; verifies MariaDB migration execution without creating business schema.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
