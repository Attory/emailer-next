"""Private edition orchestration; never included in the public Light repository."""
import json
import os
import re
from pathlib import Path
import secrets
import urllib.parse
from manage import Manager, ManagementError, protected, dispatch as common_dispatch, read_environment


def installation_address(values):
    value = values.get('DEFAULT_URI', '')
    try:
        uri = urllib.parse.urlsplit(value)
        port = uri.port
    except ValueError:
        raise ManagementError('The recorded application address is malformed. No installation was selected.') from None
    if uri.scheme != 'https' or uri.username or uri.password or port or uri.path not in ('', '/') or uri.query or uri.fragment or not uri.hostname or not re.fullmatch(r'[a-z0-9.-]+', uri.hostname):
        raise ManagementError('The recorded application address is outside the supported dedicated HTTPS hosting profile. No installation was selected.')
    return 'https://' + uri.hostname


def native_source_root(releases, version, recorded):
    try:
        parent = releases.resolve(strict=True)
        expected = (str(parent / version), str(parent / ('ripe-' + version)))
        source = Path(recorded) if isinstance(recorded, str) else None
        if version not in ('1.0.6', '1.1.0', '1.2.1', '1.2.2', '1.3.0') or recorded not in expected or source is None or source.is_symlink() or not source.is_dir() or str(source.resolve(strict=True)) != recorded:
            raise ValueError()
    except (OSError, ValueError):
        raise ManagementError('The retained update source directory changed or is outside this installation. Keep delivery held and retain its recovery record; no release or data was changed.') from None
    return recorded


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
        matches = []
        for path in journals:
            journal = json.loads(protected(path).read_text())
            if journal.get('source_version') in ('1.0.6', '1.1.0', '1.2.1', '1.2.2', '1.3.0') and journal.get('target_version') == self.installer.version and journal.get('private_directory') == str(self.private):
                native_source_root(self.releases, journal['source_version'], journal.get('source_root'))
                matches.append(journal)
        if matches:
            operation = matches[-1]
        if self.source_version == self.installer.version and choice == '1' and operation is None:
            raise ManagementError('This release is already installed. Choose Check the installation.')
        if choice == '2' and operation and operation.get('phase') == 'completed':
            raise ManagementError('This update already succeeded. Choose Check; recovery cannot downgrade it.')
        if choice == '2' and operation is None:
            return self.recover_host_preparation()
        if choice == '3' and operation is None:
            raise ManagementError('There is no retained native operation to recover or finish.')
        source_version = operation['source_version'] if operation else self.source_version
        if source_version not in ('1.0.6', '1.1.0', '1.2.1', '1.2.2', '1.3.0'):
            raise ManagementError('The new installer supports native updates from Ripe 1.0.6, 1.1.0, 1.2.1, 1.2.2 or 1.3.0. Earlier installations must complete their published supported update path first.')
        self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = source_version
        source = self.package.parent / ('emailer-next-' + source_version + '.zip')
        arguments = ['emailer:release:update', str(self.package), '--source-package=' + str(source), '--approved-target=' + self.installer.version, '--no-interaction']
        if choice == '2':
            arguments.append('--recover-native-operation=' + operation['operation_id'])
        elif choice == '3' or self.source_version == self.installer.version:
            arguments.append('--finish-native-operation=' + operation['operation_id'])
        from host import HostHandoff
        handoff = HostHandoff(self)
        handoff.prepare()
        if choice == '2':
            handoff.stop_target()
            self.stop_services()
            self.console(arguments)
            handoff.recover_host()
            return {'delivery_held': True}
        if choice == '1' and self.source_version != self.installer.version:
            handoff.drain()
            try:
                self.console(arguments)
            except ManagementError:
                saved_operations = [json.loads(protected(path).read_text()) for path in (self.private.parent / 'native-update-recovery').glob('*/native-operation.json')]
                if not any(item.get('source_version') == source_version and item.get('target_version') == self.installer.version and item.get('private_directory') == str(self.private) for item in saved_operations):
                    handoff.recover_host()
                    self.restore_services()
                raise
        handoff.adopt()
        # A successful native operation is irreversible. Only finish its writer handoff.
        journals = sorted((self.private.parent / 'native-update-recovery').glob('*/native-operation.json'))
        pending = [json.loads(protected(path).read_text()) for path in journals]
        pending = [item for item in pending if item.get('source_version') == source_version and item.get('target_version') == self.installer.version and item.get('private_directory') == str(self.private)]
        if not pending:
            raise ManagementError('The committed native operation evidence is missing. Keep delivery held and retain the recovery folder.')
        operation = pending[-1]
        self.console(['emailer:release:update', str(self.package), '--source-package=' + str(source), '--approved-target=' + self.installer.version, '--finish-native-operation=' + operation['operation_id'], '--no-interaction'])
        handoff.complete()
        return {'delivery_held': True}

    def transition_menu(self):
        source_version = self.record.get('transition_source', self.source_version)
        if source_version not in ('1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4'):
            raise ManagementError('Only Light 1.0.0, 1.1.0, 1.2.0, 1.2.1, 1.2.2, 1.2.3 and 1.2.4 are supported sources.')
        print(f'\nLight {source_version} → Ripe {self.installer.version}')
        print('Application address: ' + installation_address(self.values))
        print('Protected settings: ' + str(self.configuration))
        print('Your account password, campaigns, recipient snapshots, delivery history and existing links are retained.')
        print('Your administrator becomes an Owner. Ripe requires MFA enrollment. Delivery remains held until you accept the upgrade and explicitly resume it.')
        print('1. Upgrade with verified recovery material\n2. Recover an interrupted upgrade\n3. Accept a validated upgrade\n4. Check the upgraded application\n5. Resume delivery after reviewing readiness\n0. Exit')
        choice = input('Choose an action [0]: ').strip() or '0'
        if choice == '0':
            return
        if choice not in ('1', '2', '3', '4', '5'):
            raise ManagementError('Choose one of the displayed actions.')
        if choice in ('4', '5') and self.product != 'emailer-ripe':
            raise ManagementError('The edition upgrade has not completed. Choose Upgrade first; Check and Resume become available afterward.')
        if choice == '4':
            self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold'])
            print('Application structure verified. Complete MFA and mail settings in the application; Doctor shows remaining delivery requirements.')
            return
        if choice == '5':
            if input('Resume delivery if all sending checks pass? [y/N]: ').strip().lower() == 'y':
                self.call(['/usr/local/sbin/' + self.prefix, 'emailer:delivery:resume', '--no-interaction'])
                self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--record'])
                print('Delivery readiness passed and the operational hold was lifted. No campaign was approved.')
            return
        if choice in ('1', '3') and self.record.get('host_handoff', {}).get('phase') == 'complete':
            self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold'])
            print('This upgrade is already accepted and host setup is complete. No delivery state or service was changed. Sign in: ' + self.values['DEFAULT_URI'] + '/login')
            return
        saved = self.directory / 'edition-operation.json'
        if choice == '1' and saved.exists() and json.loads(protected(saved).read_text()).get('phase') in ('activated', 'accepted'):
            raise ManagementError('The application upgrade is already validated. Choose Accept to finish host setup; it will not convert your data again.')
        if choice == '2':
            saved = self.directory / 'edition-operation.json'
            phase = json.loads(protected(saved).read_text()).get('phase') if saved.exists() else None
            if phase == 'accepted' or self.record.get('host_handoff', {}).get('phase') in ('core-accepted', 'complete'):
                raise ManagementError('This upgrade was accepted. Choose Check or Accept to finish host setup; recovery cannot downgrade it.')
        self.stage()
        database = self.maintenance_environment()
        self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = self.installer.version
        pin = self.root / 'ripe-release-signing.pub'
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
        self.environment['EMAILER_INSTALLER_EDITION_OPERATION_FILE'] = str(self.directory / 'edition-operation.json')
        recovery = self.directory / 'edition-recovery'
        source = self.package.parent / ('emailer-light-' + source_version + '.zip')
        action = {'1': 'execute', '2': 'recover', '3': 'accept'}.get(choice)
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
        if choice == '1':
            inspection = ['--edition-action=inspect' if argument == '--edition-action=execute' else argument for argument in arguments if argument != '--recovery-passphrase-stdin']
            self.console(inspection)
        if choice == '2' and not (self.directory / 'edition-operation.json').exists():
            self.recover_host_preparation()
            print('Hosting preparation recovered. The original Light application is available; delivery remains held.')
            return
        from host import HostHandoff
        handoff = HostHandoff(self)
        handoff.prepare()
        if choice == '1':
            handoff.drain()
        elif choice == '2':
            handoff.stop_target()
            self.stop_services()
        elif choice == '3':
            handoff.adopt()
        try:
            self.console(arguments, data=data)
        except ManagementError:
            if choice == '1' and not (self.directory / 'edition-operation.json').exists():
                handoff.recover_host()
                self.restore_services()
            raise
        if choice == '1':
            handoff.adopt()
        elif choice == '2':
            handoff.recover_host()
        elif choice == '3':
            # The app may read/remove its accepted edition receipt, but cannot remove the host hold.
            receipt = self.private / 'edition-delivery-hold.json'
            account = __import__('pwd').getpwnam(handoff.name)
            if not receipt.exists():
                self.installer.durable(receipt, json.dumps({'phase': 'accepted', 'installation_uuid': handoff.state['installation_uuid']}) + '\n')
            os.chown(receipt, account.pw_uid, account.pw_gid)
            handoff.complete()
        if choice in ('2', '3'):
            if choice == '2':
                self.restore_services()
            self.record.pop('active_units', None)
            self.save()
        print('Upgrade action verified. Application address: ' + self.values['DEFAULT_URI'] + '/login')
        if choice == '1':
            print('The upgrade is validated and delivery is held. Start this same package again and choose Accept a validated upgrade to reopen sign-in. If host setup was interrupted, this same acceptance action also finishes it.')
        elif choice == '3':
            print('Acceptance recorded. Sign in with your existing account and enroll MFA. When Doctor is ready, start this package and choose Resume delivery.')
        else:
            print('The original Light release is restored. Delivery remains held; review it before resuming.')
        print('Complete recovery material: ' + str(recovery))
        print('Ripe key-recovery password (store separately): ' + str(self.directory / 'ripe-recovery-password'))


def dispatch(installer, error_type):
    paths = [Path('/etc/emailer/runtime.env'), Path('/etc/emailer-light/runtime.env')]
    existing = [path for path in paths if path.exists()]
    light_source = False
    if len(existing) == 1 and existing[0].parent.name == 'emailer-light':
        values = read_environment(existing[0])
        identity = json.loads((Path(values['EMAILER_PRIVATE_DIR']) / 'install.lock').read_text())
        light_source = identity.get('product_id') == 'emailer-light'
    if light_source and not installer.journal.exists():
        print('An existing Light installation was found at ' + installation_address(values) + '.\n1. Upgrade this Light installation to Ripe\n2. Install a separate fresh Ripe application\n0. Exit')
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
            print(str(number) + '. ' + installation_address(read_environment(path)) + ' (' + str(path.parent) + ')')
        choice = input('Installation number: ').strip()
        if not choice.isdigit() or not 1 <= int(choice) <= len(existing):
            raise error_type('No installation was selected. Nothing was changed.')
        paths = [existing[int(choice) - 1]]
    return common_dispatch(installer, error_type, EditionManager, paths)
