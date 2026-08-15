<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Setup\Application\ChallengeCode;
use App\Module\Setup\Infrastructure\FilesystemChallengeStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class ConcurrentSetupIssuanceTest extends KernelTestCase
{
    public function testConcurrentHttpIssuancePublishesExactlyOneActiveChallenge(): void
    {
        self::bootKernel();
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        $stateFile = self::getContainer()->getParameter('emailer.setup_state_file');
        self::assertIsString($projectDirectory);
        self::assertIsString($stateFile);
        self::ensureKernelShutdown();

        if (file_exists($stateFile)) {
            self::assertTrue(unlink($stateFile));
        }

        $port = self::availablePort();
        $server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', 'public', 'public/index.php'],
            $projectDirectory,
            [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '0',
                'APP_SECRET' => 'phase2-concurrent-test-only',
                'DEFAULT_URI' => 'http://127.0.0.1:'.$port,
                'LOCK_DSN' => 'flock',
                'MAILER_DSN' => 'smtp://mailpit:1025',
                'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
                'PHP_CLI_SERVER_WORKERS' => '2',
            ],
        );
        $server->start();

        $firstBody = sys_get_temp_dir().'/emailer-concurrent-first-'.bin2hex(random_bytes(8));
        $secondBody = sys_get_temp_dir().'/emailer-concurrent-second-'.bin2hex(random_bytes(8));

        try {
            self::waitForServer($port, $server);
            $url = 'http://127.0.0.1:'.$port.'/setup';
            $sourceSuffix = bin2hex(random_bytes(2));
            $first = self::curlProcess($url, $firstBody, self::loopbackAddress($sourceSuffix, 2));
            $second = self::curlProcess($url, $secondBody, self::loopbackAddress($sourceSuffix, 3));
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $statuses = [$first->getOutput(), $second->getOutput()];
            sort($statuses);
            self::assertSame(['200', '409'], $statuses);

            $bodies = [(string) file_get_contents($firstBody), (string) file_get_contents($secondBody)];
            $issuedBodies = array_values(array_filter(
                $bodies,
                static fn (string $body): bool => 1 === preg_match('/[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7}/', $body),
            ));
            self::assertCount(1, $issuedBodies);
            $issuedBody = $issuedBodies[0] ?? null;
            self::assertIsString($issuedBody);
            $matches = [];
            self::assertSame(1, preg_match('/[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7}/', $issuedBody, $matches));
            $matchedCode = $matches[0] ?? null;
            self::assertIsString($matchedCode);
            $normalized = ChallengeCode::normalize($matchedCode);
            self::assertNotNull($normalized);

            $state = (new FilesystemChallengeStore($stateFile))->load();
            self::assertNotNull($state);
            self::assertTrue(hash_equals($state->codeHash, hash('sha256', $normalized)));
        } finally {
            $server->stop(1.0);

            foreach ([$firstBody, $secondBody, $stateFile] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    private static function curlProcess(string $url, string $bodyFile, string $sourceAddress): Process
    {
        return new Process([
            'curl',
            '--silent',
            '--show-error',
            '--output',
            $bodyFile,
            '--interface',
            $sourceAddress,
            '--write-out',
            '%{http_code}',
            $url,
        ]);
    }

    private static function loopbackAddress(string $suffix, int $host): string
    {
        return \sprintf(
            '127.%d.%d.%d',
            hexdec(substr($suffix, 0, 2)),
            hexdec(substr($suffix, 2, 2)),
            $host,
        );
    }

    private static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($socket, $errorMessage ?? 'Unable to allocate a local test port.');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $separator = strrpos($name, ':');
        self::assertNotFalse($separator);

        return (int) substr($name, $separator + 1);
    }

    private static function waitForServer(int $port, Process $server): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if (!$server->isRunning()) {
                self::fail('Concurrent setup test server stopped early: '.$server->getErrorOutput());
            }

            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if (false !== $connection) {
                fclose($connection);

                return;
            }

            usleep(20000);
        }

        self::fail('Concurrent setup test server did not become ready.');
    }
}
