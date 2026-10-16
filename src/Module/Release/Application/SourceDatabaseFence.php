<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;

/** Persistent source-writer fencing works without changing any published Light code. */
final readonly class SourceDatabaseFence
{
    public function __construct(private Connection $connection, private string $runtimeConfigurationFile, private string $replacementRuntimeFile = '')
    {
    }

    /** @return array{name: string, host: string, originally_locked: bool} */
    public function inspect(): array
    {
        EditionRecoverySet::safeConfiguration($this->runtimeConfigurationFile);
        $runtime = (new Dotenv())->parse(EditionData::text(file_get_contents($this->runtimeConfigurationFile)));
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse(EditionData::text($runtime['DATABASE_URL'] ?? ''));
        if (($parameters['dbname'] ?? null) !== $this->connection->getDatabase()) {
            throw new \RuntimeException('Source runtime configuration refers to another database.');
        }
        $application = DriverManager::getConnection($parameters);
        try {
            $account = EditionData::text($application->fetchOne('SELECT CURRENT_USER()'));
            if ($account === $this->connection->fetchOne('SELECT CURRENT_USER()')) {
                throw new \RuntimeException('Execution requires a separate privileged maintenance database identity; do not lock the application identity in use by the installer.');
            }
            if ($application->fetchAssociative('SELECT @@hostname host, @@port port, @@server_id server_id') !== $this->connection->fetchAssociative('SELECT @@hostname host, @@port port, @@server_id server_id')) {
                throw new \RuntimeException('Application and maintenance identities refer to different database servers.');
            }
        } finally {
            $application->close();
        }
        $separator = strrpos($account, '@');
        if (false === $separator) {
            throw new \RuntimeException('Application database account identity is malformed.');
        }
        $name = substr($account, 0, $separator);
        $host = substr($account, $separator + 1);
        if (1 !== EditionData::integer($this->connection->fetchOne('SELECT COUNT(*) FROM mysql.global_priv WHERE User = ?', [$name]))) {
            throw new \RuntimeException('Application username is shared by multiple server accounts; prepare an installation-specific identity before service fencing.');
        }
        $quoted = $this->connection->quote($name).'@'.$this->connection->quote($host);
        foreach (EditionData::list($this->connection->fetchFirstColumn('SHOW GRANTS FOR '.$quoted)) as $grant) {
            if (1 === preg_match('/^GRANT USAGE ON \*\.\*/', $grant)) {
                continue;
            }
            $database = EditionData::text($this->connection->getDatabase());
            if (!str_contains($grant, ' ON `'.str_replace('`', '``', $database).'`.* TO ') || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new \RuntimeException('The application database account has shared or global privileges; prepare a dedicated account before edition conversion.');
            }
        }
        $definition = $this->connection->fetchAssociative('SHOW CREATE USER '.$quoted);
        if (false === $definition || [] === $definition) {
            throw new \RuntimeException('Database account fencing authority is unavailable.');
        }
        $sql = EditionData::text(reset($definition));

        return ['name' => $name, 'host' => $host, 'originally_locked' => str_contains($sql, 'ACCOUNT LOCK')];
    }

    /** @param array{name: string, host: string, originally_locked: bool} $account */
    public function lock(array $account): void
    {
        $quoted = $this->connection->quote($account['name']).'@'.$this->connection->quote($account['host']);
        $this->connection->executeStatement('ALTER USER '.$quoted.' ACCOUNT LOCK');
        foreach ($this->connection->fetchFirstColumn('SELECT ID FROM information_schema.PROCESSLIST WHERE USER = ? AND ID <> CONNECTION_ID()', [$account['name']]) as $id) {
            $this->connection->executeStatement('KILL CONNECTION '.EditionData::integer($id));
        }
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            if (false === $this->connection->fetchOne('SELECT 1 FROM information_schema.PROCESSLIST WHERE USER = ? AND ID <> CONNECTION_ID()', [$account['name']])) {
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('Source application connections did not quiesce.');
    }

    /** @param array{name: string, host: string, originally_locked: bool} $account */
    public function restore(array $account): void
    {
        $quoted = $this->connection->quote($account['name']).'@'.$this->connection->quote($account['host']);
        $this->connection->executeStatement('ALTER USER '.$quoted.($account['originally_locked'] ? ' ACCOUNT LOCK' : ' ACCOUNT UNLOCK'));
    }

    /** @param array{name: string, host: string, originally_locked: bool} $source
     * @return array{name: string, host: string, originally_locked: bool}
     */
    public function replacement(array $source): array
    {
        $metadata = @lstat($this->replacementRuntimeFile);
        if (!\is_array($metadata) || 0 !== $metadata['uid']) {
            throw new \RuntimeException('Replacement runtime must be root controlled.');
        }
        for ($parent = \dirname($this->replacementRuntimeFile);; $parent = \dirname($parent)) {
            $metadata = @lstat($parent);
            if (!\is_array($metadata) || is_link($parent) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022)) {
                throw new \RuntimeException('Replacement runtime has an unsafe ancestor.');
            }
            if ('/' === $parent) {
                break;
            }
        }
        $replacement = (new self($this->connection, $this->replacementRuntimeFile))->inspect();
        $quoted = $this->connection->quote($source['name']).'@'.$this->connection->quote($source['host']);
        $definition = $this->connection->fetchAssociative('SHOW CREATE USER '.$quoted);
        if ($replacement['name'] === $source['name'] || $replacement['originally_locked'] || false === $definition || !str_contains(EditionData::text(reset($definition)), 'ACCOUNT LOCK')) {
            throw new \RuntimeException('Finish host provisioning with a new dedicated application database identity while retaining the original writer fence.');
        }

        return $replacement;
    }

    /** @return array{name: string, host: string, originally_locked: bool} */
    public static function account(mixed $value): array
    {
        $account = EditionData::object($value);
        if (!\is_bool($account['originally_locked'] ?? null)) {
            throw new \RuntimeException('Durable database fencing evidence is malformed.');
        }

        return ['name' => EditionData::text($account['name']), 'host' => EditionData::text($account['host']), 'originally_locked' => $account['originally_locked']];
    }
}
