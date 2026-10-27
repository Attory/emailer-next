#!/usr/bin/env python3
"""Own disposable fault actors by immutable ID, case mount and internal network."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time


class ActorError(RuntimeError):
    pass


class Actors:
    def __init__(self, directory, network):
        self.directory = Path(directory)
        info = self.directory.lstat()
        if self.directory.is_symlink() or not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.getuid()) or info.st_mode & 0o022 or not re.fullmatch(r'light-[0-9]+_[0-9]+', self.directory.name):
            raise ActorError('Unsafe rehearsal case; no actor was changed.')
        self.directory = self.directory.resolve()
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,127}', network):
            raise ActorError('Invalid rehearsal network.')
        parent = self.directory.parent.lstat()
        if parent.st_uid != os.getuid() or parent.st_mode & 0o022:
            raise ActorError('Unsafe rehearsal control directory.')
        self.network = network
        self.file = self.directory.parent / ('.' + self.directory.name + '-fault-actors.json')

    def command(self, args):
        result = subprocess.run(['docker', *args], text=True, capture_output=True)
        if result.returncode:
            raise ActorError('Docker could not verify this rehearsal actor; existing actors were preserved.')
        return result.stdout.strip()

    def internal(self):
        if self.command(['network', 'inspect', '--format', '{{.Internal}}', self.network]) != 'true':
            raise ActorError('Fault actors require an internal rehearsal network.')

    def read(self):
        if not self.file.exists() and not self.file.is_symlink():
            return {}
        info = self.file.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077 or info.st_nlink != 1 or info.st_size > 16384:
            raise ActorError('Unsafe rehearsal actor record; it was preserved.')
        value = json.loads(self.file.read_text())
        if not isinstance(value, dict) or len(value) > 8 or not all(self.valid_name(k) and isinstance(v, str) and re.fullmatch(r'[a-f0-9]{64}', v) for k, v in value.items()):
            raise ActorError('Malformed rehearsal actor record; it was preserved.')
        return value

    @staticmethod
    def valid_name(name):
        return isinstance(name, str) and re.fullmatch(r'ripe106-(?:conflict|fault|upgrade)-[0-9]+_[0-9]+-[a-f0-9]{16}', name) is not None

    def write(self, value):
        descriptor, temporary = tempfile.mkstemp(prefix='.fault-actors-', dir=self.directory.parent)
        try:
            with os.fdopen(descriptor, 'w') as handle:
                json.dump(value, handle)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, self.file)
            directory = os.open(self.directory.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def present(self, identity):
        ids = self.command(['container', 'ls', '--all', '--no-trunc', '--filter', 'id=' + identity, '--format', '{{.ID}}'])
        if ids not in ('', identity):
            raise ActorError('Ambiguous rehearsal actor identity.')
        return ids == identity

    def owned(self, identity, name):
        self.internal()
        if not self.present(identity):
            return False
        template = '{"id":{{json .Id}},"name":{{json .Name}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}}}'
        try:
            report = self.command(['inspect', '--format', template, identity])
        except ActorError:
            # --rm can retire a proved ID between inventory and inspection.
            # Only a NEW successful inventory may prove its absence.
            if not self.present(identity):
                return False
            raise
        info = json.loads(report)
        if not isinstance(info, dict) or not isinstance(info.get('mounts'), list) or not isinstance(info.get('networks'), dict) or not isinstance(info.get('labels'), dict):
            raise ActorError('Malformed Docker actor custody report.')
        labels = info['labels']
        mounts = {v.get('Destination'): v.get('Source') for v in info.get('mounts', [])}
        if info.get('id') != identity or info.get('name') != '/' + name or labels.get('org.emailer.rehearsal-case') != str(self.directory) or labels.get('org.emailer.rehearsal-network') != self.network or set(info.get('networks', {})) != {self.network} or any(mounts.get(v) != str(self.directory) for v in ('/rehearsal', '/original-rehearsal')):
            raise ActorError('Rehearsal actor ownership differs; no actor was changed.')
        return True

    def run(self, args):
        if not args or args[0] != 'run':
            raise ActorError('Only a rehearsal Docker run is permitted.')
        self.internal()
        args = ['run', '--label', 'org.emailer.rehearsal-case=' + str(self.directory), '--label', 'org.emailer.rehearsal-network=' + self.network, *args[1:]]
        if '--detach' not in args:
            return subprocess.run(['docker', *args]).returncode
        if args.count('--name') != 1:
            raise ActorError('A detached rehearsal actor requires one bounded name.')
        name = args[args.index('--name') + 1]
        if not self.valid_name(name):
            raise ActorError('Invalid detached rehearsal actor name.')
        record = self.read()
        if name in record and self.present(record[name]):
            raise ActorError('This case already has an active actor; it was preserved.')
        identity = self.command(args)
        if not re.fullmatch(r'[a-f0-9]{64}', identity) or not self.owned(identity, name):
            raise ActorError('New rehearsal actor identity could not be verified; retain this case for diagnosis.')
        record[name] = identity
        self.write(record)
        print(identity)
        return 0

    def action(self, operation, name):
        if operation not in ('kill', 'remove', 'wait'):
            raise ActorError('Invalid rehearsal actor operation.')
        if not self.valid_name(name):
            raise ActorError('Invalid rehearsal actor name.')
        record = self.read()
        identity = record.get(name)
        if identity is None:
            if operation != 'remove':
                raise ActorError('No owned actor record proves this fault or exit.')
            return
        if operation == 'wait':
            for _ in range(100):
                if not self.owned(identity, name):
                    return
                time.sleep(0.1)
            raise ActorError('The owned rehearsal actor has not exited; it was retained.')
        if self.owned(identity, name):
            self.command(['kill', identity] if operation == 'kill' else ['rm', '--force', identity])
        elif operation == 'kill':
            raise ActorError('The intended fault actor already exited; no process kill was proved.')


def main(argv):
    try:
        operation, directory, network, *arguments = argv
        actors = Actors(directory, network)
        if operation == 'run' and arguments[:1] == ['--']:
            return actors.run(arguments[1:])
        if operation not in ('kill', 'remove', 'wait') or len(arguments) != 1:
            raise ActorError('Invalid rehearsal actor action.')
        actors.action(operation, arguments[0])
        return 0
    except (ActorError, ValueError, OSError, IndexError):
        print('Rehearsal actor verification failed. Existing actors and diagnostic evidence were preserved.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
