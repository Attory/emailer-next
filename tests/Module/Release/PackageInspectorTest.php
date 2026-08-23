<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\PackageInspector;
use PHPUnit\Framework\TestCase;

final class PackageInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-release-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testValidDetachedSignatureAndEveryFileDigestAreRequired(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        $manifest = (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
        self::assertSame('0.1.0', $manifest['version']);

        file_put_contents($archive.'.sig', sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testTamperedArchiveAndIncompatibleRuntimeAreRejected(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0', '99.0.0');
        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testWrongPinnedKeyIsRejectedBeforeExtraction(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        $wrongPair = sodium_crypto_sign_keypair();
        file_put_contents($publicKey, sodium_bin2base64(sodium_crypto_sign_publickey($wrongPair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testArchiveTamperIsRejectedBeforeFileUse(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        file_put_contents($archive, 'tamper', FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    /** @return array{string, string} */
    private function fixture(string $version, string $phpMinimum = '8.4.0'): array
    {
        $archive = $this->directory.'/emailer-next-'.$version.'.zip';
        $payload = '<?php return true;';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('public/index.php', $payload));
        $zip->close();
        $manifest = ['format' => 'emailer-next-release-v1', 'version' => $version, 'source_commit' => str_repeat('a', 40), 'minimum_source_version' => '0.1.0-dev', 'minimum_schema_version' => '20260824000000', 'schema_version' => '20260824000000', 'rollback_schema_compatible' => true, 'platform' => ['php_min' => $phpMinimum, 'php_max_exclusive' => '100.0.0', 'mariadb' => ['10.11', '11.8']], 'files' => ['public/index.php' => hash('sha256', $payload)]];
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $checksumBytes = hash_file('sha256', $archive).'  '.basename($archive)."\n";
        $sbomBytes = json_encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'synthetic'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        file_put_contents($archive.'.manifest.json', $manifestBytes);
        file_put_contents($archive.'.sha256', $checksumBytes);
        file_put_contents($archive.'.spdx.json', $sbomBytes);
        $keyPair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $public = sodium_crypto_sign_publickey($keyPair);
        $signature = sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $secret);
        file_put_contents($archive.'.sig', sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        $publicKey = $this->directory.'/release.pub';
        file_put_contents($publicKey, sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        sodium_memzero($secret);

        return [$archive, $publicKey];
    }
}
