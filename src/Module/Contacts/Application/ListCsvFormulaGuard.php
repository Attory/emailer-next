<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application;

final class ListCsvFormulaGuard
{
    private const PREFIX = "'EMAILER_CSV_SAFE_V1:";

    public static function protect(string $value): string
    {
        return self::needsProtection($value) ? self::PREFIX.$value : $value;
    }

    public static function restore(string $value): string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $original = substr($value, \strlen(self::PREFIX));

        return self::needsProtection($original) ? $original : $value;
    }

    private static function needsProtection(string $value): bool
    {
        return '' !== $value && (str_contains('=+-@', $value[0]) || "\t" === $value[0] || "\r" === $value[0] || "\n" === $value[0]);
    }
}
