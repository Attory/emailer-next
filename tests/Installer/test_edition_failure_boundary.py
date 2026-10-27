import errno
from unittest.mock import Mock, patch
import unittest
from edition import EditionManager
from manage import ManagementError
import test_management_failure as common


class EditionFailureBoundary(unittest.TestCase):
    def fixture(self):
        original, handoff, state = common.ManagedFailureBoundary().fixture()
        manager = EditionManager.__new__(EditionManager)
        manager.__dict__.update(original.__dict__)
        handoff.m = manager
        return manager, handoff, state

    def test_native_ripe_uses_one_common_failure_boundary(self):
        manager, handoff, state = self.fixture()
        manager.product = manager.installer.product = 'emailer-ripe'
        manager.transition_menu = Mock()
        manager.run_menu = Mock(side_effect=OSError(errno.ENOSPC, 'private details'))
        with patch('host.HostHandoff', return_value=handoff):
            with self.assertRaises(ManagementError): manager.run()
        self.assertEqual('failed', state['phase'])
        handoff.database.assert_called_once_with('retain-update-hold')
        manager.transition_menu.assert_not_called()

    def test_edition_host_failure_is_safe_and_never_touches_a_previous_update(self):
        manager, handoff, state = self.fixture()
        manager.product = 'emailer-light'; manager.installer.product = 'emailer-ripe'
        manager.failure_action = 'accept'
        manager.transition_menu = Mock(side_effect=common.HostError('synthetic private details'))
        handoff.current_update_snapshot = Mock(return_value=None)
        with patch('host.HostHandoff', return_value=handoff):
            with self.assertRaisesRegex(ManagementError, 'Recover or Accept') as error: manager.run()
        self.assertNotIn('synthetic private', str(error.exception))
        handoff.database.assert_not_called(); handoff.update_delivery_phase.assert_not_called()
