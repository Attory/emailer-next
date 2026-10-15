<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Retained-backup restore preparation is isolated-only.');
}
require '/tools/vendor/autoload.php';
$normal = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/runtime.env'));
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($normal['DATABASE_URL']);
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Retained-backup preparation refuses non-harness access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection([...$p, 'user' => 'root', 'password' => 'local-root-only']);
$c->executeStatement('ALTER USER '.$c->quote($p['user'])."@'%' ACCOUNT LOCK");
$c->executeStatement("UPDATE delivery_runtime SET accepting_work = 0 WHERE singleton_key = '1'");
if (0 !== (int) $c->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1'")) {
    throw new RuntimeException('Recovery clone has an active claim.');
}
// Keep the fresh-install key; use only the explicitly retained source key for
// authenticated Light restore. This changes no accepted Ripe installation.
rename('/rehearsal/keys/master.key', '/rehearsal/keys/fresh-install-unused.key');
copy('/old-export/keys/master.key', '/rehearsal/keys/master.key');
chown('/rehearsal/keys/master.key', 1000);
chgrp('/rehearsal/keys/master.key', 1000);
chmod('/rehearsal/keys/master.key', 0o600);
// The restored database carries the original UUID. Its protected installation
// lock is part of the recovery set, not an identity to regenerate on the clone.
copy('/old-export/private/install.lock', '/rehearsal/private/install.lock');
chown('/rehearsal/private/install.lock', 1000);
chgrp('/rehearsal/private/install.lock', 1000);
chmod('/rehearsal/private/install.lock', 0o600);
if (is_file('/old-export/keys/light-recovery.json')) {
    copy('/old-export/keys/light-recovery.json', '/rehearsal/keys/light-recovery.key');
    chmod('/rehearsal/keys/light-recovery.key', 0o600);
}
mkdir('/rehearsal/private/backups', 0o700);
chown('/rehearsal/private/backups', 1000);
chgrp('/rehearsal/private/backups', 1000);
$files = glob('/old-export/private/backups/*.enxbackup');
if (1 !== count($files)) {
    throw new RuntimeException('Exactly one original Light rehearsal backup is required.');
}
$backup = '/rehearsal/private/backups/'.basename($files[0]);
copy($files[0], $backup);
chown($backup, 1000);
chgrp($backup, 1000);
chmod($backup, 0o600);
file_put_contents('/rehearsal/old-backup-path', $backup);
chmod('/rehearsal/old-backup-path', 0o600);
file_put_contents('/rehearsal/old-backup-sha256', hash_file('sha256', $backup));
chmod('/rehearsal/old-backup-sha256', 0o600);
$mail = json_decode((string) file_get_contents('http://mailpit:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents('/rehearsal/old-backup-messages-before', (string) $mail['total']);
chmod('/rehearsal/old-backup-messages-before', 0o600);
echo "Disposable Light recovery clone fenced; retained key and original backup prepared.\n";
