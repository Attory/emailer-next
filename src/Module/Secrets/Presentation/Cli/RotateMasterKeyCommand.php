<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\Contract\AdministrativeUserLoader;
use App\Module\Identity\Application\ReauthenticationService;
use App\Module\Secrets\Application\MasterKeyRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'emailer:secrets:rotate-master-key', description: 'Re-authenticate an Owner and transactionally rotate the external master key.')]
final class RotateMasterKeyCommand extends Command
{
    public function __construct(
        private readonly AdministrativeUserLoader $users,
        private readonly ReauthenticationService $reauthentication,
        private readonly MasterKeyRotator $rotator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $output->writeln('<error>Interactive Owner re-authentication is required.</error>');

            return self::FAILURE;
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }

        $login = $helper->ask($input, $output, new Question('Owner login: '));
        $password = $helper->ask($input, $output, (new Question('Owner password: '))->setHidden(true)->setHiddenFallback(false));
        $mfaCode = $helper->ask($input, $output, (new Question('MFA or recovery code (blank only if MFA is disabled): ', ''))->setHidden(true)->setHiddenFallback(false));
        $recoveryPassphrase = $helper->ask($input, $output, (new Question('Current master-key recovery-package passphrase: '))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($login) || !\is_string($password) || !\is_string($mfaCode) || !\is_string($recoveryPassphrase)) {
            return self::FAILURE;
        }

        try {
            $user = $this->users->loadForAuthentication($login);
            if (AdministrativeRole::Owner !== $user->role()
                || !$this->reauthentication->verify($user, $password, $mfaCode, 'master_key_rotation', 'local-cli', 'master-key-rotation')
            ) {
                $output->writeln('<error>Owner re-authentication failed.</error>');

                return self::FAILURE;
            }
            $confirmed = $helper->ask($input, $output, new ConfirmationQuestion(
                'Create a verified backup and rotate every encrypted secret now? Delivery must be stopped [y/N] ',
                false,
            ));
            if (true !== $confirmed) {
                $output->writeln('<comment>Master-key rotation cancelled without mutation.</comment>');

                return self::FAILURE;
            }

            $result = $this->rotator->rotate($user->id(), $recoveryPassphrase);
            $output->writeln(\sprintf(
                '<info>Master key rotated for %d encrypted records after verified backup %s.</info>',
                $result['rotated_secret_count'],
                $result['backup_id'],
            ));
            $output->writeln('<comment>Before resuming delivery, export and independently store a new recovery-key package, create a new verified backup, and run Doctor.</comment>');

            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Master-key rotation failed safely. Keep delivery stopped and follow the recovery runbook.</error>');

            return self::FAILURE;
        } finally {
            sodium_memzero($password);
            sodium_memzero($mfaCode);
            sodium_memzero($recoveryPassphrase);
        }
    }
}
