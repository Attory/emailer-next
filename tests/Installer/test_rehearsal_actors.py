import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]

def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value

actors = module('actors', ROOT / 'tools/ci/rehearsal-actors.py')
historical = module('historical', ROOT / 'tools/ci/prepare-historical-rehearsal.py')


class RehearsalActorCustody(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.case = Path(self.temporary.name) / 'light-1700000000_1234'
        self.case.mkdir(mode=0o755)
        self.actor = actors.Actors(self.case, 'isolated-edition')
        self.name = 'ripe106-fault-1700000000_1234-' + hashlib.sha256(str(self.case).encode()).hexdigest()[:16]
        self.identity = 'a' * 64
        self.actor.write({self.name: self.identity})
        self.info = {'id': self.identity, 'name': '/' + self.name,
                     'labels': {'org.emailer.rehearsal-case': str(self.case), 'org.emailer.rehearsal-network': 'isolated-edition'},
                     'mounts': [{'Destination': v, 'Source': str(self.case)} for v in ['/rehearsal', '/original-rehearsal']],
                     'networks': {'isolated-edition': {}}}

    def command(self, args):
        if args[:2] == ['network', 'inspect']: return 'true'
        if args[:2] == ['container', 'ls']: return self.identity
        if args[0] == 'inspect': return json.dumps(self.info)
        return ''

    def test_kill_and_cleanup_use_the_saved_immutable_id_not_a_name(self):
        with patch.object(self.actor, 'command', side_effect=self.command) as call:
            self.actor.action('kill', self.name)
            self.actor.action('remove', self.name)
        self.assertIn(unittest.mock.call(['kill', self.identity]), call.call_args_list)
        self.assertIn(unittest.mock.call(['rm', '--force', self.identity]), call.call_args_list)

    def test_foreign_name_case_network_mount_or_labels_can_never_be_removed(self):
        for field in ['name', 'labels', 'networks', 'mounts', 'id']:
            with self.subTest(field=field):
                old = self.info[field]
                self.info[field] = {} if field in ['labels', 'networks'] else [] if field == 'mounts' else 'foreign'
                with patch.object(self.actor, 'command', side_effect=self.command) as call:
                    with self.assertRaises(actors.ActorError): self.actor.action('remove', self.name)
                    self.assertFalse(any(c.args[0][0] in ['rm', 'kill'] for c in call.call_args_list))
                self.info[field] = old

    def test_daemon_failure_is_not_treated_as_absence(self):
        with patch.object(self.actor, 'command', side_effect=actors.ActorError('unavailable')) as call:
            with self.assertRaises(actors.ActorError): self.actor.action('remove', self.name)
        self.assertFalse(any(c.args[0][0] in ['rm', 'kill'] for c in call.call_args_list))

    def test_automatic_removal_race_requires_a_new_successful_absence_proof(self):
        for disappears in (True, False):
            with self.subTest(disappears=disappears):
                inventories = 0
                def command(args):
                    nonlocal inventories
                    if args[:2] == ['container', 'ls']:
                        inventories += 1
                        return '' if disappears and inventories > 1 else self.identity
                    if args[0] == 'inspect': raise actors.ActorError('inspection race or daemon failure')
                    return self.command(args)
                with patch.object(self.actor, 'command', side_effect=command) as call:
                    if disappears: self.actor.action('wait', self.name)
                    else:
                        with self.assertRaises(actors.ActorError): self.actor.action('wait', self.name)
                    self.assertEqual(2, inventories)
                    self.assertFalse(any(c.args[0][0] in ['rm', 'kill'] for c in call.call_args_list))

    def test_reused_name_cannot_redirect_cleanup_to_a_new_actor(self):
        def command(args):
            if args[:2] == ['container', 'ls']: return ''
            return self.command(args)
        with patch.object(self.actor, 'command', side_effect=command) as call:
            self.actor.action('remove', self.name)
        self.assertFalse(any(c.args[0][0] in ['inspect', 'rm', 'kill'] for c in call.call_args_list))

    def test_unsafe_registry_or_symlink_is_rejected(self):
        self.actor.file.chmod(0o644)
        with self.assertRaises(actors.ActorError): self.actor.read()
        self.actor.file.unlink()
        self.actor.file.symlink_to('/dev/null')
        with self.assertRaises(actors.ActorError): self.actor.read()

    def test_no_registry_never_authorises_name_based_cleanup(self):
        self.actor.file.unlink()
        with patch.object(self.actor, 'command') as call: self.actor.action('remove', self.name)
        call.assert_not_called()

    def test_kill_requires_a_record_and_an_actual_live_owned_actor(self):
        self.actor.file.unlink()
        with patch.object(self.actor, 'command') as call:
            with self.assertRaises(actors.ActorError): self.actor.action('kill', self.name)
            with self.assertRaises(actors.ActorError): self.actor.action('wait', self.name)
            call.assert_not_called()
        self.actor.write({self.name: self.identity})
        def command(args):
            if args[:2] == ['container', 'ls']: return ''
            return self.command(args)
        with patch.object(self.actor, 'command', side_effect=command) as call:
            with self.assertRaises(actors.ActorError): self.actor.action('kill', self.name)
            self.actor.action('wait', self.name)
        self.assertFalse(any(c.args[0][0] == 'kill' for c in call.call_args_list))

    def test_historical_helper_remains_unchanged_and_patch_is_exclusive(self):
        script = (ROOT / 'tools/upgrade/run-light-rehearsal.sh').read_text()
        self.assertIn('readonly iteration=', script)
        self.assertNotIn('for iteration in ', script)
        self.assertNotIn('docker rm --force', script)
        self.assertNotIn('docker kill ', script)
        self.assertIn('rehearsal_actor_tool', script)
        self.assertIn('case_key', script)
        # The exact archival helper is authenticated before transformation;
        # applying the adapter to an unknown helper must fail, not patch it.
        source = Path(self.temporary.name) / 'unknown.sh'; source.write_text('# unknown source\n')
        output = Path(self.temporary.name) / 'out.sh'
        result = subprocess.run(['python3', str(ROOT / 'tools/ci/prepare-historical-rehearsal.py'), str(source), str(output)], capture_output=True)
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(output.exists())


if __name__ == '__main__': unittest.main()
