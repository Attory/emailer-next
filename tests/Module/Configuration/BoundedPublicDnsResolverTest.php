<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Tests\Module\Configuration\WebsiteDnsProcessFixture;

use function proc_open as nativeProcOpen;

/**
 * @param list<string>|string               $command
 * @param array<int, array{string, string}> $descriptorSpec
 * @param array<int, resource>|null         $pipes
 * @param array<string, string>|null        $environment
 * @param array<string, bool>|null          $options
 *
 * @param-out array<int, resource> $pipes
 *
 * @return resource|false
 */
function proc_open(array|string $command, array $descriptorSpec, ?array &$pipes, ?string $cwd = null, ?array $environment = null, ?array $options = null)
{
    if (!WebsiteDnsProcessFixture::$active) {
        return nativeProcOpen($command, $descriptorSpec, $pipes, $cwd, $environment, $options);
    }
    WebsiteDnsProcessFixture::$commands[] = $command;
    WebsiteDnsProcessFixture::$environments[] = $environment;
    if (!\is_array($command)) {
        throw new \LogicException('DNS helper must not invoke a shell.');
    }
    // Run a real, killable local process, replacing only its network-dependent body.
    $process = nativeProcOpen([$command[0], '-n', '-r', WebsiteDnsProcessFixture::$script], $descriptorSpec, $pipes, $cwd, $environment, $options);
    if (\is_resource($process)) {
        WebsiteDnsProcessFixture::$pid = proc_get_status($process)['pid'];
    }

    return $process;
}

namespace App\Tests\Module\Configuration;

use App\Module\Configuration\Infrastructure\BoundedPublicDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteDnsProcessFixture
{
    public static bool $active = false;
    public static string $script = '';
    public static ?int $pid = null;
    /** @var list<list<string>|string> */
    public static array $commands = [];
    /** @var list<array<string, string>|null> */
    public static array $environments = [];

    public static function reset(): void
    {
        self::$active = false;
        self::$script = '';
        self::$pid = null;
        self::$commands = self::$environments = [];
    }
}

final class BoundedPublicDnsResolverTest extends TestCase
{
    protected function setUp(): void
    {
        WebsiteDnsProcessFixture::reset();
        WebsiteDnsProcessFixture::$active = true;
    }

    protected function tearDown(): void
    {
        WebsiteDnsProcessFixture::reset();
    }

    public function testDnsHelperUsesArgumentVectorAndRestrictedEnvironment(): void
    {
        WebsiteDnsProcessFixture::$script = 'echo json_encode(["93.184.215.14","2606:4700:4700::1111"]);';
        $host = 'example.com;echo should-never-run';
        self::assertSame(['93.184.215.14', '2606:4700:4700::1111'], (new BoundedPublicDnsResolver())->resolve($host, microtime(true) + 1));
        self::assertCount(1, WebsiteDnsProcessFixture::$commands);
        $command = WebsiteDnsProcessFixture::$commands[0];
        self::assertIsArray($command);
        self::assertSame(PHP_BINARY, $command[0]);
        self::assertSame(['-n', '-r'], \array_slice($command, 1, 2));
        self::assertSame($host, $command[4]);
        self::assertSame([['PATH' => '/usr/bin:/bin']], WebsiteDnsProcessFixture::$environments);
        $this->assertChildExited();
    }

    public function testExpiredDeadlineNeverStartsAChild(): void
    {
        try {
            (new BoundedPublicDnsResolver())->resolve('example.com', microtime(true) - 1);
            self::fail('Expired request must not launch a DNS helper.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('total time limit', $exception->getMessage());
            self::assertSame([], WebsiteDnsProcessFixture::$commands);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedResults(): iterable
    {
        yield 'not JSON' => ['echo "not JSON";'];
        yield 'object instead of list' => ['echo "{\"ip\":\"93.184.215.14\"}";'];
        yield 'invalid element' => ['echo "[42]";'];
        yield 'too many records' => ['echo json_encode(array_fill(0,33,"93.184.215.14"));'];
    }

    #[DataProvider('malformedResults')]
    public function testMalformedHelperOutputCannotBecomeAddresses(string $script): void
    {
        WebsiteDnsProcessFixture::$script = $script;
        try {
            (new BoundedPublicDnsResolver())->resolve('example.com', microtime(true) + 1);
            self::fail('Invalid helper result must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('DNS result', $exception->getMessage());
            $this->assertChildExited();
        }
    }

    public function testSlowDnsHelperIsKilledAndReapedAtTheSharedDeadline(): void
    {
        WebsiteDnsProcessFixture::$script = 'sleep(10);echo "[]";';
        $started = microtime(true);
        try {
            (new BoundedPublicDnsResolver())->resolve('example.com', $started + 0.05);
            self::fail('Slow DNS must not occupy an application worker indefinitely.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('time or size limit', $exception->getMessage());
            self::assertLessThan(0.75, microtime(true) - $started);
            $this->assertChildExited();
        }
    }

    public function testOversizedHelperOutputIsRejectedAndChildReaped(): void
    {
        WebsiteDnsProcessFixture::$script = 'echo str_repeat("x",16384);flush();sleep(10);';
        try {
            (new BoundedPublicDnsResolver())->resolve('example.com', microtime(true) + 1);
            self::fail('Oversized helper output must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('time or size limit', $exception->getMessage());
            $this->assertChildExited();
        }
    }

    public function testHelperStderrDoesNotBecomeAddresses(): void
    {
        WebsiteDnsProcessFixture::$script = 'fwrite(STDERR,"synthetic resolver warning");echo "[\"93.184.215.14\"]";';
        self::assertSame(['93.184.215.14'], (new BoundedPublicDnsResolver())->resolve('example.com', microtime(true) + 1));
        $this->assertChildExited();
    }

    private function assertChildExited(): void
    {
        self::assertNotNull(WebsiteDnsProcessFixture::$pid);
        self::assertDirectoryDoesNotExist('/proc/'.WebsiteDnsProcessFixture::$pid);
    }
}
