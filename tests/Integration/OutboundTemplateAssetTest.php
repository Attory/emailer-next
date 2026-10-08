<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutboundTemplateAssetTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testConfiguredSmtpAdapterEmitsAbsoluteUploadedImageUrlsToMailpit(): void
    {
        self::bootKernel();
        $this->preparePhase4State($this->phase4Service(Connection::class));
        $asset = '/template-assets/'.str_repeat('c', 48).'.png';
        $subject = '[TEST] Uploaded image '.bin2hex(random_bytes(8));
        $message = new OutboundMessage('recipient@example.test', 'sender@example.test', 'Synthetic sender', null, $subject, '<img src="'.$asset.'" alt="Synthetic image">', 'Synthetic body', bin2hex(random_bytes(8)).'@example.test');
        $this->phase4Service(DeliveryTransport::class)->send($message);

        $listing = file_get_contents('http://mailpit:8025/api/v1/messages?limit=100');
        self::assertIsString($listing);
        $messages = json_decode($listing, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertIsArray($messages['messages'] ?? null);
        foreach ($messages['messages'] as $captured) {
            self::assertIsArray($captured);
            if ($subject !== ($captured['Subject'] ?? null)) {
                continue;
            }
            self::assertIsString($captured['ID'] ?? null);
            $detail = file_get_contents('http://mailpit:8025/api/v1/message/'.rawurlencode($captured['ID']));
            self::assertIsString($detail);
            $body = json_decode($detail, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('<img src="https://emailer.example.test'.$asset.'" alt="Synthetic image">', $body['HTML'] ?? null);
            self::assertSame('<img src="'.$asset.'" alt="Synthetic image">', $message->html);

            return;
        }
        self::fail('Mailpit did not capture the synthetic image message.');
    }
}
