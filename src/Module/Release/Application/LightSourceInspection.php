<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

/** The signed target carries an immutable copy of the published source contract. */
final readonly class LightSourceInspection
{
    public function __construct(private Connection $connection, private string $projectDirectory, private string $privateDirectory, private string $masterKeyFile, private \App\Module\Secrets\Application\Contract\LightSecretInspection $secrets)
    {
    }

    /** @return array{identity: array<string, mixed>, migration: array<string, mixed>, administrator_id: mixed, tables: list<string>} */
    public function inspect(bool $requireIdle): array
    {
        $server = $this->connection->fetchOne('SELECT VERSION()');
        if (!\is_string($server) || !str_contains(strtolower($server), 'mariadb') || 1 !== preg_match('/^(10\.11|11\.8)\./', $server)) {
            throw new \RuntimeException('Light transition requires supported MariaDB 10.11 or 11.8.');
        }
        $identity = $this->connection->fetchAssociative("SELECT * FROM product_identity WHERE singleton_key = '1'");
        if (false === $identity || 'emailer-light' !== ($identity['product_id'] ?? null) || !\in_array($identity['product_version'] ?? null, LightSourcePackage::SUPPORTED_VERSIONS, true) || '20261014000000' !== ($identity['schema_version'] ?? null) || '1.0' !== ($identity['upgrade_contract_version'] ?? null) || 1 !== EditionData::integer($this->connection->fetchOne('SELECT COUNT(*) FROM product_identity'))) {
            throw new \RuntimeException('Unsupported or malformed Light product identity.');
        }
        $installation = $this->connection->fetchAssociative("SELECT installation_uuid, completed_at FROM installation_state WHERE singleton_key = '1'");
        $organisation = $this->connection->fetchOne("SELECT installation_uuid FROM organisation_settings WHERE singleton_key = '1'");
        if (false === $installation || null === $installation['completed_at'] || $identity['installation_uuid'] !== $installation['installation_uuid'] || $organisation !== $identity['installation_uuid']) {
            throw new \RuntimeException('Installation UUIDs disagree or source installation is incomplete.');
        }
        EditionRecoverySet::safePrivate($this->privateDirectory, true);
        EditionRecoverySet::safeConfiguration($this->privateDirectory.'/install.lock');
        EditionRecoverySet::safe($this->masterKeyFile, false);
        if (filesize($this->masterKeyFile) < 32 || filesize($this->masterKeyFile) > 128 || filesize($this->privateDirectory.'/install.lock') > 4096) {
            throw new \RuntimeException('Source key or installation lock is malformed.');
        }
        $lock = EditionData::read($this->privateDirectory.'/install.lock');
        if (1 !== ($lock['version'] ?? null)) {
            throw new \RuntimeException('Protected installation lock format is unsupported.');
        }
        foreach (['product_id', 'product_version', 'schema_version', 'upgrade_contract_version', 'installation_uuid'] as $field) {
            if (($lock[$field] ?? null) !== $identity[$field]) {
                throw new \RuntimeException('Protected installation lock identity disagrees with database.');
            }
        }
        $ledger = $this->connection->fetchAllAssociative('SELECT version, executed_at, execution_time FROM doctrine_migration_versions');
        if (1 !== \count($ledger) || 'DoctrineMigrations\\Version20261014000000' !== $ledger[0]['version']) {
            throw new \RuntimeException('Source migration ledger is unsupported.');
        }
        $users = $this->connection->fetchAllAssociative('SELECT id, active, singleton_key, password_hash FROM app_user');
        if (1 !== \count($users) || '1' !== $users[0]['singleton_key'] || 1 !== EditionData::integer($users[0]['active']) || 'argon2id' !== password_get_info(EditionData::text($users[0]['password_hash']))['algoName']) {
            throw new \RuntimeException('Light administrator invariant is invalid.');
        }
        $manifest = EditionData::read($this->projectDirectory.'/config/upgrade/light-schema-1.0.0.json');
        $columns = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            $columns[EditionData::text($row['TABLE_NAME'])][] = ['name' => $row['COLUMN_NAME'], 'type' => $row['COLUMN_TYPE'], 'nullable' => 'YES' === $row['IS_NULLABLE']];
        }
        $keys = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION') as $row) {
            $keys[] = ['table' => $row['TABLE_NAME'], 'constraint' => $row['CONSTRAINT_NAME'], 'column' => $row['COLUMN_NAME'], 'position' => EditionData::integer($row['ORDINAL_POSITION']), 'referenced_table' => $row['REFERENCED_TABLE_NAME'], 'referenced_column' => $row['REFERENCED_COLUMN_NAME']];
        }
        $indexes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $row) {
            $indexes[] = ['table' => $row['TABLE_NAME'], 'index' => $row['INDEX_NAME'], 'position' => EditionData::integer($row['SEQ_IN_INDEX']), 'column' => $row['COLUMN_NAME'], 'unique' => 0 === EditionData::integer($row['NON_UNIQUE'])];
        }
        $triggers = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME') as $row) {
            $triggers[] = ['name' => $row['TRIGGER_NAME'], 'event' => $row['EVENT_MANIPULATION'], 'table' => $row['EVENT_OBJECT_TABLE'], 'timing' => $row['ACTION_TIMING']];
        }
        if ($columns !== $manifest['tables'] || $keys !== $manifest['foreign_keys'] || $indexes !== $manifest['indexes'] || $triggers !== $manifest['triggers']) {
            throw new \RuntimeException('Unexpected source schema, relationship, index or trigger drift.');
        }
        $contract = EditionData::read($this->projectDirectory.'/config/upgrade/light-contract-1.0.json');
        $mapped = [];
        if (!\is_array($contract['table_mappings'])) {
            throw new \RuntimeException('Source contract mappings are malformed.');
        }
        foreach ($contract['table_mappings'] as $mapping) {
            $mapped[] = EditionData::text(EditionData::object($mapping)['source']);
        }
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);
        sort($mapped);
        if ($tables !== $mapped) {
            throw new \RuntimeException('Published contract does not cover the complete source table inventory.');
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', EditionData::text($identity['installation_uuid']))) {
            throw new \RuntimeException('Source installation identity is malformed.');
        }
        $this->verifyCompleteSchema();
        EditionIntegrity::assertForeignKeys($this->connection);
        if (file_exists($this->masterKeyFile.'.rotation-journal') || is_link($this->masterKeyFile.'.rotation-journal')) {
            throw new \RuntimeException('An unfinished key operation prevents read-only source inspection.');
        }
        $this->secrets->verifyLight(EditionData::text($identity['installation_uuid']));
        if (false !== $this->connection->fetchOne("SELECT 1 FROM recipient_token t LEFT JOIN recipient_token_key k ON k.key_version = t.key_version LEFT JOIN encrypted_secret s ON s.id = k.secret_id WHERE k.key_version IS NULL OR s.record_type <> 'recipient.token.key' OR s.id IS NULL LIMIT 1")) {
            throw new \RuntimeException('Source recipient links lack their explicit retained signing-key material.');
        }
        $this->verifyAudienceSnapshots();
        if ($requireIdle) {
            $this->assertIdle();
        }

        return ['identity' => $identity, 'migration' => $ledger[0], 'administrator_id' => $users[0]['id'], 'tables' => $tables];
    }

    private function verifyAudienceSnapshots(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM recipient_token t JOIN campaign_recipient r ON r.id = t.campaign_recipient_id WHERE t.campaign_id <> r.campaign_id OR t.recipient_id <> r.recipient_id LIMIT 1')) {
            throw new \RuntimeException('Source token and delivery scopes disagree.');
        }
        foreach ($this->connection->iterateAssociative('SELECT campaign_id, recipient_count, audience_sha256 FROM campaign_audience_snapshot') as $snapshot) {
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($this->connection->iterateAssociative('SELECT recipient_id, email_canonical, profile_json FROM campaign_audience_recipient WHERE campaign_id = ? ORDER BY email_canonical', [$snapshot['campaign_id']]) as $row) {
                if (0 < $count) {
                    hash_update($hash, "\n");
                }
                hash_update($hash, EditionData::text($row['recipient_id'])."\0".EditionData::text($row['email_canonical'])."\0".EditionData::text($row['profile_json']));
                ++$count;
            }
            if ($count !== EditionData::integer($snapshot['recipient_count']) || !hash_equals(EditionData::text($snapshot['audience_sha256']), hash_final($hash))) {
                throw new \RuntimeException('Source audience snapshot digest or count is inconsistent.');
            }
        }
    }

    private function verifyCompleteSchema(): void
    {
        $expected = EditionData::read($this->projectDirectory.'/config/upgrade/light-schema-details-1.0.0.json');
        foreach (EditionData::strings($expected['tables']) as $table => $ddl) {
            $actual = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteSingleIdentifier($table));
            if (false === $actual || EditionSchemaProof::baselineTable($table, $ddl) !== EditionSchemaProof::baselineTable($table, EditionData::text($actual['Create Table']))) {
                throw new \RuntimeException('Source check, default, generated-column or schema definition drift.');
            }
        }
        foreach (EditionData::strings($expected['triggers']) as $name => $ddl) {
            $actual = $this->connection->fetchAssociative('SHOW CREATE TRIGGER '.$this->connection->quoteSingleIdentifier($name));
            if (false === $actual || self::normalize($ddl) !== self::normalize(EditionData::text($actual['SQL Original Statement']))) {
                throw new \RuntimeException('Source trigger definition drift.');
            }
        }
    }

    private static function normalize(string $sql): string
    {
        $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+` | AUTO_INCREMENT=[0-9]+/', '', $sql) ?? $sql;

        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    public function assertIdle(): void
    {
        $runtime = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || 0 !== EditionData::integer($runtime['accepting_work']) || 0 !== EditionData::integer($runtime['active_claims'])) {
            throw new \RuntimeException('Delivery is not safely quiesced.');
        }
        foreach (["SELECT COUNT(*) FROM campaign_recipient WHERE state = 'processing' OR claimed_at IS NOT NULL", "SELECT COUNT(*) FROM campaign_test_delivery WHERE state = 'processing'", "SELECT COUNT(*) FROM delivery_attempt WHERE state = 'started'", 'SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NOT NULL', "SELECT COUNT(*) FROM release_operation WHERE status = 'started'", "SELECT COUNT(*) FROM worker_heartbeat WHERE worker_kind = 'worker' AND stopped_at IS NULL AND heartbeat_at > UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE", "SELECT COUNT(*) FROM operations_run WHERE status = 'started'"] as $sql) {
            if (0 !== EditionData::integer($this->connection->fetchOne($sql))) {
                throw new \RuntimeException('Active delivery claim, lease, worker or conflicting operation prevents transition.');
            }
        }
    }
}
