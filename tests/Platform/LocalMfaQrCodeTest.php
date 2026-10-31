<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Infrastructure\LocalMfaQrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zxing\QrReader;

final class LocalMfaQrCodeTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function finderPatterns(): iterable
    {
        // Public deterministic layout inputs, never enrolled account credentials.
        foreach ([0, 209, 579, 1072, 1345, 1408, 1484, 1745, 1918] as $index) {
            yield 'synthetic layout '.$index => [$index];
        }
    }

    #[DataProvider('finderPatterns')]
    public function testFullIndependentSearchDecodesExactPixelsAndPayload(int $index): void
    {
        $bits = '';
        foreach (str_split(substr(hash('sha256', 'public synthetic QR layout '.$index, true), 0, 20)) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $seed = '';
        foreach (str_split($bits, 5) as $part) {
            $seed .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[(int) bindec($part)];
        }
        $uri = \sprintf('otpauth://totp/%s?secret=%s&issuer=Emailer%%20Next&algorithm=SHA1&digits=6&period=30', rawurlencode('Emailer Next:owner@example.test'), $seed);
        $encoded = (new LocalMfaQrCode())->dataUri($uri);
        $png = base64_decode(substr($encoded, 22), true);
        self::assertTrue(\is_string($png));
        $image = imagecreatefromstring($png);
        self::assertTrue($image instanceof \GdImage);
        $matrix = Encoder::encode($uri, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $width = $matrix->getWidth();
        self::assertTrue(imagesx($image) === ($width + 8) * 6 && imagesy($image) === ($width + 8) * 6);
        $mismatches = 0;
        for ($y = 0; $y < imagesy($image); ++$y) {
            for ($x = 0; $x < imagesx($image); ++$x) {
                $mx = intdiv($x, 6) - 4;
                $my = intdiv($y, 6) - 4;
                $dark = $mx >= 0 && $my >= 0 && $mx < $width && $my < $width && 1 === $matrix->get($mx, $my);
                if ($dark !== (0 === (imagecolorat($image, $x, $y) & 0xFFFFFF))) {
                    ++$mismatches;
                }
            }
        }
        // Check all module pixels and the complete four-module white quiet zone.
        self::assertSame(0, $mismatches);
        $decoded = (new QrReader($png, QrReader::SOURCE_TYPE_BLOB, false))->text(['TRY_HARDER' => true]);
        // Boolean-only assertions prevent a diagnostic from serializing MFA material.
        self::assertTrue(\is_string($decoded) && hash_equals($uri, $decoded));
    }
}
