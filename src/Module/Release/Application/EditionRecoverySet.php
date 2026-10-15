<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

/** A complete DDL/data/private-state checkpoint, distinct from an application backup. */
final class EditionRecoverySet
{
    private const CHUNK = 65536;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string, string> $roots */
    public function create(string $directory, array $roots): void
    {
        self::directory($directory);
        $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        self::write($directory.'/recovery.key', $key);
        $plain = $directory.'/.checkpoint.zip';
        $staging = fopen($plain, 'x+b');
        if (false === $staging || !chmod($plain, 0o600)) {
            throw new \RuntimeException('Recovery checkpoint staging failed.');
        }
        fclose($staging);
        $zip = new \ZipArchive();
        if (true !== $zip->open($plain, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Recovery checkpoint staging failed.');
        }
        $temporary = [];
        $closed = false;
        $manifest = ['format' => 'emailer-edition-recovery-v1', 'schema' => [], 'triggers' => [], 'entries' => [], 'roots' => $roots, 'file_metadata' => [], 'directory_metadata' => [], 'parent_metadata' => []];
        try {
            foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
                $definition = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteSingleIdentifier($table));
                $manifest['schema'][$table] = $definition['Create Table'] ?? throw new \RuntimeException('Recovery schema unavailable.');
                $path = $directory.'/.table-'.$table;
                $temporary[] = $path;
                $out = fopen($path, 'x+b');
                if (false === $out || !chmod($path, 0o600)) {
                    throw new \RuntimeException('Recovery data staging failed.');
                }
                try {
                    foreach ($this->connection->iterateAssociative('SELECT * FROM '.$this->connection->quoteSingleIdentifier($table)) as $row) {
                        $encoded = [];
                        foreach ($row as $column => $value) {
                            $encoded[$column] = null === $value ? null : base64_encode(EditionData::scalar($value) ?? '');
                        }
                        $line = json_encode($encoded, JSON_THROW_ON_ERROR)."\n";
                        if (\strlen($line) !== fwrite($out, $line)) {
                            throw new \RuntimeException('Recovery data write failed.');
                        }
                    }
                    if (!fflush($out) || !fsync($out)) {
                        throw new \RuntimeException('Recovery data durability failed.');
                    }
                } finally {
                    fclose($out);
                }
                $name = 'database/'.$table.'.jsonl';
                $zip->addFile($path, $name);
                $manifest['entries'][$name] = hash_file('sha256', $path);
            }
            foreach ($this->connection->fetchFirstColumn('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME') as $name) {
                $trigger = $this->connection->fetchAssociative('SHOW CREATE TRIGGER '.$this->connection->quoteSingleIdentifier(EditionData::text($name)));
                $manifest['triggers'][] = EditionData::text(false === $trigger ? null : $trigger['SQL Original Statement']);
            }
            foreach ($roots as $role => $root) {
                if ('runtime_configuration' === $role) {
                    self::safeConfiguration($root);
                } elseif ('private' === $role) {
                    self::safePrivate($root, is_dir($root));
                } else {
                    self::safe($root, is_dir($root));
                }
                if (is_dir($root)) {
                    $metadata = lstat($root);
                    if (false === $metadata) {
                        throw new \RuntimeException('Recovery directory metadata is unavailable.');
                    }
                    $manifest['directory_metadata']['private/'.$role.'/'] = ['uid' => $metadata['uid'], 'gid' => $metadata['gid'], 'mode' => $metadata['mode'] & 0o777];
                }
                if (is_file($root)) {
                    $parent = lstat(\dirname($root));
                    if (false === $parent) {
                        throw new \RuntimeException('Recovery parent metadata is unavailable.');
                    }
                    $manifest['parent_metadata'][$role] = ['uid' => $parent['uid'], 'gid' => $parent['gid'], 'mode' => $parent['mode'] & 0o777];
                    $files = [$root];
                } else {
                    $files = [];
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                        if (!$entry instanceof \SplFileInfo) {
                            throw new \RuntimeException('Recovery private-state inventory failed.');
                        }
                        'private' === $role ? self::safePrivate($entry->getPathname(), $entry->isDir()) : self::safe($entry->getPathname(), $entry->isDir());
                        if ($entry->isDir()) {
                            $metadata = lstat($entry->getPathname());
                            if (false === $metadata) {
                                throw new \RuntimeException('Recovery directory metadata is unavailable.');
                            }
                            $manifest['directory_metadata']['private/'.$role.'/'.substr($entry->getPathname(), \strlen($root) + 1)] = ['uid' => $metadata['uid'], 'gid' => $metadata['gid'], 'mode' => $metadata['mode'] & 0o777];
                        }
                        if ($entry->isFile()) {
                            $files[] = $entry->getPathname();
                        }
                    }
                }
                foreach ($files as $file) {
                    $relative = is_file($root) ? 'file' : substr($file, \strlen($root) + 1);
                    $name = 'private/'.$role.'/'.$relative;
                    if ('private' === $role && 'edition-delivery-hold.json' === $relative) {
                        continue;
                    }
                    $metadata = lstat($file);
                    if (false === $metadata) {
                        throw new \RuntimeException('Recovery file metadata is unavailable.');
                    }
                    $manifest['file_metadata'][$name] = ['uid' => $metadata['uid'], 'gid' => $metadata['gid'], 'mode' => $metadata['mode'] & 0o777];
                    $zip->addFile($file, $name);
                    $manifest['entries'][$name] = hash_file('sha256', $file);
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $closed = $zip->close();
            if (!$closed) {
                throw new \RuntimeException('Recovery archive publication failed.');
            }
            $this->crypt($plain, $directory.'/checkpoint.enxrecovery', $key, true);
            $this->verify($directory);
        } finally {
            if (!$closed) {
                $zip->close();
            }
            foreach (array_merge($temporary, [$plain]) as $path) {
                @unlink($path);
            }
            sodium_memzero($key);
        }
    }

