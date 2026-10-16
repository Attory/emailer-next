import contextlib
import io
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from edition import EditionManager, installation_address
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
