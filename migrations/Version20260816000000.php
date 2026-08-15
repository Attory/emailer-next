<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make persisted Phase 3 mail evidence exactly match plaintext unauthenticated local Mailpit.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Phase 3 requires MariaDB/MySQL.');

        $this->addSql('ALTER TABLE sender_identity DROP CONSTRAINT chk_sender_tls');
        $this->addSql("UPDATE sender_identity SET encryption_mode = 'none', smtp_username = NULL, credential_secret_id = NULL, tls_peer_verification = 0");
        $this->addSql("DELETE FROM encrypted_secret WHERE record_type = 'smtp.credential'");
        $this->addSql(<<<'SQL'
ALTER TABLE sender_identity ADD CONSTRAINT chk_sender_phase3_mailpit CHECK (
    smtp_host IN ('mailpit', '127.0.0.1', '::1')
    AND smtp_port = 1025
    AND encryption_mode = 'none'
    AND smtp_username IS NULL
    AND credential_secret_id IS NULL
    AND tls_peer_verification = 0
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity DROP CONSTRAINT chk_sender_phase3_mailpit');
        $this->addSql('UPDATE sender_identity SET tls_peer_verification = 1');
        $this->addSql('ALTER TABLE sender_identity ADD CONSTRAINT chk_sender_tls CHECK (tls_peer_verification = 1)');
    }
}
