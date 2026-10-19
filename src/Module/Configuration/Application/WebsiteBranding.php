<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

use App\Module\Configuration\Application\Contract\PublicWebsiteClient;

/** Extract values, never executable source. Remote fonts and source files are not loaded by dashboards. */
final readonly class WebsiteBranding
{
    public function __construct(private PublicWebsiteClient $client)
    {
    }

    /** @return array{website_url: string, refreshed_at: string, tokens: array<string, string>, logo_url: null, logo_data: ?string} */
    public function extract(string $url): array
    {
        $deadline = microtime(true) + 12.0;
        $page = $this->client->get(WebsiteUrlPolicy::normalize(trim($url)), null, $deadline);
        if ('text/html' !== $page['type']) {
            throw new \InvalidArgumentException('Enter the website home page, rather than a stylesheet.');
        }
        $document = new \DOMDocument();
        if (!@$document->loadHTML($page['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \InvalidArgumentException('The website page could not be read. Use manual colours instead.');
        }
        $tokens = self::defaults();
        $css = '';
        foreach ($document->getElementsByTagName('style') as $style) {
            $css .= substr($style->textContent, 0, 65536);
            if (131072 <= \strlen($css)) {
                break;
            }
        }
        $stylesheets = 0;
        foreach ($document->getElementsByTagName('link') as $link) {
            if (2 <= $stylesheets || 'stylesheet' !== strtolower($link->getAttribute('rel'))) {
                continue;
            }
            ++$stylesheets;
            try {
                $sheet = WebsiteUrlPolicy::resolve($page['url'], $link->getAttribute('href'));
                if (parse_url($sheet, PHP_URL_HOST) !== parse_url($page['url'], PHP_URL_HOST)) {
                    continue;
                }
                $response = $this->client->get($sheet, $page['url'], $deadline);
                if ('text/css' === $response['type'] && parse_url($response['url'], PHP_URL_HOST) === parse_url($page['url'], PHP_URL_HOST)) {
                    $css .= substr($response['body'], 0, 65536);
                }
            } catch (\InvalidArgumentException|\RuntimeException) {
                // HTML colours remain usable if a secondary stylesheet is unavailable.
            }
        }
        foreach (['primary' => '(?:primary|brand)(?:-color)?', 'accent' => '(?:accent|secondary)(?:-color)?', 'background' => '(?:background|page-bg)(?:-color)?', 'surface' => '(?:surface|card-bg)(?:-color)?', 'text' => '(?:text|foreground)(?:-color)?'] as $key => $pattern) {
            if (1 === preg_match('/--'.$pattern.'\s*:\s*(#[a-f0-9]{6}|#[a-f0-9]{3})(?![a-f0-9])/i', $css, $matches)) {
                $tokens[$key] = self::color($matches[1]);
            }
        }
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if ('theme-color' === strtolower($meta->getAttribute('name')) && 1 === preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', trim($meta->getAttribute('content')))) {
                $tokens['primary'] = self::color(trim($meta->getAttribute('content')));
                break;
            }
        }
        if (1 === preg_match('/font-family\s*:[^;}]{0,200}(?<!sans-)\bserif\b/i', $css)) {
            $tokens['font'] = 'serif';
        }
        $tokens = self::readable($tokens);

        $logo = null;
        foreach ($document->getElementsByTagName('link') as $link) {
            if (!\in_array(strtolower($link->getAttribute('rel')), ['icon', 'shortcut icon', 'apple-touch-icon'], true)) {
                continue;
            }
            try {
                $url = WebsiteUrlPolicy::resolve($page['url'], $link->getAttribute('href'));
                if (parse_url($url, PHP_URL_HOST) === parse_url($page['url'], PHP_URL_HOST)) {
                    $image = $this->client->get($url, $page['url'], $deadline);
                    if (parse_url($image['url'], PHP_URL_HOST) === parse_url($page['url'], PHP_URL_HOST) && \in_array($image['type'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                        $logo = WebsiteLogo::normalize($image['body']);
                    }
                }
            } catch (\InvalidArgumentException|\RuntimeException) {
                // A local text mark remains available when no small safe icon can be extracted.
            }
            break; // At most one icon request, including unsuccessful extraction.
        }

        return ['website_url' => $page['url'], 'refreshed_at' => gmdate('c'), 'tokens' => $tokens, 'logo_url' => null, 'logo_data' => $logo];
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return ['primary' => '#245c52', 'accent' => '#945529', 'surface' => '#ffffff', 'background' => '#f4f6f5', 'text' => '#243632', 'font' => 'system'];
    }

    /** @param array<mixed> $tokens
     * @return array<string, string>
     */
    public static function validate(array $tokens): array
    {
        $clean = [];
        foreach (self::defaults() as $key => $default) {
            $value = $tokens[$key] ?? $default;
            if (!\is_string($value) || ('font' === $key ? !\in_array($value, ['system', 'serif'], true) : 1 !== preg_match('/^#[a-f0-9]{6}$/i', $value))) {
                throw new \InvalidArgumentException('Use six-digit colour values and one of the suggested fonts.');
            }
            $clean[$key] = strtolower($value);
        }
        if (self::readable($clean) !== $clean) {
            throw new \InvalidArgumentException('Choose text that contrasts with both the page and card colours. Dark text on light surfaces, or light text on dark surfaces, works best.');
        }

        return $clean;
    }

    /** @param array<string, string> $tokens
     * @return array<string, string>
     */
    private static function readable(array $tokens): array
    {
        if (4.5 > self::contrast($tokens['text'], $tokens['surface']) || 4.5 > self::contrast($tokens['text'], $tokens['background'])) {
            $black = min(self::contrast('#18201e', $tokens['surface']), self::contrast('#18201e', $tokens['background']));
            $white = min(self::contrast('#ffffff', $tokens['surface']), self::contrast('#ffffff', $tokens['background']));
            if (4.5 <= max($black, $white)) {
                $tokens['text'] = $black > $white ? '#18201e' : '#ffffff';
            } else {
                $tokens['surface'] = '#ffffff';
                $tokens['background'] = '#f4f6f5';
                $tokens['text'] = '#243632';
            }
        }

        return $tokens;
    }

    public static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function (string $part): float {
                $v = hexdec($part) / 255;

                return 0.04045 >= $v ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        $x = $luminance($a);
        $y = $luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function color(string $value): string
    {
        return 4 === \strlen($value) ? '#'.$value[1].$value[1].$value[2].$value[2].$value[3].$value[3] : strtolower($value);
    }
}
