"""Private edition orchestration; never included in the public Light repository."""
import json
import os
from pathlib import Path
import secrets
import urllib.parse
from manage import Manager, ManagementError, protected, dispatch as common_dispatch


class EditionManager(Manager):
    def run(self):
        if self.product == 'emailer-light' or self.record.get('mode') == 'light-to-ripe':
            return self.transition_menu()
        return super().run()

    def native_action(self, choice):
        if self.product != 'emailer-ripe':
            raise ManagementError('Use the edition upgrade action for this installation.')
        journals = sorted((self.private.parent / 'native-update-recovery').glob('*/native-operation.json'))
        operation = None
        if journals:
            operation = json.loads(protected(journals[-1]).read_text())
        if self.source_version == '1.1.0' and choice == '1' and operation is None:
            raise ManagementError('This release is already installed. Choose Check the installation.')
        if choice in ('2', '3') and operation is None:
            raise ManagementError('There is no retained native operation to recover or finish.')
        source_version = operation['source_version'] if operation else self.source_version
        if source_version != '1.0.6':
            raise ManagementError('The new installer supports native updates from Ripe 1.0.6. Earlier installations must complete their published supported update path first.')
        self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = source_version
        source = self.package.parent / ('emailer-next-' + source_version + '.zip')
        arguments = ['emailer:release:update', str(self.package), '--source-package=' + str(source), '--approved-target=1.1.0', '--no-interaction']
        if choice == '2':
            arguments.append('--recover-native-operation=' + operation['operation_id'])
        elif choice == '3' or self.source_version == '1.1.0':
            arguments.append('--finish-native-operation=' + operation['operation_id'])
        self.stop_services()
        self.console(arguments)
        report = json.loads(self.console(['emailer:health:verify-runtime', '--offline-maintenance']))
        return {'delivery_held': not report['delivery_ready']}

    def transition_menu(self):
        source_version = self.record.get('transition_source', self.source_version)
        if source_version not in ('1.0.0', '1.1.0'):
            raise ManagementError('Only Light 1.0.0 and Light 1.1.0 are supported sources.')
        print('\nLight → Ripe upgrade. Your account password, campaigns, recipient snapshots, delivery history and existing links are retained.')
        print('Your administrator becomes an Owner. Ripe requires MFA enrollment. Delivery remains held until you accept the upgrade and explicitly resume it.')
        print('1. Upgrade with verified recovery material\n2. Recover an interrupted upgrade\n3. Accept a validated upgrade\n4. Check the upgraded application\n5. Resume delivery after reviewing readiness\n0. Exit')
        choice = input('Choose an action [0]: ').strip() or '0'
        if choice == '0':
            return
        if choice not in ('1', '2', '3', '4', '5'):
            raise ManagementError('Choose one of the displayed actions.')
        self.stage()
        database = self.maintenance_environment()
        self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = '1.1.0'
        pin = self.configuration.parent / 'ripe-release-signing.pub'
        official = (self.package.parent / 'release.pub').read_text()
        if pin.exists():
            if protected(pin, public=True).read_text().strip() != official.strip():
                raise ManagementError('The retained Ripe signing identity differs from this package. It was preserved.')
        else:
            self.installer.durable(pin, official, 0o644)
        self.environment['EMAILER_RELEASE_PUBLIC_KEY_FILE'] = str(pin)
        recovery_key = Path(self.values['EMAILER_SECRET_KEY_FILE']).parent / 'ripe-recovery-key.enxkey'
        self.environment['EMAILER_RECOVERY_KEY_FILE'] = str(recovery_key)
        params = urllib.parse.urlsplit(self.environment['DATABASE_URL'])
        scratch = urllib.parse.urlunsplit((params.scheme, params.netloc, '/' + database + '_edition_recovery', params.query, ''))
        self.environment['EMAILER_TRANSITION_RECOVERY_DATABASE_URL'] = scratch
        recovery = self.directory / 'edition-recovery'
        source = self.package.parent / ('emailer-light-' + source_version + '.zip')
        action = {'1': 'execute', '2': 'recover', '3': 'accept'}.get(choice)
        if choice == '4':
            self.console(['emailer:health:verify-runtime'])
            print('Application structure verified. Complete MFA and mail settings in the application; Doctor shows remaining delivery requirements.')
            return
        if choice == '5':
            if input('Resume delivery if all sending checks pass? [y/N]: ').strip().lower() == 'y':
                self.console(['emailer:delivery:resume', '--no-interaction'])
                print('Delivery readiness passed and the operational hold was lifted. No campaign was approved.')
            return
        self.record['mode'] = 'light-to-ripe'
        self.record['transition_source'] = source_version
        self.save()
        arguments = ['emailer:setup:install', '--upgrade-light', '--edition-action=' + action, '--target-package=' + str(self.package), '--source-package=' + str(source), '--recovery-directory=' + str(recovery), '--no-interaction']
        data = None
        if choice == '1':
            password_path = self.directory / 'ripe-recovery-password'
            if not password_path.exists():
                self.installer.durable(password_path, secrets.token_urlsafe(36) + '\n')
            phrase = protected(password_path).read_text().strip()
            data = phrase + '\n' + phrase + '\n'
            arguments.append('--recovery-passphrase-stdin')
            self.stop_services()
        elif choice == '2':
            self.stop_services()
        self.console(arguments, data=data)
        if choice in ('2', '3'):
            self.restore_services()
            self.record.pop('active_units', None)
            self.save()
        print('Upgrade action verified. Application address: ' + self.values['DEFAULT_URI'] + '/login')
        if choice == '1':
            print('The upgrade is validated and delivery is held. Start this same package again and choose Accept a validated upgrade to reopen sign-in.')
        elif choice == '3':
            print('Acceptance recorded. Sign in with your existing account and enroll MFA. When Doctor is ready, start this package and choose Resume delivery.')
        else:
            print('The original Light release is restored. Delivery remains held; review it before resuming.')
        print('Complete recovery material: ' + str(recovery))
        print('Ripe key-recovery password (store separately): ' + str(self.directory / 'ripe-recovery-password'))


def dispatch(installer, error_type):
    paths = [Path('/etc/emailer/runtime.env'), Path('/etc/emailer-light/runtime.env')]
    existing = [path for path in paths if path.exists()]
    if len(existing) == 1 and existing[0].parent.name == 'emailer-light' and not installer.journal.exists():
        print('An existing Light installation was found.\n1. Upgrade this Light installation to Ripe\n2. Install a separate fresh Ripe application\n0. Exit')
        choice = input('Choose an installation [0]: ').strip() or '0'
        if choice == '0':
            return True
        if choice == '2':
            return False
        if choice != '1':
            raise error_type('Choose one of the displayed installations.')
    if len(existing) > 1:
        print('Choose the installation to manage:')
        for number, path in enumerate(existing, 1):
            print(str(number) + '. ' + str(path.parent))
        choice = input('Installation number: ').strip()
        if not choice.isdigit() or not 1 <= int(choice) <= len(existing):
            raise error_type('No installation was selected. Nothing was changed.')
        paths = [existing[int(choice) - 1]]
    return common_dispatch(installer, error_type, EditionManager, paths)
