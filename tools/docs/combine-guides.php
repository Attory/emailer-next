<?php

declare(strict_types=1);

// Private release tooling: authenticate public documents before combining editions.
if (6 !== $argc || !ctype_digit($argv[5]) || file_exists($argv[4])) {
    throw new RuntimeException('Expected official Light runtime assets, corrected Light guides, verified Ripe guides, new ZIP and source epoch.');
}
$read = static function (string $path): string {
    $stat = lstat($path);
    if (!is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || $stat['size'] > 16777216) {
        throw new RuntimeException('Documentation input is missing, linked or oversized.');
    }
    $bytes = file_get_contents($path);
    if (false === $bytes) {
        throw new RuntimeException('Documentation input could not be read.');
    }

    return $bytes;
};
$publicKey = sodium_base642bin('2zO47r_uQtv-lcMryvh_wWijpyCF1YAfBXBGi9W8B1E', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$checksums = $read($argv[1].'/SHA256SUMS');
$signature = sodium_base642bin(trim($read($argv[1].'/SHA256SUMS.sig')), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
if (SODIUM_CRYPTO_SIGN_BYTES !== strlen($signature) || !sodium_crypto_sign_verify_detached($signature, $checksums, $publicKey)) {
    throw new RuntimeException('Official Light documentation checksum authentication failed.');
}
$inventory = [];
foreach (explode("\n", trim($checksums)) as $line) {
    if (1 !== preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/D', $line, $match) || isset($inventory[$match[2]])) {
        throw new RuntimeException('The official Light checksum inventory is malformed.');
    }
    $inventory[$match[2]] = $match[1];
}
$public = static function (string $name) use ($argv, $inventory, $read): string {
    $bytes = $read($argv[1].'/'.$name);
    if (!isset($inventory[$name]) || !hash_equals($inventory[$name], hash('sha256', $bytes))) {
        throw new RuntimeException('An official Light documentation asset failed verification.');
    }

    return $bytes;
};
$identity = json_decode($public('BUILD-IDENTITY.json'), true, 32, JSON_THROW_ON_ERROR);
$originalDocs = json_decode($public('DOCS-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
$ripe = json_decode($read($argv[3].'/DOCS-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
if ('emailer-light' !== ($identity['product'] ?? null) || '1.1.0' !== ($identity['version'] ?? null) || false !== ($identity['test_only'] ?? null)
    || !is_string($identity['source_commit'] ?? null) || 1 !== preg_match('/^[a-f0-9]{40}$/D', $identity['source_commit']) || $identity['source_commit'] !== ($originalDocs['source_commit'] ?? null)
    || 'emailer-docs-v1' !== ($originalDocs['format'] ?? null) || 'emailer-docs-v1' !== ($ripe['format'] ?? null)) {
    throw new RuntimeException('The combined guides do not identify the supported official releases.');
}
$runtime = $argv[1].'/emailer-light-1.1.0.zip';
$runtimeStat = lstat($runtime);
if (!is_array($runtimeStat) || 0o100000 !== ($runtimeStat['mode'] & 0o170000)) {
    throw new RuntimeException('The Light runtime must be an unlinked regular release file.');
}
$runtimeHash = hash_file('sha256', $runtime);
$runtimeManifest = json_decode($public('emailer-light-1.1.0.zip.manifest.json'), true, 64, JSON_THROW_ON_ERROR);
if (!is_string($runtimeHash)
    || !isset($inventory['emailer-light-1.1.0.zip']) || !hash_equals($inventory['emailer-light-1.1.0.zip'], $runtimeHash)
    || 'emailer-light-release-v1' !== ($runtimeManifest['format'] ?? null) || '1.1.0' !== ($runtimeManifest['version'] ?? null)
    || $identity['source_commit'] !== ($runtimeManifest['source_commit'] ?? null)) {
    throw new RuntimeException('The Light runtime and documentation are not from the same authenticated release.');
}
// Documentation corrections have their own immutable signed release. Their
// source may differ from the application, but the runtime hash and source must
// still match the authenticated published application and bundled predecessor.
$revisionSums = $read($argv[2].'/SHA256SUMS');
$revisionSignature = sodium_base642bin(trim($read($argv[2].'/SHA256SUMS.sig')), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
if (!sodium_crypto_sign_verify_detached($revisionSignature, $revisionSums, $publicKey)) {
    throw new RuntimeException('The corrected Light documentation inventory is not authentic.');
}
$revisionInventory = [];
foreach (explode("\n", trim($revisionSums)) as $line) {
    if (1 !== preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/D', $line, $match) || isset($revisionInventory[$match[2]])) {
        throw new RuntimeException('The corrected documentation inventory is malformed.');
    }
    $revisionInventory[$match[2]] = $match[1];
}
$guide = static function (string $name) use ($argv, $revisionInventory, $read): string {
    $bytes = $read($argv[2].'/'.$name);
    if (!isset($revisionInventory[$name]) || !hash_equals($revisionInventory[$name], hash('sha256', $bytes))) {
        throw new RuntimeException('A corrected Light documentation asset failed authentication.');
    }

    return $bytes;
};
$revision = json_decode($guide('DOCUMENTATION-RELEASE.json'), true, 32, JSON_THROW_ON_ERROR);
$light = json_decode($guide('DOCS-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
if ('emailer-light-docs-release-v1' !== ($revision['format'] ?? null) || 'emailer-light' !== ($revision['product'] ?? null)
    || '1.1.0' !== ($revision['application_version'] ?? null) || 1 !== ($revision['documentation_revision'] ?? null)
    || 'docs-v1.1.0-r1' !== ($revision['release_tag'] ?? null) || 'emailer-docs-v1' !== ($light['format'] ?? null)
    || !is_string($revision['source_commit'] ?? null) || 1 !== preg_match('/^[a-f0-9]{40}$/D', $revision['source_commit'])
    || $revision['source_commit'] !== ($light['source_commit'] ?? null)
    || $identity['source_commit'] !== ($revision['runtime_source_commit'] ?? null) || $runtimeHash !== ($revision['runtime_sha256'] ?? null)) {
    throw new RuntimeException('The corrected documentation does not identify this authenticated Light runtime.');
}
$files = [];
foreach (['emailer-light-install', 'emailer-light-manual', 'emailer-light-operator'] as $stem) {
    foreach (['pdf', 'html'] as $extension) {
        $name = $stem.'.'.$extension;
        $files[$name] = $guide($name);
        if (!isset($light['outputs'][$name]) || !hash_equals($light['outputs'][$name], hash('sha256', $files[$name]))) {
            throw new RuntimeException('The Light guide provenance differs from its signed release.');
        }
    }
}
foreach (['emailer-ripe-install', 'emailer-light-to-ripe-upgrade', 'emailer-ripe-manual', 'emailer-ripe-operator'] as $stem) {
    foreach (['pdf', 'html'] as $extension) {
        $name = $stem.'.'.$extension;
        $files[$name] = $read($argv[3].'/'.$name);
        if (!isset($ripe['outputs'][$name]) || !hash_equals($ripe['outputs'][$name], hash('sha256', $files[$name]))) {
            throw new RuntimeException('A previously verified Ripe guide changed before combination.');
        }
    }
}
$files['FONT-LICENSE.txt'] = $guide('FONT-LICENSE.txt');
if ($files['FONT-LICENSE.txt'] !== $read($argv[3].'/FONT-LICENSE.txt')) {
    throw new RuntimeException('Keep both documentation font notices identical.');
}
$files['LIGHT-DOCS-MANIFEST.json'] = $guide('DOCS-MANIFEST.json');
$files['LIGHT-DOCUMENTATION-RELEASE.json'] = $guide('DOCUMENTATION-RELEASE.json');
$files['LIGHT-DOCUMENTATION-SHA256SUMS'] = $revisionSums;
$files['LIGHT-DOCUMENTATION-SHA256SUMS.sig'] = $read($argv[2].'/SHA256SUMS.sig');
$files['LIGHT-RUNTIME-BUILD-IDENTITY.json'] = $public('BUILD-IDENTITY.json');
$files['RIPE-DOCS-MANIFEST.json'] = $read($argv[3].'/DOCS-MANIFEST.json');
$files['README.txt'] = "Emailer 1.1.0 complete documentation set\nPrivate: contains Ripe material. Do not publish this archive in the public Light repository.\nStart with emailer-light-install.html or emailer-ripe-install.html.\nFor existing Light, open emailer-light-to-ripe-upgrade.html.\nAll seven guides also have selectable-text PDF versions. Keep this folder together for offline HTML links.\nLight documentation revision1 uses recipient.first_name; application1.1.0 is unchanged.\n";
ksort($files);
$zip = new ZipArchive();
if (true !== $zip->open($argv[4], ZipArchive::CREATE | ZipArchive::EXCL)) {
    throw new RuntimeException('The complete documentation archive could not be created.');
}
foreach ($files as $name => $bytes) {
    if (!$zip->addFromString($name, $bytes) || !$zip->setMtimeName($name, (int) $argv[5])) {
        throw new RuntimeException('The complete documentation archive could not be populated.');
    }
}
if (!$zip->close()) {
    throw new RuntimeException('The complete documentation archive could not be finalized.');
}
echo "Seven-guide private documentation archive authenticated and created.\n";
