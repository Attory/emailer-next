<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 0 !== posix_geteuid() || '/rehearsal/source-releases/ripe-1.1.0' !== realpath('/rehearsal/source-releases/current')) {
    throw new RuntimeException('Only the historical converted-source fixture is permitted.');
}
require '/tools/vendor/autoload.php';
$environment = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents('/rehearsal/runtime.env'));
$database = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($environment['DATABASE_URL']);
if ('database' !== ($database['host'] ?? null) || !str_starts_with($database['dbname'] ?? '', 'ripe106_') || 'https://localhost' !== ($environment['DEFAULT_URI'] ?? null)) {
    throw new RuntimeException('Recovery selection refuses non-harness configuration.');
}
$package = '/rehearsal/keys/converted-native-recovery.key';
$metadata = lstat($package);
$master = lstat('/rehearsal/keys/master.key');
if (!is_array($metadata) || !is_array($master) || 0o100000 !== ($metadata['mode'] & 0o170000) || 1 !== $metadata['nlink'] || 0o600 !== ($metadata['mode'] & 0o777) || 1000 !== $metadata['uid'] || $master['uid'] !== $metadata['uid'] || $metadata['size'] > 4096 || !is_file('/rehearsal/keys/ripe-recovery.key')) {
    throw new RuntimeException('The separately exported recovery package lacks application custody.');
}
foreach (['runtime.env', 'updater.env'] as $name) {
    $path = '/rehearsal/'.$name;
    $contents = file_get_contents($path);
    if (false === $contents || is_link($path)) {
        throw new RuntimeException('The fixture launcher is unsafe.');
    }
    $selected = preg_replace('/^EMAILER_RECOVERY_KEY_FILE=.*$/m', "EMAILER_RECOVERY_KEY_FILE='".$package."'", $contents, -1, $count);
    if (!is_string($selected) || 1 !== $count || strlen($selected) !== file_put_contents($path, $selected, LOCK_EX)) {
        throw new RuntimeException('The fixture recovery reference could not be selected.');
    }
}
echo "Application-owned native recovery selected; historical recovery package retained.\n";