    /** @return array{format: string, schema: array<string, string>, triggers: list<string>, entries: array<string, string>, roots: array<string, string>, file_metadata: array<string, array{uid: int, gid: int, mode: int}>, directory_metadata: array<string, array{uid: int, gid: int, mode: int}>, parent_metadata: array<string, array{uid: int, gid: int, mode: int}>} */
    public function verify(string $directory): array
    {
        [$zip, $plain] = $this->open($directory);
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($manifest) || 'emailer-edition-recovery-v1' !== ($manifest['format'] ?? null) || !\is_array($manifest['entries'] ?? null) || $zip->numFiles !== 1 + \count($manifest['entries'])) {
                throw new \RuntimeException('Recovery manifest is invalid.');
            }
            $manifest = ['format' => 'emailer-edition-recovery-v1', 'schema' => EditionData::strings($manifest['schema'] ?? null), 'triggers' => EditionData::list($manifest['triggers'] ?? null), 'entries' => EditionData::strings($manifest['entries']), 'roots' => EditionData::strings($manifest['roots'] ?? null), 'file_metadata' => self::metadata($manifest['file_metadata'] ?? null), 'directory_metadata' => self::metadata($manifest['directory_metadata'] ?? null), 'parent_metadata' => self::metadata($manifest['parent_metadata'] ?? null)];
            foreach ($manifest['entries'] as $name => $digest) {
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    throw new \RuntimeException('Recovery content is missing.');
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (!hash_equals($digest, hash_final($hash))) {
                    throw new \RuntimeException('Recovery content is corrupt.');
                }
            }

