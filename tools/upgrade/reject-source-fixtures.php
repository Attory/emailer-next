<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL') || 2 !== $argc || !in_array($argv[1], ['unsupported_version', 'schema_drift', 'mismatched_roots', 'restore'], true)) {
    throw new RuntimeException('Explicit isolated rejection fixture is required.');
}
require '/tools/vendor/autoload.php';
$url = getenv('DATABASE_URL');
if (!is_string($url)) {
    throw new RuntimeException('Disposable URL is required.');
}
$p = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
if ('database' !== ($p['host'] ?? null) || !str_starts_with($p['dbname'] ?? '', 'ripe106_')) {
    throw new RuntimeException('Rejection fixture refuses non-harness database access.');
}
$c = Doctrine\DBAL\DriverManager::getConnection($p);
if ('unsupported_version' === $argv[1]) {
    $c->executeStatement("UPDATE product_identity SET product_version = '1.0.1' WHERE singleton_key = '1'");
} elseif ('schema_drift' === $argv[1]) {
    $c->executeStatement('ALTER TABLE sender_identity ADD synthetic_drift INT DEFAULT NULL');
} elseif ('mismatched_roots' === $argv[1]) {
    copy('/rehearsal/runtime.env', '/rehearsal/runtime.env.before-root-rejection');
    chmod('/rehearsal/runtime.env.before-root-rejection', 0o600);
    file_put_contents('/rehearsal/runtime.env', str_replace('/rehearsal/private', '/rehearsal/unsupported-private', (string) file_get_contents('/rehearsal/runtime.env')));
} else {
    $c->executeStatement("UPDATE product_identity SET product_version = ? WHERE singleton_key = '1'", [getenv('EMAILER_REHEARSAL_LIGHT_VERSION') ?: '1.0.0']);
    if (isset($c->createSchemaManager()->listTableColumns('sender_identity')['synthetic_drift'])) {
        $c->executeStatement('ALTER TABLE sender_identity DROP COLUMN synthetic_drift');
    }
    if (is_file('/rehearsal/runtime.env.before-root-rejection')) {
        rename('/rehearsal/runtime.env.before-root-rejection', '/rehearsal/runtime.env');
        chmod('/rehearsal/runtime.env', 0o640);
        chgrp('/rehearsal/runtime.env', 1000);
    }
}
echo "Disposable rejection fixture prepared/restored.\n";
