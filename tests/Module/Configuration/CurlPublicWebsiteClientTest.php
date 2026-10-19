<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Tests\Module\Configuration\WebsiteTransportFixture;

use function curl_exec as nativeCurlExec;
use function curl_getinfo as nativeCurlGetinfo;
use function curl_init as nativeCurlInit;
use function curl_setopt_array as nativeCurlSetoptArray;

/** @param array<int, mixed> $options */
function curl_setopt_array(\CurlHandle $handle, array $options): bool
{
    if (!WebsiteTransportFixture::$active) {
        return nativeCurlSetoptArray($handle, $options);
    }
    WebsiteTransportFixture::$options[spl_object_id($handle)] = $options;

    return true;
}

/** @return ($url is string ? \CurlHandle : \CurlHandle|false) */
function curl_init(?string $url = null): \CurlHandle|false
{
    if (null === $url) {
        return nativeCurlInit();
    }
    $handle = nativeCurlInit($url);
    if (WebsiteTransportFixture::$active) {
        WebsiteTransportFixture::$urls[spl_object_id($handle)] = $url;
    }

    return $handle;
}

function curl_exec(\CurlHandle $handle): string|bool
{
    if (!WebsiteTransportFixture::$active) {
        return nativeCurlExec($handle);
    }

    return WebsiteTransportFixture::execute($handle);
}

function curl_getinfo(\CurlHandle $handle, ?int $option = null): mixed
{
    if (!WebsiteTransportFixture::$active) {
        return nativeCurlGetinfo($handle, $option);
    }
    $response = WebsiteTransportFixture::$current[spl_object_id($handle)];

    return match ($option) {
        CURLINFO_RESPONSE_CODE => $response['status'],
        CURLINFO_CONTENT_TYPE => $response['type'],
        default => throw new \LogicException('Unexpected transport inspection.'),
    };
}

namespace App\Tests\Module\Configuration;

use App\Module\Configuration\Application\Contract\PublicDnsResolver;
use App\Module\Configuration\Infrastructure\CurlPublicWebsiteClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Native handles exercise the production callback signatures, with no sockets opened.
 * Outside these tests every namespaced wrapper delegates to the native extension.
 *
 * @phpstan-type Response array{status: int, type: ?string, headers: list<string>, chunks: list<string>, delay: int, failed: bool}
 * @phpstan-type Request array{url: string, options: array<int, mixed>}
 */
final class WebsiteTransportFixture
{
    public static bool $active = false;
    /** @var array<int, string> */
    public static array $urls = [];
    /** @var array<int, array<int, mixed>> */
    public static array $options = [];
    /** @var list<Response> */
    public static array $responses = [];
    /** @var array<int, Response> */
    public static array $current = [];
    /** @var list<Request> */
    public static array $requests = [];
    public static bool $writeAborted = false;

    /** @param list<string> $headers
     * @param list<string> $chunks
     */
    public static function respond(int $status = 200, ?string $type = 'text/html', array $headers = [], array $chunks = ['synthetic public page'], int $delay = 0, bool $failed = false): void
    {
        self::$responses[] = ['status' => $status, 'type' => $type, 'headers' => $headers, 'chunks' => $chunks, 'delay' => $delay, 'failed' => $failed];
    }

    public static function execute(\CurlHandle $handle): bool
    {
        $id = spl_object_id($handle);
        $options = self::$options[$id];
        self::$requests[] = ['url' => self::$urls[$id], 'options' => $options];
        $response = array_shift(self::$responses);
        if (null === $response) {
            throw new \LogicException('Unexpected connection attempt.');
        }
        self::$current[$id] = $response;
        if (0 < $response['delay']) {
            usleep($response['delay']);
        }
        $header = $options[CURLOPT_HEADERFUNCTION];
        $write = $options[CURLOPT_WRITEFUNCTION];
        if (!\is_callable($header) || !\is_callable($write)) {
            throw new \LogicException('Transport callbacks are required.');
        }
        foreach ($response['headers'] as $line) {
            TestCase::assertSame(\strlen($line), $header($handle, $line));
        }
        foreach ($response['chunks'] as $chunk) {
            if (\strlen($chunk) !== $write($handle, $chunk)) {
                self::$writeAborted = true;

                return false;
            }
        }

        return !$response['failed'];
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$urls = self::$options = self::$responses = self::$current = self::$requests = [];
        self::$writeAborted = false;
    }
}

