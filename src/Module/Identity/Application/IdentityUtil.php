<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final class IdentityUtil
{
    public static function normalizeLogin(string $login): string
    {
        $normalized = \Normalizer::normalize(trim($login), \Normalizer::FORM_C);
        if (!\is_string($normalized)) {
            throw new \InvalidArgumentException('Login identifier is invalid.');
        }
        $normalized = mb_strtolower($normalized, 'UTF-8');
        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL) || 254 < \strlen($normalized)) {
            throw new \InvalidArgumentException('Login identifier must be a valid email address.');
        }

        return $normalized;
    }

    public static function uuidV7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);
        for ($index = 5; 0 <= $index; --$index) {
            $bytes[$index] = \chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
