<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Templates\Application\Contract\TemplateAssetStorage;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Phase8TemplateAssetExposureTest extends WebTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testValidatedPersistentAssetIsPublicWithoutSessionOrDirectoryExposure(): void
    {
        $client = self::createClient();
        $this->preparePhase4State($this->phase4Service(Connection::class));
        $source = tempnam(sys_get_temp_dir(), 'emailer-asset-route-');
        self::assertIsString($source);
        $image = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $source);
        unset($image);
        $storage = $this->phase4Service(TemplateAssetStorage::class);
        $asset = $storage->store($source);
        unlink($source);
        try {
            $client->request('GET', $asset['url'], server: $this->phase4HttpsServer('2001:db8::88'));
            self::assertResponseIsSuccessful();
            self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));

            $client->request('GET', '/template-assets/', server: $this->phase4HttpsServer('2001:db8::88'));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $storage->delete($asset['filename']);
        }
    }
}
