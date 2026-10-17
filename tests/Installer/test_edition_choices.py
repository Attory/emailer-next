import contextlib
import io
from pathlib import Path
import sys
import types
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from edition import EditionManager, installation_address, native_source_root, dispatch
from manage import ManagementError


class EditionChoices(unittest.TestCase):
    def test_action_menu_identifies_the_source_target_address_and_configuration(self):
        manager = object.__new__(EditionManager)
        manager.record = {}
        manager.source_version = '1.0.0'
        manager.installer = types.SimpleNamespace(version='1.1.0')
        manager.values = {'DEFAULT_URI': 'https://news.example.test'}
        manager.configuration = Path('/etc/emailer-light/runtime.env')
        output = io.StringIO()
        with contextlib.redirect_stdout(output), patch('builtins.input', return_value='0'):
            manager.transition_menu()
        for expected in ('Light 1.0.0', 'Ripe 1.1.0', 'https://news.example.test', '/etc/emailer-light/runtime.env'):
            self.assertIn(expected, output.getvalue())

    def test_display_never_echoes_credentials_or_terminal_control_characters(self):
        for value in ('https://user:synthetic-secret@news.example.test', 'https://news.example.test/\x1b[31m', 'https://news.example.test:8443'):
            with self.subTest(value=value), self.assertRaises(ManagementError):
                installation_address({'DEFAULT_URI': value})
        self.assertEqual('https://news.example.test', installation_address({'DEFAULT_URI': 'https://news.example.test/'}))

    def test_native_recovery_accepts_only_actual_plain_or_converted_source_children(self):
        with tempfile.TemporaryDirectory() as directory:
            releases=Path(directory)/'releases';releases.mkdir()
            for name in ('1.1.0','ripe-1.1.0'):
                path=releases/name;path.mkdir()
                self.assertEqual(str(path),native_source_root(releases,'1.1.0',str(path)))
            outside=Path(directory)/'outside';outside.mkdir()
            for recorded in (str(outside),str(releases/'1.0.6'),str(releases/'ripe-1.1.0'/'..'/'1.1.0'),None):
                with self.subTest(recorded=recorded),self.assertRaises(ManagementError):
                    native_source_root(releases,'1.1.0',recorded)
            path=releases/'1.1.0';path.rmdir();path.symlink_to(outside,target_is_directory=True)
            with self.assertRaises(ManagementError):native_source_root(releases,'1.1.0',str(path))

    def test_multiple_standard_profiles_select_only_the_displayed_address(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);paths=[]
            for name in ('emailer','emailer-light'):
                path=root/name/'runtime.env';path.parent.mkdir();path.touch();paths.append(path)
            real_path=Path
            def translate(value):
                if str(value).startswith('/etc/emailer'):return root/str(value).removeprefix('/etc/')
                return real_path(value)
            def environment(path):
                return {'DEFAULT_URI':'https://'+path.parent.name+'.example.test','DATABASE_URL':'mysql://synthetic-private-password@localhost/private','APP_SECRET':'synthetic-private-secret'}
            output=io.StringIO();installer=types.SimpleNamespace(journal=root/'no-journal')
            with patch('edition.Path',side_effect=translate),patch('edition.read_environment',side_effect=environment),patch('edition.common_dispatch',return_value=True) as common,patch('builtins.input',return_value='2'),contextlib.redirect_stdout(output):
                self.assertTrue(dispatch(installer,ManagementError))
            self.assertEqual([paths[1]],common.call_args.args[3])
            self.assertIn('https://emailer-light.example.test',output.getvalue())
            self.assertNotIn('synthetic-private',output.getvalue())
            with patch('edition.Path',side_effect=translate),patch('edition.read_environment',side_effect=environment),patch('edition.common_dispatch') as common,patch('builtins.input',return_value='99'),contextlib.redirect_stdout(io.StringIO()),self.assertRaisesRegex(ManagementError,'Nothing was changed'):
                dispatch(installer,ManagementError)
            common.assert_not_called()
