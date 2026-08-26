<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class IdentityReadinessProbe implements LightweightReadinessProbe
{
    public function __construct(private Connection $connection, private string $environment)
    {
    }

    public function findings(): array
    {
        if ('test' === $this->environment) {
            return [];
        }
        $owners = $this->count("SELECT COUNT(*) FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1");
        if (1 > $owners) {
            return [['code' => 'active_owner', 'severity' => 'critical', 'summary' => 'No active Owner account exists.', 'remediation' => 'Recover an Owner account before operating the installation.']];
        }
        $withoutMfa = $this->count("SELECT COUNT(*) FROM app_user u WHERE u.role = 'ROLE_OWNER' AND u.active = 1 AND NOT EXISTS (SELECT 1 FROM mfa_credential m WHERE m.user_id = u.id AND m.credential_type = 'totp' AND m.active = 1)");

        return 0 < $withoutMfa ? [['code' => 'owner_mfa', 'severity' => 'critical', 'summary' => 'An active Owner does not have TOTP MFA.', 'remediation' => 'Enable and confirm TOTP for every active Owner.']] : [];
    }

    private function count(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Identity readiness count is invalid.');
        }

        return (int) $value;
    }
}
