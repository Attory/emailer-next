<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

/** This deliberately small profile never reaches local hosting or alternate ports. */
final class WebsiteUrlPolicy
{
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (2048 < \strlen($url) || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || false === $parts || 'https' !== ($parts['scheme'] ?? null)
            || !\is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && 443 !== $parts['port']) || isset($parts['fragment'])
            || isset($parts['query'])
        ) {
            throw new \InvalidArgumentException('Enter a public HTTPS website address without a password, query or alternate port.');
        }
        $host = strtolower($parts['host']);
        // Literal IPs, ambiguous numeric names and local/search-domain names are not websites.
        if (253 < \strlen($host) || !str_contains($host, '.') || str_ends_with($host, '.')
            || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || 1 === preg_match('/^[0-9.]+$/', $host)
            || 1 !== preg_match('/\.[a-z][a-z0-9-]*$/', $host)
            || 1 === preg_match('/\.(?:local|localhost|internal|test|invalid|example|onion)$/', $host)
        ) {
            throw new \InvalidArgumentException('Use a public website name, such as https://example.com. Local and numeric addresses are unavailable.');
        }

        return 'https://'.$host.($parts['path'] ?? '/');
    }

    /** @param list<string> $addresses */
    public static function publicAddresses(array $addresses): void
    {
        if ([] === $addresses || 32 < \count($addresses)) {
            throw new \InvalidArgumentException('This website has no usable public address. Check its DNS and try again.');
        }
        foreach ($addresses as $address) {
            $packed = @inet_pton($address);
            $transition = false !== $packed && 16 === \strlen($packed)
                && ((\ord($packed[0]) & 0xE0) !== 0x20 || str_starts_with($packed, "\x20\x02") || str_starts_with($packed, "\x20\x01\x00\x00"));
            if (false === $packed || $transition || (4 === \strlen($packed) && 224 <= \ord($packed[0]))
                || false === filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new \InvalidArgumentException('Website branding cannot contact private, reserved or local network addresses.');
            }
        }
    }

    public static function resolve(string $base, string $reference): string
    {
        if (str_starts_with($reference, 'https://')) {
            return self::normalize($reference);
        }
        $host = parse_url(self::normalize($base), PHP_URL_HOST);
        if (!\is_string($host) || '' === $reference || str_contains($reference, ':') || str_starts_with($reference, '//')) {
            throw new \InvalidArgumentException('This website references an unsupported address.');
        }
        $basePath = parse_url($base, PHP_URL_PATH);
        $path = str_starts_with($reference, '/') ? $reference : rtrim(\dirname(\is_string($basePath) ? $basePath : '/'), '/').'/'.$reference;

        return self::normalize('https://'.$host.$path);
    }
}
