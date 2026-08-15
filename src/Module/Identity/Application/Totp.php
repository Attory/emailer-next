<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSeed(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function verify(string $seed, string $code, ?int $timestamp = null): bool
    {
        if (1 !== preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->code($seed, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function code(string $seed, int $counter): string
    {
        $key = $this->base32Decode($seed);
        $binaryCounter = pack('N2', intdiv($counter, 0x1_0000_0000), $counter & 0xFFFF_FFFF);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        if ('' === $encoded || 1 !== preg_match('/^[A-Z2-7]+$/', $encoded)) {
            throw new \InvalidArgumentException('TOTP seed is malformed.');
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if (false === $position) {
                throw new \InvalidArgumentException('TOTP seed is malformed.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $decoded .= \chr((int) bindec($chunk));
            }
        }

        return $decoded;
    }
}
