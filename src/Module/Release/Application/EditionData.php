<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Strict decoding at the installer trust boundaries, with no private values in errors. */
final class EditionData
{
    public static function text(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \RuntimeException('Edition evidence contains an invalid string.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (!\is_int($value) && (!\is_string($value) || 1 !== preg_match('/^-?[0-9]+$/D', $value))) {
            throw new \RuntimeException('Edition evidence contains an invalid integer.');
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException('Edition evidence contains an invalid object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Edition evidence contains an invalid object key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @return array<string, string> */
    public static function strings(mixed $value): array
    {
        $strings = [];
        foreach (self::object($value) as $key => $item) {
            $strings[$key] = self::text($item);
        }

        return $strings;
    }

    /** @return list<string> */
    public static function list(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('Edition evidence contains an invalid list.');
        }

        return array_map(self::text(...), $value);
    }

    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Edition evidence cannot be read.');
        }

        return self::object(json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function scalar(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_scalar($value)) {
            throw new \RuntimeException('Edition database evidence contains an invalid scalar.');
        }

        return (string) $value;
    }
}
