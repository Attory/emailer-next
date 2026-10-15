<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Logging;

use App\Infrastructure\Logging\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactionProcessorTest extends TestCase
{
    public function testItRedactsSensitiveKeysAddressesCredentialsAndControlCharacters(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: "Result for phase1-recipient@example.test\npassword=do-not-log",
            context: [
                'smtp_password' => 'do-not-log',
                'nested' => [
                    'authorization' => 'Bearer do-not-log',
                    'safe_count' => 3,
                ],
                'exception' => new \LogicException('do-not-log'),
            ],
            extra: [
                'correlation_id' => 'synthetic-correlation-id',
            ],
        );

        $processed = (new RedactionProcessor())($record);
        $encoded = json_encode(
            [
                $processed->message,
                $processed->context,
                $processed->extra,
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString('phase1-recipient@example.test', $encoded);
        self::assertStringNotContainsString('do-not-log', $encoded);
        self::assertStringNotContainsString("\n", $processed->message);
        self::assertSame('[REDACTED]', $processed->message);
        $nestedContext = $processed->context['nested'];
        self::assertIsArray($nestedContext);
        self::assertSame(3, $nestedContext['safe_count']);
        self::assertSame('synthetic-correlation-id', $processed->extra['correlation_id']);
    }

    public function testRecipientWireTokensAreRedactedInsideOriginalUrlContextAndMessages(): void
    {
        $token = 'v1.'.str_repeat('A', 43).'.'.str_repeat('_', 43);
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Info, 'GET /r/'.$token.'/unsubscribe', ['request_uri' => 'https://example.test/r/'.$token.'/open.gif']);
        $redacted = (new RedactionProcessor())($record);
        self::assertStringNotContainsString($token, $redacted->message);
        self::assertSame('https://example.test/r/[REDACTED]/open.gif', $redacted->context['request_uri']);
    }
}
