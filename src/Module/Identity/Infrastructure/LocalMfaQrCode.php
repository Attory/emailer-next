<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\Contract\MfaQrCode;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/** Produces transient in-response pixels only; never creates a file or network request. */
final readonly class LocalMfaQrCode implements MfaQrCode
{
    public function dataUri(#[\SensitiveParameter] string $provisioningUri): string
    {
        if (!str_starts_with($provisioningUri, 'otpauth://totp/') || 4096 < \strlen($provisioningUri)) {
            throw new \InvalidArgumentException('MFA provisioning context is invalid.');
        }
        try {
            $correction = ErrorCorrectionLevel::M();
            $width = Encoder::encode($provisioningUri, $correction, 'UTF-8')->getMatrix()->getWidth();
            // Integer-sized modules and a four-module quiet zone avoid resampling artifacts.
            $writer = new Writer(new GDLibRenderer(($width + 8) * 6, 4));

            return 'data:image/png;base64,'.base64_encode($writer->writeString($provisioningUri, 'UTF-8', $correction));
        } catch (\Throwable) {
            // Do not chain an encoder exception: its trace may contain the URI.
            throw new \RuntimeException('The local authenticator image could not be generated. Restart enrollment or use the supported recovery workflow.');
        }
    }
}