final class CurlPublicWebsiteClientTest extends TestCase
{
    protected function setUp(): void
    {
        WebsiteTransportFixture::reset();
        WebsiteTransportFixture::$active = true;
    }

    protected function tearDown(): void
    {
        WebsiteTransportFixture::reset();
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeDns(): iterable
    {
        yield 'private only' => [['127.0.0.1']];
        yield 'public first, private second' => [['93.184.215.14', '169.254.169.254']];
        yield 'private first, public second' => [['10.0.0.1', '93.184.215.14']];
        yield 'IPv6 private mixed with public' => [['2606:4700:4700::1111', '::1']];
        yield 'no records' => [[]];
    }

    /** @param list<string> $addresses */
    #[DataProvider('unsafeDns')]
    public function testUnsafeDnsNeverOpensAConnection(array $addresses): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('example.com', self::isFloat())->willReturn($addresses);
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/');
            self::fail('Unsafe DNS must stop installation-level website fetching.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], WebsiteTransportFixture::$requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenRedirects(): iterable
    {
        yield 'private literal' => ['https://127.0.0.1/'];
        yield 'alternate port' => ['https://other.com:8081/'];
        yield 'unsecured HTTP' => ['http://other.com/'];
    }

    #[DataProvider('forbiddenRedirects')]
    public function testUnsafeRedirectNeverOpensItsDestination(string $location): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['93.184.215.14']);
        WebsiteTransportFixture::respond(302, headers: ['Location: '.$location."\r\n"]);
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/');
            self::fail('Unsafe redirect must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame(['https://example.com/'], array_column(WebsiteTransportFixture::$requests, 'url'));
        }
    }

    public function testRedirectDnsIsRecheckedAndPrivateRebindingNeverConnects(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturnOnConsecutiveCalls(['93.184.215.14'], ['127.0.0.1']);
        WebsiteTransportFixture::respond(302, headers: ["Location: /next\r\n"]);
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/');
            self::fail('DNS rebinding must fail before its second connection.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, WebsiteTransportFixture::$requests);
        }
    }

