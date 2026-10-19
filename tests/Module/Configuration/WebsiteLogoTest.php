<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Configuration\Application\WebsiteLogo;
use PHPUnit\Framework\TestCase;

final class WebsiteLogoTest extends TestCase
{
    public function testRasterIsReencodedResizedAndHasNoTrailingSourceMetadata(): void
    {
        $image = imagecreatetruecolor(320, 160);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $encoded = WebsiteLogo::normalize($bytes.'<script>untrusted-source-tail</script>');
        self::assertNotNull($encoded);
        $png = WebsiteLogo::decode($encoded);
        self::assertNotNull($png);
        $size = getimagesizefromstring($png);
        self::assertNotFalse($size);
        self::assertSame(128, $size[0]);
        self::assertSame(64, $size[1]);
        self::assertStringNotContainsString('script', $png);
        self::assertLessThanOrEqual(16384, \strlen($png));
    }

    public function testVectorMarkupMalformedAndOversizedFilesAreRejected(): void
    {
        self::assertNull(WebsiteLogo::normalize('<svg onload="alert(1)"></svg>'));
        self::assertNull(WebsiteLogo::normalize(str_repeat('x', 262145)));
        self::assertNull(WebsiteLogo::normalize('not an image'));
        self::assertNull(WebsiteLogo::decode(base64_encode('<script>alert(1)</script>')));
        self::assertNull(WebsiteLogo::decode('not base64'));
    }
}
