"""Public packaging must never adopt an older installation or its recovery state."""
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from provision import Installer, SetupError


@unittest.skipUnless(os.geteuid() == 0, 'Run in the isolated root container; no host installation is used.')
class PublicFreshScope(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='ripe-public-', dir='/run')
        self.addCleanup(self.directory.cleanup)
        root = Path(self.directory.name)
        self.installer = object.__new__(Installer)
        self.installer.product = 'emailer-ripe'
        self.installer.version = '1.3.12'
        self.installer.meta = {'fresh_install_only': True, 'archive': 'runtime.zip', 'files': {'runtime.zip': 'a' * 64}}
        self.installer.journal = root / 'state.json'
        self.installer.config = root / 'config'
        self.installer.base = root / 'application'
        self.installer.private = root / 'data' / 'private'

    def journal(self, digest='a' * 64):
        self.installer.journal.write_text(json.dumps({'product': 'emailer-ripe', 'version': '1.3.12', 'package_sha256': digest}))
        self.installer.journal.chmod(0o600)

    def test_clean_host_can_continue(self):
        self.installer.check_public_install_scope()

    def test_existing_configuration_is_preserved_and_refused(self):
        self.installer.config.mkdir()
        sentinel = self.installer.config / 'runtime.env'
        sentinel.write_text('synthetic fixture')
        with self.assertRaises(SetupError):
            self.installer.check_public_install_scope()
        self.assertEqual('synthetic fixture', sentinel.read_text())

    def test_same_exact_fresh_operation_can_resume(self):
        self.journal()
        self.installer.config.mkdir()
        self.installer.check_public_install_scope()

    def test_different_package_cannot_adopt_saved_operation(self):
        self.journal('b' * 64)
        before = self.installer.journal.read_bytes()
        with self.assertRaises(SetupError):
            self.installer.check_public_install_scope()
        self.assertEqual(before, self.installer.journal.read_bytes())

    def test_linked_operation_record_is_refused(self):
        target = self.installer.journal.with_name('retained-state')
        target.write_text('{}')
        self.installer.journal.symlink_to(target)
        with self.assertRaises(SetupError):
            self.installer.check_public_install_scope()
        self.assertEqual('{}', target.read_text())

    def test_historical_packaging_keeps_its_existing_dispatch(self):
        self.installer.meta.pop('fresh_install_only')
        self.installer.config.mkdir()
        self.installer.check_public_install_scope()


if __name__ == '__main__':
    unittest.main()
