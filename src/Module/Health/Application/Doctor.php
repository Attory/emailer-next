<?php

declare(strict_types=1);

namespace App\Module\Health\Application;

use App\Module\Health\Application\Contract\ReadinessProbe;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class Doctor
{
    /** @param iterable<ReadinessProbe> $probes */
    public function __construct(private iterable $probes, private Connection $connection)
    {
    }

    /** @return array{id: string, status: 'healthy'|'warning'|'critical', critical_count: int, warning_count: int, findings: list<array{code: string, severity: 'critical'|'warning', summary: string, remediation: string}>, started_at: string, finished_at: string} */
    public function run(bool $persist = true, bool $maintenanceVerification = false): array
    {
        $started = Identity::now();
        $findings = [];
        foreach ($this->probes as $probe) {
            try {
                foreach ($probe->findings() as $finding) {
                    if (!$this->safeFinding($finding)) {
                        throw new \UnexpectedValueException('Readiness finding is unsafe.');
                    }
                    $findings[] = $finding;
                }
            } catch (\Throwable) {
                $findings[] = ['code' => 'probe_unavailable', 'severity' => 'critical', 'summary' => 'A readiness check could not complete.', 'remediation' => 'Inspect the affected runtime component and rerun Doctor.'];
            }
        }
        if ($maintenanceVerification) {
            foreach ($findings as &$finding) {
                if ('delivery_quiesced' === $finding['code'] && 'critical' === $finding['severity']) {
                    $finding['severity'] = 'warning';
                    $finding['summary'] = 'Delivery is safely quiesced for a supervised maintenance verification.';
                    $finding['remediation'] = 'Resume delivery only after this verification succeeds.';
                }
            }
            unset($finding);
        }
        usort($findings, static fn (array $a, array $b): int => [$a['severity'], $a['code']] <=> [$b['severity'], $b['code']]);
        $critical = \count(array_filter($findings, static fn (array $finding): bool => 'critical' === $finding['severity']));
        $warning = \count($findings) - $critical;
        $status = 0 < $critical ? 'critical' : (0 < $warning ? 'warning' : 'healthy');
        $finished = Identity::now();
        $id = Identity::uuidV7();
        if ($persist) {
            $this->connection->insert('health_check_run', ['id' => $id, 'status' => $status, 'critical_count' => $critical, 'warning_count' => $warning, 'findings_json' => json_encode($findings, JSON_THROW_ON_ERROR), 'started_at' => $started, 'finished_at' => $finished]);
        }

        return ['id' => $id, 'status' => $status, 'critical_count' => $critical, 'warning_count' => $warning, 'findings' => $findings, 'started_at' => $started, 'finished_at' => $finished];
    }

    public function assertSendingReady(): void
    {
        $result = $this->run(false);
        if (0 < $result['critical_count']) {
            throw new \DomainException('Sending is blocked by critical readiness findings.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function recent(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, status, critical_count, warning_count, findings_json, started_at, finished_at FROM health_check_run ORDER BY finished_at DESC LIMIT 20');
        foreach ($rows as &$row) {
            try {
                $decoded = \is_string($row['findings_json']) ? json_decode($row['findings_json'], true, 8, JSON_THROW_ON_ERROR) : [];
            } catch (\Throwable) {
                $decoded = [];
            }
            $row['findings'] = [];
            if (\is_array($decoded)) {
                foreach ($decoded as $finding) {
                    if (\is_array($finding) && $this->safeFinding($finding)) {
                        $row['findings'][] = $finding;
                    }
                }
            }
            unset($row['findings_json']);
        }

        return $rows;
    }

    /** @param array<mixed> $finding */
    private function safeFinding(array $finding): bool
    {
        if (['code', 'severity', 'summary', 'remediation'] !== array_keys($finding)
            || !\is_string($finding['code'])
            || 1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $finding['code'])
            || !\in_array($finding['severity'], ['critical', 'warning'], true)
            || !\is_string($finding['summary'])
            || !\is_string($finding['remediation'])
        ) {
            return false;
        }
        foreach ([$finding['summary'], $finding['remediation']] as $text) {
            if ('' === $text || 240 < \strlen($text) || str_contains($text, '@') || 1 === preg_match('/[\x00-\x1F\x7F]/', $text)) {
                return false;
            }
        }

        return true;
    }
}
