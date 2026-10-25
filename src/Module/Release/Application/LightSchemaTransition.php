<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

/** Dedicated forward DDL; never runs Ripe historical migrations over a Light schema. */
final readonly class LightSchemaTransition
{
    public function __construct(private Connection $connection, private string $projectDirectory)
    {
    }

    public function apply(): void
    {
        $native = EditionData::read($this->projectDirectory.'/config/upgrade/ripe-native-schema-1.3.3.json');
        $tables = EditionData::strings($native['tables']);
        $triggers = EditionData::strings($native['triggers']);
        $contract = EditionData::read($this->projectDirectory.'/config/upgrade/light-contract-1.0.json');
        // Every new business structure is initialized empty, with its actual native constraints.
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_merge(EditionData::list($contract['empty_target_tables']), ['ripe_edition_transition']) as $table) {
                $this->connection->executeStatement($tables[$table]);
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach ([
            "ALTER TABLE login_throttle DROP CONSTRAINT chk_login_throttle_type, ADD CONSTRAINT chk_login_throttle_type CHECK (scope_type IN ('account','source','reauth_account','reauth_context'))",
            "ALTER TABLE product_identity DROP CONSTRAINT chk_product_id, ADD CONSTRAINT chk_product_id CHECK (BINARY product_id IN ('emailer-light','emailer-ripe'))",
            "ALTER TABLE backup_record ADD source_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-light', ADD CONSTRAINT chk_backup_source_edition CHECK (BINARY source_edition IN ('emailer-light','emailer-ripe'))",
            "ALTER TABLE backup_record ALTER source_edition SET DEFAULT 'emailer-ripe'",
            "ALTER TABLE campaign ADD audience_model VARCHAR(16) NOT NULL DEFAULT 'campaign_owned', ADD CONSTRAINT chk_campaign_audience_model CHECK (BINARY audience_model IN ('list','campaign_owned'))",
            "ALTER TABLE campaign ALTER audience_model SET DEFAULT 'list'",
            "ALTER TABLE app_user DROP CONSTRAINT chk_administrator_singleton, DROP CONSTRAINT chk_administrator_active, DROP INDEX uniq_administrator_singleton, DROP COLUMN singleton_key, ADD role VARCHAR(32) NOT NULL DEFAULT 'ROLE_OWNER', ADD mfa_changed_at DATETIME(6) DEFAULT NULL, ADD disabled_at DATETIME(6) DEFAULT NULL, ADD INDEX idx_user_role_active (role, active), ADD CONSTRAINT chk_user_role CHECK (BINARY role IN ('ROLE_OWNER','ROLE_ADMINISTRATOR','ROLE_OPERATOR','ROLE_AUDITOR'))",
            'ALTER TABLE app_user ALTER role DROP DEFAULT',
            "ALTER TABLE organisation_settings ADD security_level VARCHAR(6) NOT NULL DEFAULT 'high', ADD CONSTRAINT chk_organisation_security_level CHECK (BINARY security_level IN ('high','normal'))",
            'ALTER TABLE installation_state ADD owner_ready_at DATETIME(6) DEFAULT NULL',
            'UPDATE installation_state SET owner_ready_at = administrator_ready_at',
            'ALTER TABLE setup_installation_event ADD owner_id CHAR(36) DEFAULT NULL',
            'UPDATE setup_installation_event SET owner_id = administrator_id',
            'ALTER TABLE setup_installation_event MODIFY owner_id CHAR(36) NOT NULL, ADD CONSTRAINT fk_setup_event_owner FOREIGN KEY (owner_id) REFERENCES app_user(id) ON DELETE RESTRICT',
            'ALTER TABLE housekeeping_run ADD deleted_import_files INT UNSIGNED NOT NULL DEFAULT 0',
            "ALTER TABLE encrypted_secret ADD cipher_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-light', ADD CONSTRAINT chk_secret_edition CHECK (BINARY cipher_edition IN ('emailer-next','emailer-light'))",
            "ALTER TABLE encrypted_secret ALTER cipher_edition SET DEFAULT 'emailer-next'",
            'ALTER TABLE campaign_audience_recipient ADD contact_id CHAR(36) DEFAULT NULL, ADD CONSTRAINT fk_campaign_audience_recipient_contact FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT',
            'ALTER TABLE campaign_recipient ADD contact_id CHAR(36) DEFAULT NULL, ADD UNIQUE INDEX uniq_campaign_contact_delivery (campaign_id,contact_id), ADD UNIQUE INDEX uniq_delivery_scope (id,campaign_id,recipient_id), ADD CONSTRAINT fk_delivery_contact FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT',
            "ALTER TABLE recipient_token ADD contact_id CHAR(36) DEFAULT NULL, ADD codec_edition VARCHAR(32) NOT NULL DEFAULT 'emailer-light', ADD CONSTRAINT chk_token_edition CHECK (BINARY codec_edition IN ('emailer-next','emailer-light')), ADD CONSTRAINT fk_recipient_token_contact FOREIGN KEY (contact_id) REFERENCES contact(id) ON DELETE RESTRICT, ADD CONSTRAINT fk_token_scope FOREIGN KEY (campaign_recipient_id,campaign_id,recipient_id) REFERENCES campaign_recipient(id,campaign_id,recipient_id) ON DELETE RESTRICT",
            "ALTER TABLE recipient_token ALTER codec_edition SET DEFAULT 'emailer-next'",
        ] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement('ALTER TABLE campaign MODIFY template_version_id CHAR(36) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE suppression ADD UNIQUE INDEX uniq_suppression_identity_match (id,email_match_key)');
        $this->connection->executeStatement($tables['suppression_match']);
        $this->connection->executeStatement('INSERT INTO suppression_match (email_match_key,suppression_id) SELECT email_match_key,id FROM suppression');
        foreach (['campaign_audience_recipient', 'campaign_recipient', 'recipient_token'] as $table) {
            $this->connection->executeStatement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_'.$table.'_identity CHECK (recipient_id IS NOT NULL AND (contact_id IS NULL OR BINARY contact_id = BINARY recipient_id))');
        }
        foreach ($triggers as $name => $ddl) {
            if (!\in_array($name, ['audit_event_no_update', 'audit_event_no_delete'], true)) {
                $this->connection->executeStatement($ddl);
            }
        }
    }
}