            return $manifest;
        } finally {
            $zip->close();
            unlink($plain);
        }
    }

    /** Restore ONLY under the transition journal and maintenance lock, or into a disposable drill schema. */
    public function restore(string $directory, Connection $target, bool $privateState): void
    {
        $manifest = $this->verify($directory);
        [$zip, $plain] = $this->open($directory);
        try {
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($target->createSchemaManager()->listTableNames() as $table) {
                $target->executeStatement('DROP TABLE '.$target->quoteSingleIdentifier($table));
            }
            foreach ($manifest['schema'] as $ddl) {
                $target->executeStatement($ddl);
            }
            foreach ($manifest['schema'] as $table => $ddl) {
                $generated = EditionData::list($target->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%GENERATED%'", [$table]));
                $stream = $zip->getStream('database/'.$table.'.jsonl');
                if (false === $stream) {
                    throw new \RuntimeException('Recovery table is unavailable.');
                }
                try {
                    while (false !== ($line = fgets($stream))) {
                        $row = EditionData::object(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
                        foreach ($row as $column => &$value) {
                            $value = null === $value ? null : base64_decode(EditionData::text($value), true);
                            if (false === $value) {
                                throw new \RuntimeException('Recovery table encoding is invalid.');
                            }
                        }
                        unset($value);
                        foreach ($generated as $column) {
                            unset($row[$column]);
                        }
                        $target->insert($table, $row);
                    }
                } finally {
                    fclose($stream);
                }
            }
            foreach ($manifest['triggers'] as $ddl) {
                $target->executeStatement($ddl);
            }
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            EditionIntegrity::assertForeignKeys($target);
            if ($privateState) {
                $this->quarantineAddedPrivateFiles($directory, $manifest);
                foreach ($manifest['directory_metadata'] as $name => $metadata) {
                    [, $role, $relative] = explode('/', $name, 3);
                    if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
                        throw new \RuntimeException('Recovery directory path is unsafe.');
                    }
                    $path = rtrim($manifest['roots'][$role].'/'.$relative, '/');
                    if (!is_dir($path) && !mkdir($path, 0o700, true)) {
                        throw new \RuntimeException('Recovery private directory is unavailable.');
                    }
                    self::safePrivate($path, true);
                    if (!chown($path, $metadata['uid']) || !chgrp($path, $metadata['gid']) || !chmod($path, $metadata['mode'])) {
                        throw new \RuntimeException('Recovery directory ownership restoration failed.');
                    }
                }
                foreach ($manifest['entries'] as $name => $digest) {
                    if (!str_starts_with($name, 'private/')) {
                        continue;
                    }
                    [, $role, $relative] = explode('/', $name, 3);
                    if ('private' === $role && 'edition-delivery-hold.json' === $relative) {
                        continue;
                    }
                    $root = $manifest['roots'][$role];
                    $path = 'file' === $relative ? $root : $root.'/'.$relative;
                    if (str_contains($relative, '..') || str_starts_with($relative, '/') || is_link($path)) {
                        throw new \RuntimeException('Recovery private-state path is unsafe.');
                    }
                    $parent = \dirname($path);
                    $parentWasMissing = !is_dir($parent);
                    if (!is_dir($parent) && !mkdir($parent, 0o700, true)) {
                        throw new \RuntimeException('Recovery private-state parent is unavailable.');
                    }
                    if ($parentWasMissing && isset($manifest['parent_metadata'][$role])) {
                        $metadata = $manifest['parent_metadata'][$role];
                        if (!chown($parent, $metadata['uid']) || !chgrp($parent, $metadata['gid']) || !chmod($parent, $metadata['mode'])) {
                            throw new \RuntimeException('Recovery parent ownership restoration failed.');
                        }
                    }
                    $parentMetadata = lstat($parent);
                    if (false === $parentMetadata || is_link($parent) || 0 !== ($parentMetadata['mode'] & 0o022)) {
                        throw new \RuntimeException('Recovery private-state parent is unsafe.');
                    }
                    $stream = $zip->getStream($name);
                    if (false === $stream) {
                        throw new \RuntimeException('Recovery private state is unavailable.');
                    }
                    try {
                        self::writeStream($path, $stream, \in_array($role, ['runtime_configuration', 'private'], true));
                        $metadata = $manifest['file_metadata'][$name] ?? throw new \RuntimeException('Recovery file ownership evidence is missing.');
                        if (!chown($path, $metadata['uid']) || !chgrp($path, $metadata['gid']) || !chmod($path, $metadata['mode'])) {
                            throw new \RuntimeException('Recovery file ownership restoration failed.');
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            }
        } finally {
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $zip->close();
            unlink($plain);
        }
    }

    /** Export to an EMPTY isolated tree; never restores a running installation. */
    public function exportLightFiles(string $directory, string $destination): void
    {
        $this->verify($directory);
        self::directory($destination);
        $entries = scandir($destination);
        if (false === $entries || 2 !== \count($entries)) {
            throw new \RuntimeException('Light recovery export requires an empty protected destination.');
        }
        [$zip, $plain] = $this->open($directory);
        try {
            $manifest = $this->verify($directory);
            foreach ($manifest['entries'] as $name => $digest) {
                if (!str_starts_with($name, 'private/')) {
                    continue;
                }
                [, $role, $relative] = explode('/', $name, 3);
                $relativePath = match ($role) {
                    'private' => 'private/'.$relative,
                    'master_key' => 'keys/master.key',
                    'light_recovery_key' => 'keys/light-recovery.json',
                    'runtime_configuration' => 'runtime.env.REFERENCE-ONLY',
                    default => null,
                };
                if (null === $relativePath || 'edition-delivery-hold.json' === $relative || str_contains($relativePath, '..')) {
                    continue;
                }
                $path = $destination.'/'.$relativePath;
                self::directory(\dirname($path));
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    throw new \RuntimeException('Light recovery export entry is unavailable.');
                }
                try {
                    self::writeStream($path, $stream);
                } finally {
                    fclose($stream);
                }
                if (!hash_equals($digest, (string) hash_file('sha256', $path))) {
                    throw new \RuntimeException('Light recovery export integrity failed.');
                }
            }
        } finally {
            $zip->close();
            unlink($plain);
        }
    }

    /** @return array{\ZipArchive, string} */
    private function open(string $directory): array
    {
        self::safe($directory.'/recovery.key', false);
        self::safe($directory.'/checkpoint.enxrecovery', false);
        $key = (string) file_get_contents($directory.'/recovery.key');
        $plain = tempnam($directory, '.verify-');
        if (false === $plain) {
            throw new \RuntimeException('Recovery inspection staging failed.');
        }
        chmod($plain, 0o600);
        try {
            $this->crypt($directory.'/checkpoint.enxrecovery', $plain, $key, false);
            $zip = new \ZipArchive();
            if (true !== $zip->open($plain)) {
                throw new \RuntimeException('Recovery archive is invalid.');
            }

            return [$zip, $plain];
        } catch (\Throwable $exception) {
            unlink($plain);
            throw $exception;
        } finally {
            sodium_memzero($key);
        }
    }

    private function crypt(string $source, string $destination, string $key, bool $encrypt): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if (false === $in || false === $out || !chmod($destination, 0o600)) {
            throw new \RuntimeException('Recovery encryption stream failed.');
        }
        try {
            if ($encrypt) {
                $initial = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
                $state = EditionData::text($initial[0]);
                $header = EditionData::text($initial[1]);
                fwrite($out, $header);
                do {
                    $chunk = fread($in, self::CHUNK);
                    if (false === $chunk) {
                        throw new \RuntimeException('Recovery encryption read failed.');
                    }
                    $last = feof($in);
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, 'emailer-edition-recovery-v1', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0);
                    $frame = pack('N', \strlen($cipher)).$cipher;
                    if (\strlen($frame) !== fwrite($out, $frame)) {
                        throw new \RuntimeException('Recovery encryption write failed.');
                    }
                } while (!$last);
            } else {
                $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) $header, $key);
                $last = false;
                while (!$last) {
                    $prefix = fread($in, 4);
                    if (false === $prefix || 4 !== \strlen($prefix)) {
                        throw new \RuntimeException('Recovery framing is invalid.');
                    }
                    $unpacked = unpack('Nlength', $prefix);
                    $length = EditionData::integer(false === $unpacked ? null : $unpacked['length']);
                    if ($length < 17 || $length > self::CHUNK + 17) {
                        throw new \RuntimeException('Recovery frame size is invalid.');
                    }
                    $cipher = fread($in, $length);
                    $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) $cipher, 'emailer-edition-recovery-v1');
                    if (false === $result) {
                        throw new \RuntimeException('Recovery authentication failed.');
                    }
                    $chunk = EditionData::text($result[0]);
                    $tag = EditionData::integer($result[1]);
                    $last = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag;
                    if (\strlen($chunk) !== fwrite($out, $chunk)) {
                        throw new \RuntimeException('Recovery decryption write failed.');
                    }
                }
                if ('' !== fread($in, 1)) {
                    throw new \RuntimeException('Recovery contains trailing data.');
                }
            }
            if (!fflush($out) || !fsync($out)) {
                throw new \RuntimeException('Recovery encryption durability failed.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        self::syncDirectory(\dirname($destination));
    }

    /** @return array<string, array{uid: int, gid: int, mode: int}> */
    private static function metadata(mixed $value): array
    {
        $result = [];
        foreach (EditionData::object($value) as $name => $entry) {
            $entry = EditionData::object($entry);
            $mode = EditionData::integer($entry['mode'] ?? null);
            if (0 > $mode || 0o777 < $mode || 0 !== ($mode & 0o022)) {
                throw new \RuntimeException('Recovery ownership metadata is unsafe.');
            }
            $result[$name] = ['uid' => EditionData::integer($entry['uid'] ?? null), 'gid' => EditionData::integer($entry['gid'] ?? null), 'mode' => $mode];
        }

        return $result;
    }

    /** @param array<string, mixed> $manifest */
    private function quarantineAddedPrivateFiles(string $directory, array $manifest): void
    {
        $roots = EditionData::strings($manifest['roots']);
        $entries = EditionData::strings($manifest['entries']);
        $root = $roots['private'] ?? null;
        if (null === $root || !is_dir($root)) {
            return;
        }
        self::safePrivate($root, true);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Recovery private-state quarantine inventory failed.');
            }
            $path = $entry->getPathname();
            $relative = substr($path, \strlen($root) + 1);
            if ('edition-delivery-hold.json' === $relative) {
                continue;
            }
            self::safePrivate($path, $entry->isDir());
            if ($entry->isDir() || isset($entries['private/private/'.$relative])) {
                continue;
            }
            // Retain additions from an unfinished target in protected quarantine;
            // they must not masquerade as source assets or source-format backups.
            $quarantine = $directory.'/post-checkpoint-private/'.$relative;
            self::directory(\dirname($quarantine));
            if (file_exists($quarantine) || is_link($quarantine)) {
                self::safe($quarantine, false);
                if (hash_file('sha256', $path) !== hash_file('sha256', $quarantine) || !unlink($path)) {
                    throw new \RuntimeException('Interrupted recovery quarantine evidence disagrees.');
                }
                self::syncDirectory(\dirname($path));
                continue;
            }
            $temporary = $quarantine.'.pending-'.bin2hex(random_bytes(8));
            $input = fopen($path, 'rb');
            $output = fopen($temporary, 'x+b');
            if (false === $input || false === $output || !chmod($temporary, 0o600)) {
                throw new \RuntimeException('Recovery quarantine could not retain target additions.');
            }
            try {
                if (false === stream_copy_to_stream($input, $output) || !fflush($output) || !fsync($output)) {
                    throw new \RuntimeException('Recovery quarantine durability failed.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if (hash_file('sha256', $path) !== hash_file('sha256', $temporary) || !rename($temporary, $quarantine)) {
                throw new \RuntimeException('Recovery quarantine verification failed.');
            }
            self::syncDirectory(\dirname($quarantine));
            if (!unlink($path)) {
                throw new \RuntimeException('Recovery could not isolate a retained target addition.');
            }
            self::syncDirectory(\dirname($path));
        }
    }

    public static function directory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new \RuntimeException('Protected recovery directory is unavailable.');
        }
        self::safe($directory, true);
        self::syncDirectory(\dirname($directory));
    }

    public static function safe(string $path, bool $directory): void
    {
        $ancestor = $path;
        while ('/' !== $ancestor && '.' !== $ancestor) {
            if (is_link($ancestor)) {
                throw new \RuntimeException('Protected path has a linked ancestor.');
            }
            $ancestor = \dirname($ancestor);
        }
        $stat = lstat($path);
        if (false === $stat || ($directory ? 0o040000 : 0o100000) !== ($stat['mode'] & 0o170000) || (!$directory && 1 !== $stat['nlink']) || 0 !== ($stat['mode'] & 0o077) || !str_starts_with($path, '/')) {
            throw new \RuntimeException('Protected recovery path has unsafe metadata.');
        }
    }

    private static function syncDirectory(string $path): void
    {
        $stream = fopen($path, 'r');
        if (false === $stream) {
            throw new \RuntimeException('Recovery directory durability is unavailable.');
        }
        try {
            if (!fsync($stream)) {
                throw new \RuntimeException('Recovery directory durability failed.');
            }
        } finally {
            fclose($stream);
        }
    }

    public static function safePrivate(string $path, bool $directory): void
    {
        if (!$directory) {
            self::safeConfiguration($path);

            return;
        }
        for ($ancestor = $path; '/' !== $ancestor; $ancestor = \dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new \RuntimeException('Private recovery state has a linked ancestor.');
            }
        }
        $stat = lstat($path);
        if (false === $stat || !str_starts_with($path, '/') || 0o040000 !== ($stat['mode'] & 0o170000) || !\in_array($stat['mode'] & 0o777, [0o700, 0o750, 0o500, 0o550], true)) {
            throw new \RuntimeException('Private recovery directory metadata is unsafe.');
        }
    }

    public static function safeConfiguration(string $path): void
    {
        $stat = @lstat($path);
        if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || !\in_array($stat['mode'] & 0o777, [0o600, 0o640, 0o400, 0o440], true) || !str_starts_with($path, '/')) {
            throw new \RuntimeException('Protected runtime configuration metadata is unsafe.');
        }
        for ($ancestor = $path; '/' !== $ancestor; $ancestor = \dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new \RuntimeException('Protected runtime configuration has a linked ancestor.');
            }
        }
    }

    /** @param resource $input */
    private static function writeStream(string $path, $input, bool $configuration = false): void
    {
        $previous = @lstat($path);
        if (\is_array($previous)) {
            $configuration ? self::safeConfiguration($path) : self::safe($path, false);
        }
        $temporary = $path.'.new-'.bin2hex(random_bytes(8));
        $output = fopen($temporary, 'x+b');
        if (false === $output || !chmod($temporary, 0o600)) {
            throw new \RuntimeException('Recovery private-state stream publication failed.');
        }
        try {
            if (false === stream_copy_to_stream($input, $output) || !fflush($output) || !fsync($output)) {
                throw new \RuntimeException('Recovery private-state stream durability failed.');
            }
        } finally {
            fclose($output);
        }
        if (\is_array($previous) && (!chown($temporary, $previous['uid']) || !chgrp($temporary, $previous['gid']) || !chmod($temporary, $previous['mode'] & 0o777))) {
            throw new \RuntimeException('Recovery private-state stream ownership failed.');
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Recovery private-state stream activation failed.');
        }
        self::syncDirectory(\dirname($path));
    }

    public static function write(string $path, string $contents, bool $configuration = false): void
    {
        $previous = @lstat($path);
        if ($configuration && \is_array($previous)) {
            self::safeConfiguration($path);
        }
        if (\is_array($previous) && (0o100000 !== ($previous['mode'] & 0o170000) || 1 !== $previous['nlink'] || (!$configuration && 0 !== ($previous['mode'] & 0o077)))) {
            throw new \RuntimeException('Protected state replacement metadata is unsafe.');
        }
        $temporary = $path.'.new-'.bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (false === $stream || !chmod($temporary, 0o600)) {
            throw new \RuntimeException('Protected state publication failed.');
        }
        try {
            if (\strlen($contents) !== fwrite($stream, $contents) || !fflush($stream) || !fsync($stream)) {
                throw new \RuntimeException('Protected state durability failed.');
            }
        } finally {
            fclose($stream);
        }
        if (\is_array($previous)) {
            if (!chmod($temporary, $previous['mode'] & 0o777) || !chown($temporary, $previous['uid']) || !chgrp($temporary, $previous['gid'])) {
                throw new \RuntimeException('Protected state ownership could not be preserved.');
            }
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Protected state activation failed.');
        }
        $parent = fopen(\dirname($path), 'r');
        if (false === $parent || !fsync($parent)) {
            throw new \RuntimeException('Protected state directory durability failed.');
        }
        fclose($parent);
    }
}
