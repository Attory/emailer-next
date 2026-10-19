<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\PublicDnsResolver;
use App\Module\Configuration\Application\Contract\PublicWebsiteClient;
use App\Module\Configuration\Application\WebsiteUrlPolicy;

/** No proxies, ambient credentials, automatic redirects or second DNS lookup at connection time. */
final class CurlPublicWebsiteClient implements PublicWebsiteClient
{
    public function __construct(private readonly PublicDnsResolver $resolver)
    {
    }

    public function get(string $url, ?string $expectedOrigin = null, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + 6.0;
        for ($redirect = 0; $redirect <= 2; ++$redirect) {
            $url = WebsiteUrlPolicy::normalize($url);
            $host = parse_url($url, PHP_URL_HOST);
            if (!\is_string($host)) {
                throw new \InvalidArgumentException('Website address is invalid.');
            }
            if (null !== $expectedOrigin && parse_url(WebsiteUrlPolicy::normalize($expectedOrigin), PHP_URL_HOST) !== $host) {
                throw new \InvalidArgumentException('Secondary website assets must stay on the confirmed website.');
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Website branding exceeded its total time limit. Your saved appearance is unchanged.');
            }
            $addresses = $this->resolver->resolve($host, $deadline);
            WebsiteUrlPolicy::publicAddresses($addresses);
            $remaining = (int) floor(($deadline - microtime(true)) * 1000);
            if (1 > $remaining) {
                throw new \RuntimeException('Website branding exceeded its total time limit. Your saved appearance is unchanged.');
            }
            $address = $addresses[0];
            $handle = curl_init($url);
            $body = '';
            $location = '';
            curl_setopt_array($handle, [
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => min(6000, $remaining), CURLOPT_NOSIGNAL => true,
                CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
                CURLOPT_USERAGENT => 'Emailer-Ripe-Branding/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html,text/css,image/png,image/jpeg,image/webp;q=0.9', 'Accept-Encoding: identity'],
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$location): int {
                    if (str_starts_with(strtolower($line), 'location:')) {
                        $location = trim(substr($line, 9));
                    }

                    return \strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body): int {
                    if (262144 < \strlen($body) + \strlen($chunk)) {
                        return 0;
                    }
                    $body .= $chunk;

                    return \strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $type = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            unset($handle);
            if (false === $ok) {
                throw new \RuntimeException('The website could not be read securely within the size or time limit. Your saved appearance is unchanged.');
            }
            if (\in_array($status, [301, 302, 303, 307, 308], true) && '' !== $location && 2 > $redirect) {
                $url = WebsiteUrlPolicy::resolve($url, $location);
                continue;
            }
            if (200 !== $status || !\is_string($type) || !\in_array(strtolower(trim(explode(';', $type)[0])), ['text/html', 'text/css', 'image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new \RuntimeException('The website did not return a readable page, stylesheet or supported image. Your saved appearance is unchanged.');
            }

            return ['url' => $url, 'body' => $body, 'type' => strtolower(trim(explode(';', $type)[0]))];
        }
        throw new \RuntimeException('This website redirects too many times. Enter its final public HTTPS address.');
    }
}
