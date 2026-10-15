<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application;

use App\Module\Secrets\Application\Contract\LightSecretInspection;
use App\Module\Secrets\Application\Contract\MasterKey;
use Doctrine\DBAL\Connection;

final readonly class LightSecretVerifier implements LightSecretInspection
{
    public function __construct(private Connection $connection, private MasterKey $masterKey, private SecretCipher $cipher)
    {
    }

    public function verifyLight(string $installationId): int
    {
        $key = $this->masterKey->load();
        $count = 0;
        try {
            foreach ($this->connection->iterateAssociative('SELECT id, record_type, envelope, schema_version, key_version FROM encrypted_secret') as $row) {
                if (!\is_string($row['id']) || !\is_string($row['record_type']) || !\is_string($row['envelope']) || !is_numeric($row['schema_version']) || !is_numeric($row['key_version']) || 1 !== (int) $row['schema_version'] || 1 !== (int) $row['key_version']) {
                    throw new \RuntimeException('Source encrypted-record metadata is unsupported.');
                }
                $plain = $this->cipher->decrypt($row['envelope'], $key, new SecretContext($installationId, $row['record_type'], $row['id'], 1, 'emailer-light'));
                try {
                    if ('recipient.token.key' === $row['record_type']) {
                        try {
                            $tokenKey = sodium_base642bin($plain, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
                        } catch (\SodiumException) {
                            throw new \RuntimeException('Source recipient signing key encoding is unsupported.');
                        }
                        try {
                            if (32 !== \strlen($tokenKey)) {
                                throw new \RuntimeException('Source recipient signing key length is unsupported.');
                            }
                        } finally {
                            sodium_memzero($tokenKey);
                        }
                    }
                } finally {
                    sodium_memzero($plain);
                }
                ++$count;
            }
        } finally {
            sodium_memzero($key);
        }

        return $count;
    }
}
