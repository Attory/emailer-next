<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

/** Decode and re-encode a small raster; never retain the remote file or SVG markup. */
final class WebsiteLogo
{
    public static function normalize(string $bytes): ?string
    {
        if (262144 < \strlen($bytes)) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if (false === $size || !\in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || 1 > $size[0] || 1 > $size[1] || 1024 < $size[0] || 1024 < $size[1]
        ) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (false === $image) {
            return null;
        }
        $ratio = min(1.0, 128 / max($size[0], $size[1]));
        $width = max(1, (int) floor($size[0] * $ratio));
        $height = max(1, (int) floor($size[1] * $ratio));
        $output = imagecreatetruecolor($width, $height);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
        if (false === $transparent) {
            return null;
        }
        imagefill($output, 0, 0, $transparent);
        imagecopyresampled($output, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        ob_start();
        try {
            imagepng($output, null, 9);
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
            unset($output);
        }

        return 16384 >= \strlen($png) ? base64_encode($png) : null;
    }

    public static function decode(?string $encoded): ?string
    {
        if (null === $encoded || 24000 < \strlen($encoded)) {
            return null;
        }
        $bytes = base64_decode($encoded, true);
        if (false === $bytes || !str_starts_with($bytes, "\x89PNG\x0d\x0a\x1a\x0a")) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);

        return false !== $size && IMAGETYPE_PNG === $size[2] && 128 >= $size[0] && 128 >= $size[1] ? $bytes : null;
    }
}
