<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Signed source/target metadata is checked before mutation, never silently repaired. */
final class NativeIdentityBinding
{
    /** @param array<array-key, mixed> $lock
     * @param array<array-key, mixed>|null $identity
     */
    public static function source(string $version, string $schema, string $uuid, array $lock, ?array $identity, bool $legacy106, bool $converted = false): void
    {
        if (($lock['installation_uuid'] ?? null) !== $uuid || (isset($lock['product_id']) && 'emailer-ripe' !== $lock['product_id'])) {
            throw new \RuntimeException('The source installation lock and installation identity disagree. No update was started.');
        }
        $historical = '1.0.6' === $version;
        $recorded = $lock['product_version'] ?? null;
        $recordedSchema = $lock['schema_version'] ?? null;
        $legacyVersion = $historical && $legacy106 && '1.0.5' === $recorded;
        if ((!$historical && 'emailer-ripe' !== ($lock['product_id'] ?? null)) || ($recorded !== $version && !($historical && null === $recorded) && !$legacyVersion) || ($recordedSchema !== $schema && !($historical && null === $recordedSchema) && !($legacyVersion && '20261013000000' === $recordedSchema))) {
            throw new \RuntimeException('The source installation lock does not match its authenticated version and schema. No metadata was repaired.');
        }
        if ($converted && null === $identity) {
            throw new \RuntimeException('A converted source is missing its installation identity. No update was started.');
        }
        if (null !== $identity) {
            self::row($identity, $version, $schema, $uuid);
        }
    }

    /** @param array<array-key, mixed> $identity */
    public static function row(array $identity, string $version, string $schema, string $uuid): void
    {
        if (($identity['singleton_key'] ?? null) !== '1' || ($identity['product_id'] ?? null) !== 'emailer-ripe' || ($identity['product_version'] ?? null) !== $version || ($identity['schema_version'] ?? null) !== $schema || ($identity['installation_uuid'] ?? null) !== $uuid) {
            throw new \RuntimeException('The converted installation identity does not match its authenticated edition, version, schema and installation. No metadata was repaired.');
        }
    }
}
