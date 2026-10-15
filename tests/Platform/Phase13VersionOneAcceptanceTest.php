<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;

final class Phase13VersionOneAcceptanceTest extends TestCase
{
    public function testCurrentRuntimeAndReleaseTrainContractAreExact(): void
    {
        $root = \dirname(__DIR__, 2);
        $services = (string) file_get_contents($root.'/config/services.yaml');
        self::assertStringContainsString("emailer.app_version: '1.0.6'", $services);
        self::assertStringContainsString("emailer.schema_version: '20261015000000'", $services);

        $tooling = (string) file_get_contents($root.'/tools/release/check-candidate-tooling.sh');
        self::assertStringContainsString('--version=1.0.6', $tooling);
        self::assertStringContainsString('--minimum-source=1.0.5', $tooling);
        self::assertStringContainsString('--supported-sources=1.0.5', $tooling);
        self::assertStringContainsString('--minimum-schema=20261013000000', $tooling);
        self::assertStringContainsString('--schema=20261015000000', $tooling);
        self::assertStringContainsString('for supported_source in 1.0.5', $tooling);
        self::assertStringContainsString('EMAILER_UPDATE_SOURCE_VERSION="$supported_source"', $tooling);
        self::assertStringContainsString('build/artifact/bin/console" emailer:release:inspect', $tooling);
    }

