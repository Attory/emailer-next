<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$options = getopt('', ['artifact:', 'output:', 'version:', 'source:', 'minimum-source:', 'schema:', 'rollback-schema-compatible:', 'signing-key:', 'source-date-epoch:']);
$required = ['artifact', 'output', 'version', 'source', 'minimum-source', 'schema', 'rollback-schema-compatible', 'signing-key', 'source-date-epoch'];
foreach ($required as $name) {
    if (!isset($options[$name]) || !is_string($options[$name]) || '' === $options[$name]) {
        fwrite(STDERR, "Missing required option --$name.\n");
        exit(2);
    }
}
if (1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $options['version']) || 1 !== preg_match('/^[a-f0-9]{40}$/', $options['source']) || 1 !== preg_match('/^[0-9]{14}$/', $options['schema']) || !in_array($options['rollback-schema-compatible'], ['true', 'false'], true) || !ctype_digit($options['source-date-epoch'])) {
    fwrite(STDERR, "Release metadata is invalid.\n");
    exit(2);
}
$artifact = realpath($options['artifact']);
$outputDirectory = realpath($options['output']);
$keyStat = @lstat($options['signing-key']);
if (false === $artifact || !is_dir($artifact) || false === $outputDirectory || !is_dir($outputDirectory) || !is_array($keyStat) || 0o100000 !== ($keyStat['mode'] & 0o170000) || 1 !== $keyStat['nlink'] || 0 !== ($keyStat['mode'] & 0o077) || $keyStat['size'] > 256) {
    fwrite(STDERR, "Release input path or signing-key permissions are unsafe.\n");
    exit(2);
}
$secretEncoded = trim((string) file_get_contents($options['signing-key']));
try {
    $secret = sodium_base642bin($secretEncoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
} catch (SodiumException) {
    fwrite(STDERR, "Signing key is malformed.\n");
    exit(2);
} finally {
    sodium_memzero($secretEncoded);
}
if (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($secret)) {
    sodium_memzero($secret);
    fwrite(STDERR, "Signing key is malformed.\n");
    exit(2);
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($artifact, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact contains an unsafe object.\n");
        exit(2);
    }
    $relative = substr($file->getPathname(), strlen(rtrim($artifact, '/')) + 1);
    if ('' === $relative || str_contains($relative, "\0") || 1 === preg_match('~(^|/)\.\.(/|$)~', $relative)) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact path is unsafe.\n");
        exit(2);
    }
    $digest = hash_file('sha256', $file->getPathname());
    if (false === $digest) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact file cannot be hashed.\n");
        exit(2);
    }
    $files[$relative] = ['path' => $file->getPathname(), 'sha256' => $digest];
}
ksort($files, SORT_STRING);
$filename = 'emailer-next-'.$options['version'].'.zip';
$archive = $outputDirectory.'/'.$filename;
foreach ([$archive, $archive.'.manifest.json', $archive.'.sha256', $archive.'.spdx.json', $archive.'.sig'] as $target) {
    if (file_exists($target) || is_link($target)) {
        sodium_memzero($secret);
        fwrite(STDERR, "Release output already exists.\n");
        exit(2);
    }
}
$zip = new ZipArchive();
if (true !== $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL)) {
    sodium_memzero($secret);
    fwrite(STDERR, "Release archive cannot be created.\n");
    exit(2);
}
$epoch = max(315532800, (int) $options['source-date-epoch']);
foreach ($files as $relative => $entry) {
    if (!$zip->addFile($entry['path'], $relative) || !$zip->setMtimeName($relative, $epoch) || !$zip->setCompressionName($relative, ZipArchive::CM_DEFLATE, 9)) {
        $zip->close();
        @unlink($archive);
        sodium_memzero($secret);
        fwrite(STDERR, "Release archive assembly failed.\n");
        exit(2);
    }
}
$zip->close();
$digests = [];
foreach ($files as $relative => $entry) {
    $digests[$relative] = $entry['sha256'];
}
$manifest = ['format' => 'emailer-next-release-v1', 'version' => $options['version'], 'source_commit' => $options['source'], 'minimum_source_version' => $options['minimum-source'], 'minimum_schema_version' => $options['schema'], 'schema_version' => $options['schema'], 'rollback_schema_compatible' => 'true' === $options['rollback-schema-compatible'], 'platform' => ['php_min' => '8.4.0', 'php_max_exclusive' => '8.6.0', 'mariadb' => ['10.11', '11.8']], 'files' => $digests];
$manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$archiveHash = hash_file('sha256', $archive);
if (false === $archiveHash) {
    sodium_memzero($secret);
    fwrite(STDERR, "Release archive cannot be hashed.\n");
    exit(2);
}
$checksumBytes = $archiveHash.'  '.$filename."\n";
$lock = json_decode((string) file_get_contents($artifact.'/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
$packages = [];
foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
    if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
        $packages[] = ['SPDXID' => 'SPDXRef-Package-'.preg_replace('/[^A-Za-z0-9.-]/', '-', $package['name']), 'name' => $package['name'], 'versionInfo' => $package['version'], 'downloadLocation' => 'NOASSERTION', 'filesAnalyzed' => false, 'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => 'NOASSERTION', 'copyrightText' => 'NOASSERTION'];
    }
}
usort($packages, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
$sbom = ['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'Emailer Next '.$options['version'], 'documentNamespace' => 'https://attory.example.test/spdx/emailer-next/'.$options['source'].'/'.$options['version'], 'creationInfo' => ['created' => gmdate('Y-m-d\TH:i:s\Z', $epoch), 'creators' => ['Tool: emailer-next-phase8-builder']], 'packages' => $packages];
$sbomBytes = json_encode($sbom, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$signature = sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $secret);
sodium_memzero($secret);
file_put_contents($archive.'.manifest.json', $manifestBytes, LOCK_EX);
file_put_contents($archive.'.sha256', $checksumBytes, LOCK_EX);
file_put_contents($archive.'.spdx.json', $sbomBytes, LOCK_EX);
file_put_contents($archive.'.sig', sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n", LOCK_EX);
foreach ([$archive, $archive.'.manifest.json', $archive.'.sha256', $archive.'.spdx.json', $archive.'.sig'] as $target) {
    chmod($target, 0o644);
}
fwrite(STDOUT, $archive."\n");
