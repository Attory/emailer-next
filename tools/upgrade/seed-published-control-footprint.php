<?php

declare(strict_types=1);

// Synthetic acceptance only: exact affected source code prepares its real0077
// namespace. The fixed target must repair it and preserve an unrelated hold.
ini_set('zend.exception_ignore_args', '1');
if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || '1.3.5' !== getenv('EMAILER_REHEARSAL_NATIVE_SOURCE') || 0 !== posix_geteuid() || '/rehearsal/private' !== getenv('EMAILER_PRIVATE_DIR')) {
    throw new RuntimeException('Published control footprint needs its isolated native135 fixture.');
}
require '/rehearsal/source-releases/1.3.5/vendor/autoload.php';
$params = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse((string) getenv('DATABASE_URL'));
if ('database' !== ($params['host'] ?? null) || !str_starts_with($params['dbname'] ?? '', 'ripe106_') || file_exists('/rehearsal/delivery-control')) {
    throw new RuntimeException('Published control footprint refuses unrelated or existing state.');
}
$db = Doctrine\DBAL\DriverManager::getConnection($params);
$id = $db->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
if (!is_string($id) || 0 !== (int) $db->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
    throw new RuntimeException('Expected source safety gate is unavailable.');
}
$files = new App\Shared\DeliveryControlFiles('/rehearsal/private', $id);
$mask = umask(0o077);
try {
    $files->prepare(1000, 1000, 'enabled');
    $files->hold('01900000-0000-7000-8000-000000000135', 'manual', ['source' => 'independent-synthetic-operation']);
} finally {
    umask($mask);
}
foreach (['/rehearsal/delivery-control', '/rehearsal/delivery-control/'.$id, '/rehearsal/delivery-control/'.$id.'/holds'] as $path) {
    if (0o700 !== (fileperms($path) & 0o777)) {
        throw new RuntimeException('Affected package did not reproduce its published permissions.');
    }
}
echo "Actual135 restrictive control footprint and independent hold prepared; no mail.\n";
