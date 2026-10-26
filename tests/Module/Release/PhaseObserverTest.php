<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PhaseObserverTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, string}> */
    public static function cases(): iterable
    {
        yield 'delayed DDL after the mutating journal' => ['delayed', 'mutating', true, 'Committed partial target schema independently observed.'];
        yield 'no DDL before the same deadline' => ['never', 'mutating', false, 'Expected isolated fault phase was not reached.'];
        yield 'conversion already passed its fault boundary' => ['cipher', 'mutating', false, 'The fault did not interrupt actual partial schema conversion.'];
        yield 'database failures are never evidence of missing columns' => ['sql-error', 'mutating', false, 'Synthetic metadata probe failed.'];
        yield 'wait for actual partial restore' => ['restore', 'recovering', true, 'Partial source schema restoration independently observed.'];
        yield 'production-like database names remain refused' => ['wrong-database', 'mutating', false, 'Phase observer refuses non-harness database access.'];
    }

    #[DataProvider('cases')]
    public function testActualObserverWaitsForTheSameRealSchemaEvidence(string $scenario, string $phase, bool $success, string $message): void
    {
        $directory = sys_get_temp_dir().'/phase-observer-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        mkdir($directory.'/private', 0o700);
        file_put_contents($directory.'/private/edition-delivery-hold.json', json_encode(['phase' => $phase], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/updater.env', 'Synthetic observer fixture only');
        file_put_contents($directory.'/prepend.php', <<<'PHP'
<?php
namespace Emailer\RehearsalObserver {
    // Deterministic monotonic time; no sleep, network, real credentials or DB.
    function hrtime(bool $number = false): int { static $time = 0; return $time += 1000000000; }
    function usleep(int $microseconds): void {}
}
namespace Symfony\Component\Dotenv {
    class Dotenv { public function parse(string $text): array { return ['DATABASE_URL' => 'synthetic-observer-only']; } }
}
namespace Doctrine\DBAL\Tools {
    class DsnParser {
        public function __construct(array $schemes) {}
        public function parse(string $url): array { return ['host' => 'database', 'dbname' => getenv('OBSERVER_SCENARIO') === 'wrong-database' ? 'ordinary_production_name' : 'ripe106_observer_fixture']; }
    }
}
namespace Doctrine\DBAL {
    class DriverManager {
        public static function getConnection(array $parameters): object {
            file_put_contents(getenv('EMAILER_REHEARSAL_ROOT').'/factory.json', json_encode(['timeout' => $parameters['driverOptions'][\PDO::ATTR_TIMEOUT] ?? null]));
            return new ObserverConnection();
        }
    }
    class ObserverConnection {
        private int $queries = 0;
        public function executeStatement(string $sql): int {
            if ($sql !== 'SET SESSION max_statement_time = 1') { throw new \RuntimeException('Unbounded observer query fixture.'); }
            return 0;
        }
        public function fetchOne(string $sql): string|false {
            $scenario = getenv('OBSERVER_SCENARIO');
            ++$this->queries;
            file_put_contents(getenv('EMAILER_REHEARSAL_ROOT').'/queries.json', json_encode(['queries' => $this->queries]));
            if ($scenario === 'sql-error') { throw new \RuntimeException('Synthetic metadata probe failed.'); }
            if (str_contains($sql, "TABLE_NAME = 'encrypted_secret'")) { return $scenario === 'cipher' ? 'cipher_edition' : false; }
            if (str_contains($sql, 'COLUMN_NAME')) { return $scenario === 'never' || $this->queries < 7 ? false : 'role'; }
            return $this->queries < 4 ? 'app_user' : false;
        }
    }
}
PHP);
        $observer = \dirname(__DIR__, 3).'/tools/upgrade/observe-rehearsal-phase.php';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-d', 'auto_prepend_file='.$directory.'/prepend.php', $observer, $phase], [['file', '/dev/null', 'r'], ['file', $directory.'/stdout', 'w'], ['file', $directory.'/stderr', 'w']], $pipes, null, array_replace(getenv(), ['EMAILER_ISOLATED_REHEARSAL' => '1', 'EMAILER_REHEARSAL_ROOT' => $directory, 'OBSERVER_SCENARIO' => $scenario]));
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process, 9);
                self::fail('The deterministic observer did not respect its bounded clock.');
            }
            proc_close($process);
            $output = (string) file_get_contents($directory.'/stdout').file_get_contents($directory.'/stderr');
            self::assertSame($success, 0 === $status['exitcode']);
            self::assertStringContainsString($message, $output);
            self::assertSame($success, str_contains($output, 'Expected isolated operation phase reached.'));
            if ('wrong-database' === $scenario) {
                self::assertFileDoesNotExist($directory.'/factory.json');
            } else {
                self::assertSame(['timeout' => 2], json_decode((string) file_get_contents($directory.'/factory.json'), true, flags: JSON_THROW_ON_ERROR));
            }
            if ('delayed' === $scenario || 'restore' === $scenario) {
                $evidence = json_decode((string) file_get_contents($directory.'/queries.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($evidence);
                self::assertGreaterThan(2, $evidence['queries']);
            }
        } finally {
            if (\is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            (new Filesystem())->remove($directory);
        }
    }
}
