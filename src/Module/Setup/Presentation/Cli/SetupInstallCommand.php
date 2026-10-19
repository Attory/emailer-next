<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Cli;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Setup\Application\Contract\CliSetupState;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\Preflight;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emailer:setup:install',
    description: 'Run the credential-free preflight or the HTTPS-independent interactive installer.',
)]
final class SetupInstallCommand extends Command
{
    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly Preflight $preflight,
        private readonly CliSetupState $cliSetupStateStore,
        private readonly Clock $clock,
        private readonly string $publicDirectory,
        private readonly string $canonicalUri = '',
        private readonly string $backupDestinationDirectory = '',
        private readonly ?\App\Module\Operations\Application\Contract\OperationsPolicyWriter $operationsPolicy = null,
        private readonly ?DatabaseSetup $databaseSetup = null,
        private readonly ?SetupState $setupState = null,
        private readonly ?ConfigurationSetup $configurationSetup = null,
        private readonly ?OwnerSetup $ownerSetup = null,
        private readonly ?InstallationFinalizer $finalizer = null,
        private readonly ?\App\Module\Release\Application\Contract\EditionUpgrade $editionUpgrade = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('recovery-passphrase-stdin', null, InputOption::VALUE_NONE, 'Read two protected recovery-passphrase lines from the automated host installer.');
        $this->addOption('upgrade-light', null, InputOption::VALUE_NONE, 'Inspect or perform the supported Light 1.0.0/1.1.0/1.2.0/1.2.1/1.2.3 to Ripe 1.2.3 edition transition.');
        $this->addOption('edition-action', null, InputOption::VALUE_REQUIRED, 'inspect, execute, recover, accept or export-light-recovery.', 'inspect');
        $this->addOption('target-package', null, InputOption::VALUE_REQUIRED, 'Authenticated Ripe target package.');
        $this->addOption('source-package', null, InputOption::VALUE_REQUIRED, 'Exact published Light source package.');
        $this->addOption('light-recovery-export-directory', null, InputOption::VALUE_REQUIRED, 'Separate empty protected tree for isolated old-Light-backup recovery.');
        $this->addOption('recovery-directory', null, InputOption::VALUE_REQUIRED, 'Protected complete recovery destination outside private storage.');
        $this->addOption('guided', null, InputOption::VALUE_NONE, 'Use provisioned hosting settings and defer SMTP; ask only for the first administrator account.');
        $this->addOption('complete', null, InputOption::VALUE_NONE, 'Continue through the interactive full installation after preflight.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->perform($input, $output);
        } catch (\Throwable $error) {
            if ('1' !== getenv('EMAILER_INSTALLER_DIAGNOSTICS')) {
                throw $error;
            }
            $output->writeln(json_encode(\App\Module\Release\Application\Contract\InstallerFailure::report($error), JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }
    }

    private function perform(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (true === $input->getOption('upgrade-light')) {
            if (null === $this->editionUpgrade) {
                throw new \RuntimeException('Edition installer is unavailable.');
            }
            $options = [];
            foreach (['edition-action', 'target-package', 'source-package', 'recovery-directory'] as $name) {
                $value = $input->getOption($name);
                if (!\is_string($value) || '' === $value) {
                    throw new \InvalidArgumentException('Missing edition installer option: '.$name);
                }
                $options[$name] = $value;
            }
            $passphrase = null;
            if ('execute' === $options['edition-action']) {
                if (!$input->isInteractive() && true !== $input->getOption('recovery-passphrase-stdin')) {
                    throw new \RuntimeException('Edition execution requires protected terminal or installer standard input. Inspection remains read-only and noninteractive.');
                }
                $io->note('This installer transitions supported Emailer Light 1.0.0, 1.1.0, 1.2.0, 1.2.1 or 1.2.3 to Emailer Ripe 1.2.3. It preserves your account password, leaves MFA unconfigured, and keeps delivery held. A separately configured Ripe recovery-key destination must retain the existing Light package.');
                $helper = $this->getHelper('question');
                if (!$helper instanceof QuestionHelper) {
                    throw new \RuntimeException('Protected edition input is unavailable.');
                }
                if (true === $input->getOption('recovery-passphrase-stdin')) {
                    $first = fgets(STDIN, 4098);
                    $second = fgets(STDIN, 4098);
                    if (!\is_string($first) || !\is_string($second)) {
                        throw new \RuntimeException('Protected recovery input is incomplete.');
                    }
                    $passphrase = rtrim($first, "\r\n");
                    $confirmation = rtrim($second, "\r\n");
                } else {
                    $passphrase = $this->askSecret($helper, $input, $output, 'Ripe master-key recovery passphrase: ');
                    $confirmation = $this->askSecret($helper, $input, $output, 'Confirm Ripe recovery passphrase: ');
                }
                if (!hash_equals($passphrase, $confirmation)) {
                    sodium_memzero($passphrase);
                    sodium_memzero($confirmation);
                    throw new \InvalidArgumentException('Ripe recovery passphrases did not match.');
                }
                sodium_memzero($confirmation);
            }
            try {
                $result = $this->editionUpgrade->run($options['edition-action'], $options['target-package'], $options['source-package'], $options['recovery-directory'], $passphrase, \is_string($input->getOption('light-recovery-export-directory')) ? $input->getOption('light-recovery-export-directory') : null);
                $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
            } finally {
                if (null !== $passphrase) {
                    sodium_memzero($passphrase);
                }
            }

            return self::SUCCESS;
        }

        if ($this->installationLock->exists()) {
            $io->error('Setup is permanently locked for this installation.');

            return self::FAILURE;
        }

        $checks = $this->preflight->run($this->publicDirectory);
        $rows = [];
        $passed = true;

        foreach ($checks as $check) {
            $rows[] = [$check->name, $check->passed ? 'PASS' : 'FAIL', $check->message];
            $passed = $passed && $check->passed;
        }

        if (true !== $input->getOption('guided') || !$passed) {
            $io->table(['Check', 'Result', 'Guidance'], $rows);
        } else {
            $io->text('Server and protected-storage checks passed.');
        }

        if (!$passed) {
            $io->error('Correct the failed preflight checks before continuing setup.');

            return self::FAILURE;
        }

        $this->cliSetupStateStore->recordOwnershipVerified($this->clock->now());
        if (true !== $input->getOption('complete') && true !== $input->getOption('guided')) {
            $io->success('CLI ownership and preflight are verified. Re-run with --complete in a protected interactive terminal to finish installation.');

            return self::SUCCESS;
        }
        if (!$input->isInteractive()) {
            $io->error('Full installation requires protected interactive input; secrets are never accepted as command-line options.');

            return self::FAILURE;
        }
        if (null === $this->databaseSetup || null === $this->setupState || null === $this->configurationSetup || null === $this->ownerSetup || null === $this->finalizer) {
            $io->error('Full installer services are unavailable.');

            return self::FAILURE;
        }

        try {
            return $this->completeInstallation($input, $output, $io);
        } catch (\InvalidArgumentException) {
            $io->error('Installer input was rejected. Review the documented format and retry; submitted secrets were not retained or printed.');

            return self::FAILURE;
        } catch (\Doctrine\DBAL\Exception) {
            $io->error('Database setup failed. Verify the protected DSN, server reachability, account grants, and migration status before retrying.');

            return self::FAILURE;
        } catch (\RuntimeException) {
            $io->error('Installation state validation failed. Verify key/private-path ownership, mail configuration, setup progress, and Doctor results before retrying.');

            return self::FAILURE;
        } catch (\Throwable) {
            $io->error('Installation failed in an unexpected safe category. Preserve the unlocked state and obtain an operator review before retrying.');

            return self::FAILURE;
        }
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        if (!\defined('SIGINT') || !\defined('SIGTERM') || !\defined('SIGHUP')) {
            return [];
        }

        return [\SIGINT, \SIGTERM, \SIGHUP];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        // Symfony's default dispatched signal exit is zero. Interruption must
        // never look like completed account setup to a host provisioner.
        return 128 + $signal;
    }

    private function completeInstallation(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $databaseSetup = $this->databaseSetup;
        $setupState = $this->setupState;
        $configurationSetup = $this->configurationSetup;
        $ownerSetup = $this->ownerSetup;
        $finalizer = $this->finalizer;
        if (null === $databaseSetup || null === $setupState || null === $configurationSetup || null === $ownerSetup || null === $finalizer) {
            throw new \LogicException('Full installer services are unavailable.');
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }
        $summaryRows = [];

        $guided = true === $input->getOption('guided');
        if ($guided) {
            $io->section('Preparing your application');
            $databaseSetup->configureRuntime();
            $summaryRows[] = ['Database', 'Dedicated protected hosting configuration verified'];
        } else {
            $io->section('Database and installation key');
            $host = $this->ask($helper, $input, $output, 'MariaDB host: ');
            $port = $this->askInt($helper, $input, $output, 'MariaDB port [3306]: ', 3306);
            $database = $this->ask($helper, $input, $output, 'MariaDB database: ');
            $username = $this->ask($helper, $input, $output, 'MariaDB runtime username: ');
            $databasePassword = $this->askSecret($helper, $input, $output, 'MariaDB password: ');
            try {
                $databaseSetup->configure(new DatabaseInput($host, $port, $database, $username, $databasePassword));
            } finally {
                sodium_memzero($databasePassword);
            }
            $summaryRows[] = ['Database', $host.':'.$port.'/'.$database.' as '.$username];
        }
        $installationId = $setupState->initializeKey();
        $summaryRows[] = ['Installation key', 'External service-owned key initialized and validated'];

        $progress = $setupState->progress();
        if (!$progress->organisationReady) {
            $io->section('Organisation');
            if ($guided) {
                $organisation = 'Emailer';
                $baseUrl = $this->canonicalUri;
                $locale = 'en_GB';
                $timeZone = 'UTC';
            } else {
                $organisation = $this->ask($helper, $input, $output, 'Organisation display name: ');
                $baseUrl = $this->ask($helper, $input, $output, 'Canonical HTTPS base URL: ');
                $locale = $this->ask($helper, $input, $output, 'Locale [en_AU]: ', 'en_AU');
                $timeZone = $this->ask($helper, $input, $output, 'Time zone [Australia/Sydney]: ', 'Australia/Sydney');
            }
            $configurationSetup->saveOrganisation($installationId, new OrganisationInput($organisation, $baseUrl, $locale, $timeZone));
            $setupState->mark('organisation');
            $summaryRows[] = ['Organisation', $organisation.' | '.$baseUrl.' | '.$locale.' | '.$timeZone];
        } else {
            $summaryRows[] = ['Organisation', 'Previously completed; verify in the protected administration UI'];
        }

        $progress = $setupState->progress();
        if (!$progress->ownerReady) {
            $io->section('Initial Owner');
            $io->text($guided ? 'Use your email address to sign in. Choose a password of 15–256 characters; a few unrelated words are easier to remember.' : '');
            $login = $guided ? $this->askEmail($helper, $input, $output, 'Owner email: ') : $this->ask($helper, $input, $output, 'Owner email: ');
            while (true) {
                $ownerPassword = $this->askSecret($helper, $input, $output, 'Owner password (minimum 15 characters): ');
                $confirmation = $this->askSecret($helper, $input, $output, 'Confirm Owner password: ');
                try {
                    if (!hash_equals($ownerPassword, $confirmation)) {
                        if (!$guided) {
                            throw new \InvalidArgumentException('Passwords did not match.');
                        }
                        $io->error('The passwords did not match. Please enter both again.');
                        continue;
                    }
                    $ownerId = $ownerSetup->create($login, $ownerPassword);
                    $ownerSetup->skipMfa($ownerId);
                    break;
                } catch (\InvalidArgumentException $rejected) {
                    if (!$guided) {
                        throw $rejected;
                    }
                    $io->error('Choose 15–256 characters. Avoid common passwords, repeated characters and your full email address. Then enter it twice again.');
                } finally {
                    sodium_memzero($ownerPassword);
                    sodium_memzero($confirmation);
                }
            }
            $setupState->mark('owner');
            $summaryRows[] = ['Initial Owner', $login.' | MFA enrollment required after HTTPS sign-in'];
            $io->note($guided ? 'After signing in, open Settings & tools > MFA to connect your authenticator app and save your recovery codes.' : 'MFA was not enrolled because the CLI installer never prints enrollment secrets. Enrol TOTP from the authenticated HTTPS administration UI before production use.');
        } else {
            $summaryRows[] = ['Initial Owner', 'Previously completed; verify identity and MFA state in the protected administration UI'];
        }

        $progress = $setupState->progress();
        if (!$progress->mailReady) {
            if ($guided) {
                $io->note('Email delivery is not configured. You can sign in and prepare templates and campaigns; add SMTP in Mail settings when ready.');
                $summaryRows[] = ['Email delivery', 'Not configured; sending remains unavailable'];
            } else {
                $io->section('Isolated setup mail probe');
                $mailHost = $this->ask($helper, $input, $output, 'Local Mailpit host [mailpit]: ', 'mailpit');
                $mailPort = $this->askInt($helper, $input, $output, 'Local Mailpit SMTP port [1025]: ', 1025);
                $senderAddress = $this->ask($helper, $input, $output, 'Synthetic sender address: ');
                $senderName = $this->ask($helper, $input, $output, 'Synthetic sender name [Emailer Next Test]: ', 'Emailer Next Test');
                $configurationSetup->testAndSaveMail($installationId, new MailInput(
                    $mailHost,
                    $mailPort,
                    'none',
                    '',
                    '',
                    $senderAddress,
                    $senderName,
                    '',
                ));
                $summaryRows[] = ['Setup mail', $mailHost.':'.$mailPort.' | '.$senderAddress.' | '.$senderName.' | no TLS/auth'];
            }
            $setupState->mark('mail');
        } else {
            $summaryRows[] = ['Setup mail', 'Previously completed; production SMTP remains prohibited until authenticated reconfiguration'];
        }

        $progress = $setupState->progress();
        if (!$progress->policyReady || !$progress->capabilityReady) {
            $workerMode = $guided ? 'supervised' : $this->ask($helper, $input, $output, 'Worker mode, supervised or cron [supervised]: ', 'supervised');
            $configurationSetup->savePolicies(new PolicyInput(
                60,
                1,
                5,
                10_000,
                10_485_760,
                100_000,
                30,
                365,
                'You are receiving this message from the configured organisation. An unsubscribe link is included below.',
                $workerMode,
            ));
            if ($guided && '' !== $this->backupDestinationDirectory && null !== $this->operationsPolicy) {
                $this->operationsPolicy->updateBackup(['backup_automation_enabled' => true, 'backup_interval_hours' => 24, 'backup_retention_days' => 30, 'restore_drill_interval_days' => 30, 'backup_destination_type' => 'filesystem']);
                $summaryRows[] = ['Backups', 'Daily encrypted local backup; 30-day retention'];
            }
            $setupState->mark('policy');
            $setupState->mark('capability');
            $summaryRows[] = ['Worker policy', $workerMode];
        } else {
            $summaryRows[] = ['Worker policy', 'Previously completed; verify supervised/cron ownership in the protected administration UI'];
        }

        $io->section($guided ? 'Your installation' : 'Non-secret installation summary');
        if ($guided) {
            $summaryRows = array_values(array_filter($summaryRows, static fn (array $row): bool => !\in_array($row[0], ['Database', 'Installation key', 'Worker policy'], true)));
        }
        $io->table(['Area', 'Recorded value or state'], $summaryRows);
        $io->note($guided ? 'Your account and security settings are ready. Email delivery stays unavailable until you configure it in Mail settings. Secret values are never displayed.' : 'Confirm the canonical origin, identities, isolated setup mail, worker ownership, and external key boundary. Secret values are intentionally omitted.');

        if (!$guided && true !== $helper->ask($input, $output, new ConfirmationQuestion(
            'Permanently lock setup and complete this installation? [y/N] ',
            false,
        ))) {
            $io->warning('Finalization was cancelled. Setup remains resumable and is not locked.');

            return self::FAILURE;
        }

        $completedId = $finalizer->finalize();
        if (!hash_equals($installationId, $completedId)) {
            throw new \RuntimeException('Installation identity changed during finalization.');
        }
        $io->success($guided ? 'Your account is ready. Setup is now closed to protect it. The server installer will finish background services, HTTPS and your initial backup next.' : 'Installation completed and setup is permanently locked. Configure production SMTP only through the authenticated HTTPS administration UI.');

        return self::SUCCESS;
    }

    private function askEmail(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $question = (new Question($prompt))->setValidator(static function (mixed $value): string {
            if (!\is_string($value) || false === filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Enter a complete email address, such as you@example.com.');
            }

            return trim($value);
        });
        $answer = $helper->ask($input, $output, $question);
        if (!\is_string($answer)) {
            throw new \InvalidArgumentException('Administrator email was not provided.');
        }

        return $answer;
    }

    private function ask(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, ?string $default = null): string
    {
        $answer = $helper->ask($input, $output, new Question($prompt, $default));
        if (!\is_string($answer) || '' === trim($answer)) {
            throw new \InvalidArgumentException('Required installer input was omitted.');
        }

        return trim($answer);
    }

    private function askInt(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, int $default): int
    {
        $answer = $helper->ask($input, $output, new Question($prompt, (string) $default));
        if (!\is_string($answer) && !\is_int($answer)) {
            throw new \InvalidArgumentException('Numeric installer input was omitted.');
        }
        $value = filter_var($answer, FILTER_VALIDATE_INT);
        if (false === $value) {
            throw new \InvalidArgumentException('Numeric installer input is invalid.');
        }

        return $value;
    }

    private function askSecret(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $answer = $helper->ask($input, $output, (new Question($prompt))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($answer) || '' === $answer) {
            throw new \InvalidArgumentException('Required secret installer input was omitted.');
        }

        return $answer;
    }
}
