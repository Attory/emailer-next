<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Authenticate only the explicitly supported Light releases under Light's original identity. */
final class LightSourcePackage
{
    public const SUPPORTED_VERSIONS = ['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4', '1.2.5', '1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.2.10'];

    private const ORIGINAL_HASH = 'cfd9afb133c3bbfe7da6053aa2d121d325200c46f4db51e784699356d28d0e77';
    private const LIGHT_SIGNER = '2zO47r_uQtv-lcMryvh_wWijpyCF1YAfBXBGi9W8B1E';

    public static function authenticate(string $archive): string
    {
        $hash = hash_file('sha256', $archive);
        if (\is_string($hash) && hash_equals(self::ORIGINAL_HASH, $hash)) {
            return '1.0.0';
        }
        if (1 !== preg_match('/^emailer-light-(1\.(?:1\.0|2\.(?:[0123456789]|10)))\.zip$/D', basename($archive), $match)) {
            throw new \RuntimeException('Only original published Light 1.0.0 and signed Light 1.1.0/1.2.0/1.2.1/1.2.2/1.2.3/1.2.4/1.2.5/1.2.6/1.2.7/1.2.8/1.2.9/1.2.10 are supported.');
        }
        $manifestBytes = self::read($archive.'.manifest.json', 1048576);
        $checksum = self::read($archive.'.sha256', 256);
        $sbom = self::read($archive.'.spdx.json', 8388608);
        $signature = sodium_base642bin(trim(self::read($archive.'.sig', 256)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $key = sodium_base642bin(self::LIGHT_SIGNER, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        if (!\is_string($hash) || !hash_equals($hash.'  '.basename($archive)."\n", $checksum)
            || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key) || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)
            || !sodium_crypto_sign_verify_detached($signature, $manifestBytes.$checksum.hash('sha256', $sbom), $key)) {
            throw new \RuntimeException('Light source package authentication failed.');
        }
        $manifest = json_decode($manifestBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($manifest) || 'emailer-light-release-v1' !== ($manifest['format'] ?? null) || $match[1] !== ($manifest['version'] ?? null)
            || self::schemaFor($match[1]) !== ($manifest['schema_version'] ?? null) || !\is_string($manifest['source_commit'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{40}$/', $manifest['source_commit'])) {
            throw new \RuntimeException('Light source release identity is unsupported.');
        }

        return $match[1];
    }

    public static function schemaFor(string $version): string
    {
        if (!\in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new \RuntimeException('This Light release has no supported source schema profile.');
        }

        return \in_array($version, ['1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.2.10'], true) ? '20261025000000' : '20261014000000';
    }

    /** @return list<string> */
    public static function migrationsFor(string $version): array
    {
        return '20261025000000' === self::schemaFor($version)
            ? ['DoctrineMigrations\\Version20261014000000', 'DoctrineMigrations\\Version20261025000000']
            : ['DoctrineMigrations\\Version20261014000000'];
    }

    public static function proofFor(string $version): string
    {
        self::schemaFor($version);

        return '20261025000000' === self::schemaFor($version) ? '1.2.6' : '1.0.0';
    }

    private static function read(string $path, int $limit): string
    {
        $metadata = @lstat($path);
        if (!\is_array($metadata) || 0o100000 !== ($metadata['mode'] & 0o170000) || 1 !== $metadata['nlink'] || $metadata['size'] > $limit) {
            throw new \RuntimeException('Light source evidence is missing or unsafe.');
        }
        $bytes = file_get_contents($path);
        if (false === $bytes) {
            throw new \RuntimeException('Light source evidence could not be read.');
        }

        return $bytes;
    }
}
