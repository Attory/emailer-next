<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\AppearanceInitialization;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Shared\MaintenanceOperationLock;

/** Non-secret installation presentation settings; included in encrypted application backups. */
final readonly class AppearanceSettings implements AppearanceInitialization
{
    public function __construct(
        private string $appearanceDirectory,
        private WebsiteBranding $branding,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
        private MaintenanceOperationLock $maintenance,
    ) {
    }

    public function initializeFresh(): void
    {
        $this->mutate(function (): void {
            if (null === $this->read('default.json')) {
                $this->write('default.json', ['skin' => 'studio']);
            }
        });
    }

    /** @return array{skin: string, motion: string, branding: array{website_url: string, refreshed_at: ?string, tokens: array<string, string>, logo_url: ?string}} */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Auditor, 'appearance.view');
        $preference = $this->read($this->preferenceFile($actor));
        $skin = $preference['skin'] ?? $this->read('default.json')['skin'] ?? 'classic';
        $motion = $preference['motion'] ?? 'system';

        return [
            'skin' => \is_string($skin) && \in_array($skin, ['classic', 'website', 'studio', 'warm'], true) ? $skin : 'classic',
            'motion' => 'reduced' === $motion ? 'reduced' : 'system',
            'branding' => $this->savedBranding(),
        ];
    }

    /** @return array{skin: string, motion: string, branding: array{website_url: string, refreshed_at: ?string, tokens: array<string, string>, logo_url: ?string}} */
    public function save(AdministrativeActor $actor, string $skin, string $motion): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Auditor, 'appearance.save');
        if (!\in_array($skin, ['classic', 'website', 'studio', 'warm'], true) || !\in_array($motion, ['system', 'reduced'], true)) {
            throw new \InvalidArgumentException('Choose one of the displayed appearances and animation preferences.');
        }
        $this->mutate(fn () => $this->write($this->preferenceFile($actor), ['skin' => $skin, 'motion' => $motion]));

        return $this->view($actor);
    }

    /** @param array<mixed> $tokens
     * @return array{website_url: string, refreshed_at: ?string, tokens: array<string, string>, logo_url: ?string}
     */
    public function changeBranding(AdministrativeActor $actor, string $action, string $website, array $tokens): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'appearance.branding.change');
        if (!\in_array($action, ['refresh', 'save'], true)) {
            throw new \InvalidArgumentException('Choose Refresh from website or Save website corrections.');
        }
        $website = '' === trim($website) && 'save' === $action ? '' : WebsiteUrlPolicy::normalize(trim($website));
        // Reserve the shared fetch budget under lock, then release it before network I/O.
        $generation = null;
        if ('refresh' === $action) {
            $this->mutate(function () use (&$generation): void {
                $generation = $this->generation();
                $last = $this->read('fetch-budget.json')['last_attempt'] ?? 0;
                if (\is_int($last) && time() - $last < 30) {
                    throw new \InvalidArgumentException('Wait thirty seconds before refreshing website branding again.');
                }
                $this->write('fetch-budget.json', ['last_attempt' => time()]);
            });
            $brand = $this->branding->extract($website);
        } else {
            $saved = $this->savedBranding();
            $raw = $this->read('branding.json');
            $brand = ['website_url' => $website, 'refreshed_at' => $website === $saved['website_url'] ? $saved['refreshed_at'] : null, 'tokens' => WebsiteBranding::validate($tokens), 'logo_url' => null, 'logo_data' => $website === $saved['website_url'] ? ($raw['logo_data'] ?? null) : null];
        }
        $this->mutate(function () use ($actor, $brand, $action, $generation): void {
            if (null !== $generation && !hash_equals($generation, $this->generation())) {
                throw new \InvalidArgumentException('Website branding changed while the website was loading. Your newer saved settings were preserved. Review them before refreshing again.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'configuration.appearance.branding.'.$action, null, 'success'));
            $this->write('branding.json', $brand);
        });

        return $this->savedBranding();
    }

    /** @return array{website_url: string, refreshed_at: ?string, tokens: array<string, string>, logo_url: ?string} */
    private function savedBranding(): array
    {
        $saved = $this->read('branding.json');
        $fallback = ['website_url' => '', 'refreshed_at' => null, 'tokens' => WebsiteBranding::defaults(), 'logo_url' => null];
        if (null === $saved) {
            return $fallback;
        }
        try {
            $url = $saved['website_url'] ?? '';
            $tokens = $saved['tokens'] ?? null;
            if (!\is_string($url) || !\is_array($tokens)) {
                return $fallback;
            }
            $date = $saved['refreshed_at'] ?? null;

            return ['website_url' => '' === $url ? '' : WebsiteUrlPolicy::normalize($url), 'refreshed_at' => \is_string($date) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $date) ? $date : null, 'tokens' => WebsiteBranding::validate($tokens), 'logo_url' => null === $this->logo() ? null : '/admin/appearance/logo.png'];
        } catch (\InvalidArgumentException) {
            return $fallback;
        }
    }

    private function generation(): string
    {
        return hash('sha256', json_encode($this->read('branding.json'), JSON_THROW_ON_ERROR));
    }

    public function logo(): ?string
    {
        $value = $this->read('branding.json')['logo_data'] ?? null;

        return \is_string($value) ? WebsiteLogo::decode($value) : null;
    }

    private function preferenceFile(AdministrativeActor $actor): string
    {
        return 'user-'.hash('sha256', $actor->id()).'.json';
    }

    /** @return array<string, mixed>|null */
    private function read(string $name): ?array
    {
        $path = $this->appearanceDirectory.'/'.$name;
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        try {
            $this->assertSafe($path);
            $bytes = file_get_contents($path);
            if (false === $bytes || 32768 < \strlen($bytes)) {
                return null;
            }
            $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);

            if (!\is_array($value) || array_is_list($value)) {
                return null;
            }
            $result = [];
            foreach ($value as $key => $item) {
                if (!\is_string($key)) {
                    return null;
                }
                $result[$key] = $item;
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    private function mutate(\Closure $operation): void
    {
        $this->maintenance->acquire();
        try {
            $this->mutateWithMaintenanceLock($operation);
        } finally {
            $this->maintenance->release();
        }
    }

    private function mutateWithMaintenanceLock(\Closure $operation): void
    {
        $directory = $this->appearanceDirectory;
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Appearance settings cannot be saved. Check the private directory permissions.');
        }
        $stat = lstat($directory);
        if (is_link($directory) || false === $stat || 0 !== ($stat['mode'] & 0o077) || false === realpath($directory) || str_contains((string) realpath($directory), '/public/')) {
            throw new \RuntimeException('Appearance storage permissions are unsafe. The current skin is unchanged.');
        }
        $path = $directory.'/.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Appearance storage lock is unsafe.');
        }
        $oldMask = umask(0o077);
        try {
            $lock = fopen($path, 'c+b');
        } finally {
            umask($oldMask);
        }
        if (false === $lock) {
            throw new \RuntimeException('Appearance settings are busy. Try again.');
        }
        try {
            $this->assertSafe($path);
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Appearance settings are busy. Try again.');
            }
            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $data */
    private function write(string $name, array $data): void
    {
        $path = $this->appearanceDirectory.'/'.$name;
        if (file_exists($path) || is_link($path)) {
            $this->assertSafe($path);
        }
        $temporary = tempnam($this->appearanceDirectory, '.pending-');
        if (false === $temporary) {
            throw new \RuntimeException('Appearance settings cannot be saved.');
        }
        try {
            chmod($temporary, 0o600);
            $stream = fopen($temporary, 'wb');
            if (false === $stream) {
                throw new \RuntimeException('Appearance settings cannot be written.');
            }
            try {
                $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (\strlen($bytes) !== fwrite($stream, $bytes) || !fflush($stream) || !fsync($stream)) {
                    throw new \RuntimeException('Appearance settings could not be saved completely.');
                }
            } finally {
                fclose($stream);
            }
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Appearance settings could not be published.');
            }
            $directory = fopen($this->appearanceDirectory, 'r');
            if (false === $directory) {
                throw new \RuntimeException('Appearance storage cannot be synchronized.');
            }
            try {
                if (!fsync($directory)) {
                    throw new \RuntimeException('Appearance storage cannot be synchronized.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function assertSafe(string $path): void
    {
        $stat = lstat($path);
        if (false === $stat || is_link($this->appearanceDirectory) || 0o100000 !== ($stat['mode'] & 0o170000) || 0 !== ($stat['mode'] & 0o077) || 1 !== $stat['nlink'] || 32768 < $stat['size']) {
            throw new \RuntimeException('Appearance storage is unsafe.');
        }
    }
}
