<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity;

use App\Module\Identity\Application\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testRfcVectorAndBoundedClockWindow(): void
    {
        $totp = new Totp();
        $seed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        self::assertSame('287082', $totp->code($seed, 1));
        self::assertTrue($totp->verify($seed, '287082', 59));
        self::assertFalse($totp->verify($seed, '287082', 120));
        self::assertFalse($totp->verify($seed, 'not-a-code', 59));
    }

    public function testGeneratedSeedsAreIndependent(): void
    {
        $totp = new Totp();
        $first = $totp->generateSeed();
        $second = $totp->generateSeed();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $first);
        self::assertNotSame($first, $second);
    }
}