    public function testSecondaryAssetOriginIsCheckedBeforeInitialDns(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::never())->method('resolve');
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://other.com/logo.png', 'https://example.com/');
            self::fail('Cross-origin asset must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], WebsiteTransportFixture::$requests);
        }
    }

    public function testSecondaryAssetRedirectOriginIsCheckedBeforeNewDns(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('example.com', self::anything())->willReturn(['93.184.215.14']);
        WebsiteTransportFixture::respond(302, headers: ["Location: https://other.com/logo.png\r\n"]);
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/logo.png', 'https://example.com/');
            self::fail('Cross-origin redirect must fail before DNS or connection.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, WebsiteTransportFixture::$requests);
        }
    }

    public function testEachHopPinsItsValidatedAddressAndCannotUseAmbientProxiesOrDisableTls(): void
    {
        $deadline = microtime(true) + 0.5;
        $resolver = $this->createMock(PublicDnsResolver::class);
        $hosts = [];
        $resolver->expects(self::exactly(2))->method('resolve')->willReturnCallback(static function (string $host, float $limit) use (&$hosts, $deadline): array {
            self::assertSame($deadline, $limit);
            $hosts[] = $host;

            return 'example.com' === $host ? ['93.184.215.14'] : ['2606:4700:4700::1111'];
        });
        WebsiteTransportFixture::respond(302, headers: ["Location: https://other.com/brand\r\n"], delay: 20000);
        WebsiteTransportFixture::respond(type: 'Text/CSS; charset=utf-8', chunks: ['body{}']);
        $result = (new CurlPublicWebsiteClient($resolver))->get('https://example.com/', deadline: $deadline);
        self::assertSame(['example.com', 'other.com'], $hosts);
        self::assertSame(['url' => 'https://other.com/brand', 'body' => 'body{}', 'type' => 'text/css'], $result);
        [$first, $second] = array_column(WebsiteTransportFixture::$requests, 'options');
        self::assertSame(['example.com:443:93.184.215.14'], $first[CURLOPT_RESOLVE]);
        self::assertSame(['other.com:443:[2606:4700:4700::1111]'], $second[CURLOPT_RESOLVE]);
        foreach ([$first, $second] as $options) {
            self::assertSame('', $options[CURLOPT_PROXY]);
            self::assertSame('*', $options[CURLOPT_NOPROXY]);
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
            self::assertIsInt($options[CURLOPT_TIMEOUT_MS]);
            self::assertGreaterThan(0, $options[CURLOPT_TIMEOUT_MS]);
            self::assertLessThanOrEqual(500, $options[CURLOPT_TIMEOUT_MS]);
            self::assertLessThanOrEqual($options[CURLOPT_TIMEOUT_MS], $options[CURLOPT_CONNECTTIMEOUT_MS]);
        }
        self::assertLessThan($first[CURLOPT_TIMEOUT_MS], $second[CURLOPT_TIMEOUT_MS]);
    }

    public function testExpiredTotalDeadlineStopsBeforeDnsOrConnection(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::never())->method('resolve');
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/', deadline: microtime(true) - 1.0);
            self::fail('Expired deadline must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('total time limit', $exception->getMessage());
            self::assertSame([], WebsiteTransportFixture::$requests);
        }
    }

    public function testDnsCannotConsumeTheBudgetAndStillOpenATransport(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturnCallback(static function (): array {
            usleep(20000);

            return ['93.184.215.14'];
        });
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/', deadline: microtime(true) + 0.01);
            self::fail('DNS exhausted the total budget.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('total time limit', $exception->getMessage());
            self::assertSame([], WebsiteTransportFixture::$requests);
        }
    }

    public function testRedirectCannotResetAnExhaustedTotalDeadline(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['93.184.215.14']);
        WebsiteTransportFixture::respond(302, headers: ["Location: /next\r\n"], delay: 20000);
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/', deadline: microtime(true) + 0.01);
            self::fail('A redirect cannot grant a new time budget.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('total time limit', $exception->getMessage());
            self::assertCount(1, WebsiteTransportFixture::$requests);
        }
    }

    public function testBodyAtLimitIsAcceptedAndNextChunkAbortsWithoutTruncation(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturn(['93.184.215.14']);
        WebsiteTransportFixture::respond(chunks: [str_repeat('a', 131072), str_repeat('b', 131072)]);
        $client = new CurlPublicWebsiteClient($resolver);
        self::assertSame(262144, \strlen($client->get('https://example.com/')['body']));
        WebsiteTransportFixture::respond(chunks: [str_repeat('a', 262144), 'x']);
        try {
            $client->get('https://example.com/');
            self::fail('Oversized body must not be accepted as truncated branding.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('size or time limit', $exception->getMessage());
            self::assertTrue(WebsiteTransportFixture::$writeAborted);
        }
    }

    /** @return iterable<string, array{int, ?string, bool}> */
    public static function unreadableResponses(): iterable
    {
        yield 'not found' => [404, 'text/html', false];
        yield 'server failure' => [503, 'text/html', false];
        yield 'unsupported SVG' => [200, 'image/svg+xml', false];
        yield 'missing content type' => [200, null, false];
        yield 'failed TLS or transport' => [200, 'text/html', true];
    }

    #[DataProvider('unreadableResponses')]
    public function testUnreadableOrFailedTransportNeverReturnsBranding(int $status, ?string $type, bool $failed): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(['93.184.215.14']);
        WebsiteTransportFixture::respond($status, $type, failed: $failed);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Your saved appearance is unchanged');
        (new CurlPublicWebsiteClient($resolver))->get('https://example.com/');
    }

    public function testRedirectChainCannotOpenAFourthConnection(): void
    {
        $resolver = $this->createMock(PublicDnsResolver::class);
        $resolver->expects(self::exactly(3))->method('resolve')->willReturn(['93.184.215.14']);
        foreach (['/one', '/two', '/three'] as $path) {
            WebsiteTransportFixture::respond(302, headers: ['Location: '.$path."\r\n"]);
        }
        try {
            (new CurlPublicWebsiteClient($resolver))->get('https://example.com/');
            self::fail('Redirect chain must be bounded.');
        } catch (\RuntimeException) {
            self::assertCount(3, WebsiteTransportFixture::$requests);
            self::assertSame([], WebsiteTransportFixture::$responses);
        }
    }
}
