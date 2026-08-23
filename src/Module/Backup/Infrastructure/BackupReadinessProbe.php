<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Health\Application\Contract\ReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class BackupReadinessProbe implements ReadinessProbe
{
    public function __construct(private Connection $connection, private string $backupDirectory, private string $recoveryKeyFile, private string $environment)
    {
    }

    public function findings(): array
    {
        if ('test' === $this->environment) {
            return [];
        }
        $findings = [];
        $recent = $this->connection->fetchOne("SELECT 1 FROM backup_record WHERE status = 'verified' AND verified_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 24 HOUR) LIMIT 1");
        if (false === $recent) {
            $findings[] = ['code' => 'backup_recency', 'severity' => 'critical', 'summary' => 'No verified backup exists from the last 24 hours.', 'remediation' => 'Create and verify an encrypted backup.'];
        }
        if (!$this->safeRecoveryPackage()) {
            $findings[] = ['code' => 'recovery_key_package', 'severity' => 'critical', 'summary' => 'A separate recovery-key package is not configured.', 'remediation' => 'Export the passphrase-encrypted recovery-key package and store it separately.'];
        }
        if (!is_dir($this->backupDirectory) || !is_writable($this->backupDirectory)) {
            $findings[] = ['code' => 'backup_storage', 'severity' => 'critical', 'summary' => 'Private backup storage is unavailable.', 'remediation' => 'Provide writable restrictive backup storage outside the release and public roots.'];
        }

        return $findings;
    }

    private function safeRecoveryPackage(): bool
    {
        $stat = @lstat($this->recoveryKeyFile);
        if ('' === $this->recoveryKeyFile || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077) || $stat['size'] < 1 || $stat['size'] > 4096) {
            return false;
        }
        try {
            $contents = file_get_contents($this->recoveryKeyFile);
            $envelope = false === $contents ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

            if (!\is_array($envelope)
                || 'emailer-next-recovery-key-v1' !== ($envelope['format'] ?? null)
                || 'argon2id13' !== ($envelope['kdf'] ?? null)
                || 3 !== ($envelope['opslimit'] ?? null)
                || 67108864 !== ($envelope['memlimit'] ?? null)
                || !\is_string($envelope['salt'] ?? null)
                || !\is_string($envelope['nonce'] ?? null)
                || !\is_string($envelope['ciphertext'] ?? null)) {
                return false;
            }
            $salt = sodium_base642bin($envelope['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $nonce = sodium_base642bin($envelope['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $ciphertext = sodium_base642bin($envelope['ciphertext'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');

            return SODIUM_CRYPTO_PWHASH_SALTBYTES === \strlen($salt)
                && SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES === \strlen($nonce)
                && 48 === \strlen($ciphertext);
        } catch (\Throwable) {
            return false;
        }
    }
}
