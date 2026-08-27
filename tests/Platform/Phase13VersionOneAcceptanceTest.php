<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;

final class Phase13VersionOneAcceptanceTest extends TestCase
{
    public function testVersionOneRuntimeAndReleaseTrainContractAreExact(): void
    {
        $root = \dirname(__DIR__, 2);
        $services = (string) file_get_contents($root.'/config/services.yaml');
        self::assertStringContainsString("emailer.app_version: '1.0.0'", $services);
        self::assertStringContainsString("emailer.schema_version: '20260828000000'", $services);

        $tooling = (string) file_get_contents($root.'/tools/release/check-candidate-tooling.sh');
        self::assertStringContainsString('--version=1.0.0', $tooling);
        self::assertStringContainsString('--minimum-source=0.1.0', $tooling);
        self::assertStringContainsString('--minimum-schema=20260824010000', $tooling);
        self::assertStringContainsString('--schema=20260828000000', $tooling);
        self::assertStringContainsString('for supported_source in 0.1.0 0.2.0 0.3.0 0.4.0', $tooling);
    }

    public function testClientInstallManualContainsTheCompleteSafeSequence(): void
    {
        $root = \dirname(__DIR__, 2);
        $manual = $this->repositoryFile($root, 'docs/CLIENT-INSTALL-1.0.0.md');
        if (null === $manual) {
            return;
        }
        foreach ([
            '## 1. Record the installation change',
            '## 2. Check the supported server baseline',
            '## 3. Create the least-privilege service identity and paths',
            '## 4. Create the MariaDB database and restricted account',
            '## 5. Pin the release public key independently',
            '## 6. Stage and verify every release file',
            '## 7. Extract and freeze the immutable release',
            '## 8. Create protected runtime configuration',
            '## 9. Configure PHP-FPM and the web server',
            '## 10. Apply migrations and run credential-free preflight',
            '## 11. Complete first-run setup from the protected terminal',
            '## 12. Replace setup mail with encrypted production SMTP',
            '## 13. Install worker, scheduler, and operations services',
            '## 14. Create recovery material and the first verified backup',
            '## 15. Run acceptance and exposure checks',
            '## 16. Go-live decision',
            '## 17. Failed installation and rollback',
            '## 18. Post-install handover',
        ] as $heading) {
            self::assertStringContainsString($heading, $manual);
        }

        foreach (['current/public', 'EMAILER_SECRET_KEY_FILE', 'EMAILER_RELEASE_PUBLIC_KEY_FILE', 'doctrine:migrations:migrate', 'emailer:setup:install --complete', 'emailer:doctor:run --json', 'emailer:secrets:export-recovery-key', 'emailer:backup:create', 'emailer:operations:run', 'delivery quiesced'] as $safetyCue) {
            self::assertStringContainsString($safetyCue, $manual);
        }
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $manual);
    }

    public function testAccessibilityAcceptanceHasBlockingAutomatedAndHumanEvidence(): void
    {
        $root = \dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root.'/templates/admin/layout.html.twig');
        self::assertStringContainsString('class="skip-link"', $layout);
        self::assertStringContainsString('id="main-content"', $layout);

        $css = (string) file_get_contents($root.'/assets/styles/app.css');
        self::assertStringContainsString('outline: 2px solid #fff', $css);
        self::assertStringContainsString('box-shadow: 0 0 0 5px #17202a', $css);
        self::assertStringContainsString('min-height: 2.75rem', $css);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $css);

        $policy = $this->repositoryFile($root, 'docs/accessibility-browser-support.md');
        if (null === $policy) {
            return;
        }
        foreach (['WCAG 2.2 Level AA', 'Chrome desktop', 'Edge desktop', 'Firefox desktop', 'Safari on macOS', 'VoiceOver', 'NVDA', 'keyboard only', 'zoom to 200%'] as $cue) {
            self::assertStringContainsString($cue, $policy);
        }
    }

    private function repositoryFile(string $root, string $relativePath): ?string
    {
        $path = $root.'/'.$relativePath;
        if (!is_file($path)) {
            self::assertFalse(file_exists($root.'/.git'), 'Repository documentation is missing from a source checkout.');

            return null;
        }
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
