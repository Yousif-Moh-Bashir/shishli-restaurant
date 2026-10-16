import importlib.util
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('coverage_gate', Path(__file__).with_name('check-laravel-results.py'))
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class CoverageGateTest(unittest.TestCase):
    def write_reports(self, directory, omit=None, outcome=None, too_few=None):
        paths = []
        for index in range(2):
            suite = ET.Element('testsuite', errors='0', failures='0', skipped='0')
            for classname, minimum in gate.REQUIRED.items():
                if classname == omit or (classname.endswith('CiServicesIntegrationTest')) != bool(index):
                    continue
                for number in range(1 if classname == too_few else minimum):
                    case = ET.SubElement(suite, 'testcase', {'class': classname, 'name': f'test_{number}'})
                    if outcome and classname.endswith('DriverAcceptanceConcurrencyTest'):
                        ET.SubElement(case, outcome)
            path = Path(directory) / f'{index}.xml'
            ET.ElementTree(suite).write(path)
            paths.append(path)
        return paths

    def test_complete_executed_coverage_is_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual(len(gate.check(self.write_reports(directory))), len(gate.REQUIRED))

    def test_missing_service_coverage_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                gate.check(self.write_reports(directory, omit='Tests\\Feature\\CiServicesIntegrationTest'))

    def test_skipped_failed_or_errored_tests_are_rejected_even_if_suite_counters_say_zero(self):
        for outcome in ('skipped', 'failure', 'error'):
            with self.subTest(outcome=outcome), tempfile.TemporaryDirectory() as directory:
                with self.assertRaises(ValueError):
                    gate.check(self.write_reports(directory, outcome=outcome))

    def test_missing_concurrency_provider_case_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                gate.check(self.write_reports(directory, too_few='Tests\\Feature\\DriverAcceptanceConcurrencyTest'))

    def test_missing_report_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(FileNotFoundError):
                gate.check([Path(directory) / 'missing.xml', Path(directory) / 'other.xml'])

    def test_malformed_report_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = self.write_reports(directory)
            paths[0].write_text('<testsuite>')
            with self.assertRaises(ET.ParseError):
                gate.check(paths)

    def test_suite_error_counters_are_rejected_even_without_case_error_nodes(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = self.write_reports(directory)
            document = ET.parse(paths[0])
            document.getroot().set('errors', '1')
            document.write(paths[0])
            with self.assertRaises(ValueError):
                gate.check(paths)


if __name__ == '__main__':
    unittest.main()