    public function testNetworkIsolatedSuiteMountsAcceptedHistoryReadOnlyWithoutBakingItIntoTheImage(): void
    {
        $root = \dirname(__DIR__, 2);
        $isolated = (string) file_get_contents($root.'/tools/ci/run-network-isolated-tests.sh');
        $dockerIgnore = (string) file_get_contents($root.'/.dockerignore');

        self::assertStringContainsString('--volume "$project_root/.git:/accepted-history:ro"', $isolated);
        self::assertStringContainsString('--env EMAILER_ACCEPTED_HISTORY_GIT_DIR=/accepted-history', $isolated);
        self::assertMatchesRegularExpression('/^\.git$/m', $dockerIgnore);
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

        foreach (['current/public', 'EMAILER_SECRET_KEY_FILE=/var/lib/emailer/keys/master.key', 'EMAILER_RELEASE_PUBLIC_KEY_FILE', 'emailer-update@.service', 'doctrine:migrations:migrate', 'emailer:setup:install --complete', 'emailer:doctor:run --json', 'emailer:secrets:export-recovery-key', 'emailer:backup:create', 'emailer:operations:run', 'delivery quiesced'] as $safetyCue) {
            self::assertStringContainsString($safetyCue, $manual);
        }
        $updater = (string) file_get_contents($root.'/deployment/emailer-update@.service.example');
        self::assertStringContainsString('User=root', $updater);
        self::assertStringNotContainsString('SupplementaryGroups=', $updater);
        self::assertStringContainsString('/var/lib/emailer-candidate/verified-%i/bin/console', $updater);
        self::assertStringContainsString('EnvironmentFile=/etc/emailer/update.env', $updater);
        self::assertStringContainsString('RuntimeDirectory=emailer-update-%i', $updater);
        self::assertStringContainsString('ExecStart=/usr/bin/env EMAILER_RUNTIME_DIR=/run/emailer-update-%i /usr/bin/php ', $updater);
        self::assertStringNotContainsString('Environment=EMAILER_RUNTIME_DIR=', $updater);
        self::assertStringContainsString('--approved-target=%i', $updater);
        self::assertStringContainsString('--source-package=/var/lib/emailer-candidate/emailer-next-${EMAILER_UPDATE_SOURCE_VERSION}.zip', $updater);
        self::assertStringContainsString("TRUSTED_HOSTS='^emailer\\.client\\.example$'", $manual);
        self::assertStringContainsString('LOCK_DSN=flock:///var/lib/emailer/private/locks', $manual);
        $readiness = (string) file_get_contents($root.'/src/Module/Health/Infrastructure/SystemReadinessProbe.php');
        self::assertStringContainsString("0 === (\$stat['mode'] & 0o002)", $readiness);
        self::assertStringContainsString('is_readable($path)', $readiness);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $manual);
    }

    public function testUpdateManualAuthenticatesTargetCodeBeforeRootInstallation(): void
    {
        $root = \dirname(__DIR__, 2);
        $manual = $this->repositoryFile($root, 'docs/CLIENT-UPDATE-1.0.0.md');
        if (null === $manual) {
            return;
        }

        $verification = strpos($manual, 'sudo -u emailer php /var/lib/emailer-candidate/verify-package.php');
        $extraction = strpos($manual, 'verified-1.0.0');
        $unitInstall = strpos($manual, 'sudo install -o root -g root -m 0644 /var/lib/emailer-candidate/verified-1.0.0/deployment/emailer-update@.service.example');
        self::assertIsInt($verification);
        self::assertIsInt($extraction);
        self::assertIsInt($unitInstall);
        self::assertLessThan($extraction, $verification);
        self::assertLessThan($unitInstall, $extraction);
        self::assertStringContainsString('executes the authenticated `1.0.0` target code', $manual);
        self::assertStringContainsString('EMAILER_UPDATE_SOURCE_VERSION=0.1.0', $manual);
        self::assertStringContainsString('--installed-source', $manual);
        self::assertStringContainsString('exact predecessor archive and all four of its original sidecars', $manual);
        self::assertStringContainsString('sudo chown -R root:root <CURRENT-RELEASE-DIRECTORY>', $manual);
        self::assertStringContainsString('never operate through `current`', $manual);
    }

    public function testCandidateBuilderPreservesAConflictingPreExistingOutputDirectory(): void
    {
        $root = \dirname(__DIR__, 2);
        $builder = (string) file_get_contents($root.'/tools/release/build-1.0.0-candidate.sh');
        self::assertStringContainsString('[ "$candidate_directory_created" = true ]', $builder);
        $create = strpos($builder, 'mkdir -m 0755 "$candidate_directory"');
        $claim = strpos($builder, 'candidate_directory_created=true');
        self::assertIsInt($create);
        self::assertIsInt($claim);
        self::assertLessThan($claim, $create);
    }

    public function testCliInstallerShowsSafeSummaryAndCategorizedRemediationBeforeLock(): void
    {
        $root = \dirname(__DIR__, 2);
        $command = (string) file_get_contents($root.'/src/Module/Setup/Presentation/Cli/SetupInstallCommand.php');
        $summary = strpos($command, "section('Non-secret installation summary')");
        $confirmation = strpos($command, 'Permanently lock setup and complete this installation?');
        self::assertIsInt($summary);
        self::assertIsInt($confirmation);
        self::assertLessThan($confirmation, $summary);
        foreach (['Installer input was rejected.', 'Database setup failed.', 'Installation state validation failed.', 'unexpected safe category'] as $category) {
            self::assertStringContainsString($category, $command);
        }
        self::assertStringContainsString('Secret values are intentionally omitted.', $command);
    }

    public function testAccessibilityAcceptanceHasBlockingAutomatedAndHumanEvidence(): void
    {
        $root = \dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root.'/templates/admin/layout.html.twig');
        self::assertStringContainsString('class="skip-link"', $layout);
        self::assertStringContainsString('id="main-content"', $layout);

        $css = (string) file_get_contents($root.'/assets/styles/app.css');
        self::assertStringContainsString('outline:3px solid #856f37', $css);
        self::assertStringContainsString('outline-offset:3px', $css);
        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString('prefers-reduced-motion:reduce', $css);

        $policy = $this->repositoryFile($root, 'docs/accessibility-browser-support.md');
        if (null === $policy) {
            return;
        }
        foreach (['WCAG 2.2 Level AA', 'Chrome desktop', 'Edge desktop', 'Firefox desktop', 'Safari on macOS', 'VoiceOver', 'NVDA', 'keyboard only', 'zoom to 200%'] as $cue) {
            self::assertStringContainsString($cue, $policy);
        }
    }

    public function testHumanAcceptanceCandidateHasDeterministicBuilderAndRootInstaller(): void
    {
        $root = \dirname(__DIR__, 2);
        $builder = (string) file_get_contents($root.'/tools/release/build-0.9.1-candidate.sh');
        $installer = (string) file_get_contents($root.'/tools/release/install-0.9.1.sh');
        $manual = $this->repositoryFile($root, 'docs/CLIENT-INSTALL-0.9.1.md');

        foreach (['--version=0.9.1', 'cmp "$temporary_root/first/$candidate$suffix"', 'install-emailer-next-0.9.1.sh', 'RELEASE-CANDIDATE-RECORD.md', '0.9.0-INSTALLATION-WALKTHROUGH.md', 'accessibility-browser-support.md'] as $cue) {
            self::assertStringContainsString($cue, $builder);
        }
        foreach (['[ "$(id -u)" -eq 0 ]', 'sha256sum -c', '--expected-version="$version"', 'runuser -u "$sudo_user"', 'EMAILER_SECRET_KEY_FILE=/var/lib/emailer/keys/master.key', 'EMAILER_INSTALL_PROOF_FILE=/var/lib/emailer/private/setup/.emailer-install-proof', 'fastcgi_pass unix:/run/php/emailer.sock', 'zzzz-emailer-next.conf', 'NEEDRESTART_MODE=a', 'installed_mariadb_is_supported', 'mariadb-upgrade', 'basicConstraints=critical,CA:FALSE', 'emailer-setup-proof', 'systemctl enable --now emailer-worker.service'] as $cue) {
            self::assertStringContainsString($cue, $installer);
        }
        self::assertStringNotContainsString('apt install', $installer);
        if (null === $manual) {
            return;
        }
        foreach (['complete pre-public application', 'four files ending in', 'MariaDB administrator password', 'current Owner account password', 'Run full Doctor now', 'Create and verify local backup'] as $cue) {
            self::assertStringContainsString($cue, $manual);
        }
    }

    public function testAcceptanceWalkthroughQuestionsAreReflectedInUserFacingGuidance(): void
    {
        $root = \dirname(__DIR__, 2);
        $walkthrough = $this->repositoryFile($root, 'docs/0.9.0-INSTALLATION-WALKTHROUGH.md');
        if (null === $walkthrough) {
            return;
        }
        foreach (['Where is `emailer-next-0.9.0.zip`?', 'What are the four sidecars', 'Why did package installation ask', 'Why did `/setup` return Not Found', 'Where does the browser database password come from?', 'Where does the current six-digit TOTP code come from?', 'Why did Create and verify backup return an unexplained 403?'] as $question) {
            self::assertStringContainsString($question, $walkthrough);
        }

        $reauthentication = (string) file_get_contents($root.'/templates/admin/reauthenticate.html.twig');
        self::assertStringContainsString('This does not create or change a password.', $reauthentication);
        self::assertStringContainsString('Current account password', $reauthentication);
        self::assertStringNotContainsString('fresh password', $reauthentication);

        $backups = (string) file_get_contents($root.'/templates/admin/backups.html.twig');
        self::assertStringContainsString("purpose: 'backup_management'", $backups);
        self::assertStringContainsString('on-demand local encrypted backup', $backups);

        $database = (string) file_get_contents($root.'/templates/setup/database.html.twig');
        self::assertStringContainsString('not the MariaDB', $database);
        $mfa = (string) file_get_contents($root.'/templates/setup/mfa-enrollment.html.twig');
        self::assertStringContainsString('authenticator app', $mfa);
        $mail = (string) file_get_contents($root.'/templates/setup/mail.html.twig');
        self::assertStringContainsString('value="127.0.0.1"', $mail);
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
