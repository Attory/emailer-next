<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Configuration\Application\Contract\PublicWebsiteClient;
use App\Module\Configuration\Application\WebsiteBranding;
use App\Module\Configuration\Application\WebsiteUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteBrandingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach (['http://example.com', 'https://127.0.0.1', 'https://[::1]/', 'https://2130706433/', 'https://0177.0.0.1', 'https://example.com:8081/', 'https://user:pass@example.com', 'https://example.local', 'https://example.com./', 'https://example.com\\@127.0.0.1/', 'file:///etc/passwd', 'https://localhost', 'https://example.com/#fragment', 'https://example.com/?token=secret'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlsNeverBecomeFetchTargets(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebsiteUrlPolicy::normalize($url);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeAddresses(): iterable
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.168.1.1', '192.0.2.1', '0.0.0.0', '::1', '::', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '2001:db8::1', '224.0.0.1', 'ff02::1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', '2001:0:1:1::1'] as $address) {
            yield $address => [['93.184.215.14', $address]];
        }
        yield 'no records' => [[]];
    }

    /** @param list<string> $addresses */
    #[DataProvider('unsafeAddresses')]
    public function testEveryResolvedAddressMustBePublic(array $addresses): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebsiteUrlPolicy::publicAddresses($addresses);
    }

    public function testPublicAddressAndSameHostRelativeSheetAreSupported(): void
    {
        WebsiteUrlPolicy::publicAddresses(['93.184.215.14', '2606:4700:4700::1111']);
        self::assertSame('https://example.com/styles/main.css', WebsiteUrlPolicy::resolve('https://EXAMPLE.COM/styles/page', 'main.css'));
    }

    public function testExtractionOnlyStoresBoundedTokensAndLeavesScriptsAndExternalAssetsBehind(): void
    {
        $client = $this->createMock(PublicWebsiteClient::class);
        $responses = [
            ['https://example.com/', ['url' => 'https://example.com/', 'type' => 'text/html', 'body' => '<html><meta name="theme-color" content="#a31"><script>throw "never executed"</script><link rel="stylesheet" href="/brand.css"><link rel="stylesheet" href="https://other.example.com/tracker.css"><body style="background:url(https://evil.invalid)"><img src="https://evil.invalid/logo.svg"></body></html>']],
            ['https://example.com/brand.css', ['url' => 'https://example.com/brand.css', 'type' => 'text/css', 'body' => ':root{--accent:#256b63;--surface:#fff;--text:#333;font-family:Georgia,serif}body{background:url(https://evil.invalid)}']],
        ];
        $client->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $url) use ($responses): array {
            foreach ($responses as [$source, $response]) {
                if ($url === $source) {
                    return $response;
                }
            }
            throw new \RuntimeException('Unexpected synthetic URL');
        });
        $result = (new WebsiteBranding($client))->extract('https://example.com/');
        self::assertSame('#aa3311', $result['tokens']['primary']);
        self::assertSame('#256b63', $result['tokens']['accent']);
        self::assertSame('serif', $result['tokens']['font']);
        self::assertSame(['website_url', 'refreshed_at', 'tokens', 'logo_url', 'logo_data'], array_keys($result));
        self::assertSame(6, \count($result['tokens']));
        self::assertStringNotContainsString('script', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('evil', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnreadableSheetAndConflictingSurfaceContrastHaveHarmoniousFallbacks(): void
    {
        $client = $this->createMock(PublicWebsiteClient::class);
        $client->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $url): array {
            if (str_ends_with($url, '.css')) {
                throw new \RuntimeException('Synthetic stylesheet outage');
            }

            return ['url' => $url, 'type' => 'text/html', 'body' => '<style>:root{--surface:#000;--background:#fff;--text:#888}</style><link rel="stylesheet" href="/missing.css">'];
        });
        $tokens = (new WebsiteBranding($client))->extract('https://example.com/')['tokens'];
        self::assertGreaterThanOrEqual(4.5, WebsiteBranding::contrast($tokens['text'], $tokens['surface']));
        self::assertGreaterThanOrEqual(4.5, WebsiteBranding::contrast($tokens['text'], $tokens['background']));
    }

    public function testArbitraryCssCannotBeSavedAsTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebsiteBranding::validate(['primary' => 'red; background:url(https://example.com)']);
    }

    public function testLowContrastManualCorrectionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebsiteBranding::validate(['text' => '#ffffff', 'surface' => '#ffffff']);
    }
}
