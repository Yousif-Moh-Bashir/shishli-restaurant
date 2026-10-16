#!/usr/bin/env python3
"""Static/unit tests only: the injected runner never executes Docker."""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('runtime_ci', Path(__file__).with_name('php-runtime-ci.py'))
ci = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ci)
SHA = 'a' * 40
IMAGE = 'sha256:' + 'b' * 64
DIGEST = 'sha256:' + 'c' * 64
CID = 'd' * 64
NID = 'e' * 64


def result(code=0, stdout='', stderr=''):
    return subprocess.CompletedProcess([], code, stdout, stderr)


class RuntimeCiTests(unittest.TestCase):
    def make_ci(self, runner=None):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        env = {
            'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'github-hosted',
            'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '2', 'GITHUB_SHA': SHA,
            'RUNNER_TEMP': temporary.name, 'GITHUB_WORKSPACE': str(ci.ROOT),
            'CI_BUILD_DIGEST': DIGEST,
        }
        with patch.object(ci.sys, 'platform', 'linux'):
            return ci.RuntimeCi(env, runner or (lambda *args, **kwargs: result()))

    def container(self, subject, **changes):
        data = {'Id': CID, 'Image': IMAGE,
                'Config': {'Labels': {
                    'com.docker.compose.project': subject.project,
                    'com.docker.compose.service': 'runtime',
                    'io.shishli.ci.owner': ci.OWNER, 'io.shishli.ci.run': subject.identity}},
                'State': {'Health': {'Status': 'healthy'}}, 'Mounts': []}
        data.update(changes)
        return data

    def network(self, subject, **changes):
        data = {'Id': NID, 'Internal': True, 'Containers': {CID: {}}, 'Labels': {
            'com.docker.compose.project': subject.project,
            'com.docker.compose.network': 'isolated',
            'io.shishli.ci.owner': ci.OWNER, 'io.shishli.ci.run': subject.identity}}
        data.update(changes)
        return data

    def resource_runner(self, subject, container=None, network=None, volumes=''):
        commands = []
        remaining = {'container': container, 'network': network}

        def run(args, **kwargs):
            commands.append(args)
            kind = args[1]
            operation = args[2]
            if operation == 'ls':
                if kind == 'volume':
                    return result(stdout=volumes)
                item = remaining[kind]
                return result(stdout=item['Id'] if item else '')
            if operation == 'inspect':
                return result(stdout=json.dumps([remaining[kind]]))
            if operation == 'rm':
                remaining[kind] = None
                return result()
            self.fail('Unexpected command: ' + repr(args[:3]))
        subject.runner = run
        return commands

    def test_refuses_local_execution_before_any_command(self):
        for env in ({}, {'GITHUB_ACTIONS': 'true'}, {'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'self-hosted'}):
            with self.subTest(env=env), self.assertRaisesRegex(ci.CiError, 'HOSTED_RUNNER_REQUIRED'):
                ci.RuntimeCi(env, lambda *a, **k: self.fail('Must not execute'))

    def test_identity_rejects_project_injection_and_missing_sha(self):
        base = {'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'github-hosted',
                'GITHUB_RUN_ID': '1', 'GITHUB_RUN_ATTEMPT': '1', 'GITHUB_SHA': SHA}
        for key, value in [('GITHUB_RUN_ID', '../production'), ('GITHUB_RUN_ATTEMPT', '0'), ('GITHUB_SHA', '')]:
            with self.subTest(key=key), patch.object(ci.sys, 'platform', 'linux'), self.assertRaises(ci.CiError):
                ci.hosted_identity(dict(base, **{key: value}))

    def test_credentials_stay_outside_workspace_and_report(self):
        subject = self.make_ci()
        subject.prepare()

        values = dict(line.split('=', 1) for line in subject.env_file.read_text().splitlines())
        mysql_values = dict(line.split('=', 1) for line in subject.mysql_env_file.read_text().splitlines())
        self.assertEqual('shishli_ci_123_2', values['DB_DATABASE'])
        self.assertEqual('testing', values['APP_ENV'])
        self.assertNotIn('MYSQL_ROOT_PASSWORD', values)
        self.assertNotEqual(values['DB_PASSWORD'], mysql_values['MYSQL_ROOT_PASSWORD'])
        self.assertNotIn(ci.ROOT, subject.env_file.parents)
        report = subject.report_file.read_text()
        for key in ('DB_PASSWORD', 'APP_KEY'):
            self.assertNotIn(values[key], report)
        self.assertNotIn(mysql_values['MYSQL_ROOT_PASSWORD'], report)

    def test_duplicate_prepare_refuses_to_replace_credentials(self):
        subject = self.make_ci()
        subject.prepare()
        original = subject.env_file.read_bytes()

        with self.assertRaisesRegex(ci.CiError, 'DUPLICATE_INITIALIZATION'):
            subject.prepare()
        self.assertEqual(original, subject.env_file.read_bytes())

    def test_probe_exit_and_marker_both_required(self):
        for bad in (result(1, 'CI_PROBE_OK'), result(0, 'secret'), result(10, 'CI_EFFECT_MISSING')):
            with self.subTest(bad=bad), self.assertRaises(ci.CiError):
                ci.expected_probe(bad)
        ci.expected_probe(result(21, 'CI_EFFECT_MISSING\n'), 21, 'CI_EFFECT_MISSING')

    def test_connection_failure_requires_expected_failure_contract(self):
        ci.expected_probe(result(20, 'CI_EXPECTED_CONNECTION_FAILURE'), 20, 'CI_EXPECTED_CONNECTION_FAILURE')
        with self.assertRaises(ci.CiError):
            ci.expected_probe(result(10, 'CI_PROBE_FAILED'), 20, 'CI_EXPECTED_CONNECTION_FAILURE')

    def test_subprocess_output_and_timeout_are_not_printed(self):
        with patch.object(ci.subprocess, 'run', side_effect=subprocess.TimeoutExpired('SECRET', 1, output='SECRET')):
            with self.assertRaisesRegex(ci.CiError, '^COMMAND_UNAVAILABLE_OR_TIMEOUT$'):
                ci.execute(['never-executed'])
        with self.assertRaisesRegex(ci.CiError, '^COMMAND_FAILED$'):
            ci.require_success(result(1, 'SECRET', 'SECRET'))

    def test_image_digest_platform_revision_and_id_are_verified(self):
        subject = self.make_ci()
        image = {'Id': IMAGE, 'Architecture': 'amd64', 'Os': 'linux',
                 'Config': {'Labels': {'org.opencontainers.image.revision': SHA}}}
        subject.runner = lambda *a, **k: result(stdout=json.dumps(image))
        subject.image_check()
        self.assertEqual(IMAGE, subject.compose_env['CI_IMAGE'])
        self.assertEqual(DIGEST, subject.report['build_digest'])
        for key, value in [('Architecture', 'arm64'), ('Id', 'mutable-tag'), ('Os', 'windows')]:
            with self.subTest(key=key):
                subject.runner = lambda *a, **k: result(stdout=json.dumps(dict(image, **{key: value})))
                with self.assertRaisesRegex(ci.CiError, 'IMAGE_IDENTITY_MISMATCH'):
                    subject.image_check()
        subject.runner = lambda *a, **k: result(stdout=json.dumps(dict(image, Config={'Labels': {}})))
        with self.assertRaises(ci.CiError):
            subject.image_check()

    def test_missing_build_digest_is_failure(self):
        subject = self.make_ci()
        subject.env.pop('CI_BUILD_DIGEST')
        subject.runner = lambda *a, **k: result(stdout=json.dumps({
            'Id': IMAGE, 'Architecture': 'amd64', 'Os': 'linux',
            'Config': {'Labels': {'org.opencontainers.image.revision': SHA}}}))
        with self.assertRaises(ci.CiError):
            subject.image_check()

    def test_cleanup_deletes_only_verified_exact_ids_then_credentials(self):
        subject = self.make_ci()
        subject.prepare()
        commands = self.resource_runner(subject, self.container(subject), self.network(subject))
        subject.cleanup()

        deletions = [args for args in commands if args[2] == 'rm']
        self.assertEqual([['docker', 'container', 'rm', '--force', CID], ['docker', 'network', 'rm', NID]], deletions)
        self.assertFalse(subject.env_file.exists())
        self.assertFalse(subject.mysql_env_file.exists())
        self.assertEqual('passed', subject.report['cleanup'])
        for args in commands:
            if args[2] == 'ls':
                self.assertIn('label=com.docker.compose.project=shishli-php-ci-123-2', args)

    def test_cleanup_refuses_mismatched_labels_before_any_deletion(self):
        for label in ('io.shishli.ci.run', 'io.shishli.ci.owner', 'com.docker.compose.project'):
            with self.subTest(label=label):
                subject = self.make_ci()
                subject.prepare()
                network = self.network(subject)
                network['Labels'][label] = 'foreign'
                commands = self.resource_runner(subject, self.container(subject), network)
                with self.assertRaisesRegex(ci.CiError, 'RESOURCE_IDENTITY_MISMATCH'):
                    subject.cleanup()
                self.assertFalse(any(args[2] == 'rm' for args in commands))
                self.assertFalse(subject.env_file.exists())
                self.assertEqual('failed', json.loads(subject.report_file.read_text())['cleanup'])

    def test_cleanup_refuses_foreign_endpoint_or_noninternal_network(self):
        for changes in ({'Containers': {'f' * 64: {}}}, {'Internal': False}):
            with self.subTest(changes=changes):
                subject = self.make_ci()
                subject.prepare()
                commands = self.resource_runner(subject, self.container(subject), self.network(subject, **changes))
                with self.assertRaises(ci.CiError):
                    subject.cleanup()
                self.assertFalse(any(args[2] == 'rm' for args in commands))

    def test_cleanup_refuses_persistent_or_anonymous_volumes(self):
        for mounts, volumes in [([{'Type': 'volume'}], ''), ([], 'unexpected-volume')]:
            with self.subTest(mounts=mounts):
                subject = self.make_ci()
                subject.prepare()
                commands = self.resource_runner(subject, self.container(subject, Mounts=mounts), volumes=volumes)
                with self.assertRaises(ci.CiError):
                    subject.cleanup()
                self.assertFalse(any(args[2] == 'rm' for args in commands))

    def test_cleanup_without_initialization_never_calls_docker(self):
        subject = self.make_ci(lambda *a, **k: self.fail('No Docker without initialization'))
        subject.cleanup()
        self.assertEqual('not_run', subject.report['status'])
        self.assertEqual('passed', subject.report['cleanup'])

    def test_cleanup_refuses_forged_initialization(self):
        subject = self.make_ci(lambda *a, **k: self.fail('No Docker for forged marker'))
        subject.prepare()
        ci.write_json(subject.marker, {'project': 'production', 'run': subject.identity})
        with self.assertRaisesRegex(ci.CiError, 'CLEANUP_MARKER_MISMATCH'):
            subject.cleanup()

    def test_cleanup_command_failure_is_not_reported_as_success(self):
        subject = self.make_ci()
        subject.prepare()
        self.resource_runner(subject, self.container(subject))
        original = subject.runner

        def failing_remove(args, **kwargs):
            return result(1, 'SECRET', 'SECRET') if args[2] == 'rm' else original(args, **kwargs)

        subject.runner = failing_remove
        with self.assertRaisesRegex(ci.CiError, 'COMMAND_FAILED'):
            subject.cleanup()
        saved = json.loads(subject.report_file.read_text())
        self.assertEqual('failed', saved['cleanup'])
        self.assertEqual('COMMAND_FAILED', saved['error_code'])
        self.assertNotIn('SECRET', subject.report_file.read_text())
        self.assertFalse(subject.mysql_env_file.exists())

    def test_report_rejects_injected_sensitive_nested_data(self):
        subject = self.make_ci(lambda *a, **k: self.fail('No Docker for invalid report'))
        subject.prepare()
        report = dict(subject.report, service_images={'runtime': 'SECRET'})
        ci.write_json(subject.report_file, report)
        with self.assertRaisesRegex(ci.CiError, 'REPORT_IDENTITY_MISMATCH'):
            subject.cleanup()
        self.assertNotIn('SECRET', subject.report_file.read_text())
        self.assertFalse(subject.env_file.exists())

    def test_missing_worker_is_verified_before_real_worker(self):
        subject = self.make_ci()
        calls = []
        processed = False

        def fixture(*args):
            calls.append(args[0])
            if args[0] == 'effect' and not processed:
                return result(21, 'CI_EFFECT_MISSING')
            return result(0, 'CI_PROBE_OK')

        def worker():
            nonlocal processed
            processed = True
            calls.append('worker')

        subject.fixture = fixture
        subject.worker = worker
        subject.queue_test()
        self.assertEqual(['dispatch', 'absent', 'effect', 'worker', 'effect'], calls)
        self.assertEqual('passed', subject.report['checks']['missing_worker'])

    def test_worker_exit_zero_without_database_effect_fails(self):
        subject = self.make_ci()
        subject.fixture = lambda *a, **k: result(21, 'CI_EFFECT_MISSING') if a[0] == 'effect' else result(0, 'CI_PROBE_OK')
        subject.worker = lambda: None
        with self.assertRaises(ci.CiError):
            subject.queue_test()

    def test_failed_job_requires_database_verification_after_worker(self):
        subject = self.make_ci()
        subject.fixture = lambda *a, **k: result(10, 'CI_PROBE_FAILED') if a[0] == 'failed-job' else result(0, 'CI_PROBE_OK')
        subject.worker = lambda: None
        with self.assertRaises(ci.CiError):
            subject.failed_job_test()

    def test_scheduler_command_success_without_callback_effect_fails(self):
        subject = self.make_ci()
        subject.fixture = lambda *a, **k: result(21, 'CI_EFFECT_MISSING') if a[0] == 'effect' else result(0, 'CI_PROBE_OK')
        subject.console = lambda *a, **k: result()
        with self.assertRaises(ci.CiError):
            subject.scheduler_test()
        self.assertEqual('passed', subject.report['checks']['missing_scheduler'])

    def test_scheduler_failure_requires_failure_event_and_no_success_effect(self):
        subject = self.make_ci()
        subject.console = lambda *a, **k: result()
        subject.fixture = lambda *a, **k: result(21, 'CI_EFFECT_MISSING') if a[0] == 'effect' else result(0, 'CI_PROBE_OK')
        subject.scheduler_failure_test()
        subject.fixture = lambda *a, **k: result(10, 'CI_PROBE_FAILED')
        with self.assertRaises(ci.CiError):
            subject.scheduler_failure_test()

    def test_unexpected_errors_are_sanitized_and_fail_report(self):
        subject = self.make_ci()
        subject.image_check = lambda: (_ for _ in ()).throw(RuntimeError('SECRET credential'))
        with self.assertRaises(RuntimeError):
            subject.test()
        report = subject.report_file.read_text()
        self.assertNotIn('SECRET', report)
        self.assertEqual('failed', subject.report['status'])
        self.assertEqual('failed', subject.report['checks']['image'])
        self.assertEqual('INTERNAL_ERROR', subject.report['error_code'])
        self.assertEqual('INTERNAL_ERROR', ci.safe_error(ci.CiError('SECRET')))

    def test_existing_resources_prevent_startup(self):
        subject = self.make_ci()
        subject.inventory = lambda: [('container', {})]
        subject.compose = lambda *a, **k: self.fail('Must not recreate preexisting resources')
        with self.assertRaisesRegex(ci.CiError, 'RESOURCE_ALREADY_EXISTS'):
            subject.service_check()

    def test_missing_or_unhealthy_service_fails_health_validation(self):
        for unhealthy in (True, False):
            with self.subTest(unhealthy=unhealthy):
                subject = self.make_ci()
                subject.report['image_id'] = IMAGE
                containers = []
                for name in sorted(ci.SERVICES):
                    item = self.container(subject)
                    item['Config']['Labels']['com.docker.compose.service'] = name
                    if unhealthy:
                        item['State']['Health']['Status'] = 'unhealthy'
                    containers.append(('container', item))
                subject.inventory = unittest.mock.Mock(side_effect=[[], containers if unhealthy else containers[:-1]])
                with self.assertRaises(ci.CiError):
                    subject.service_check()

    def test_hosted_temp_directory_cannot_be_workspace(self):
        subject = self.make_ci()
        env = dict(subject.env, RUNNER_TEMP=str(ci.ROOT))
        with patch.object(ci.sys, 'platform', 'linux'), self.assertRaisesRegex(ci.CiError, 'UNSAFE_TEMP_DIRECTORY'):
            ci.RuntimeCi(env, lambda *a, **k: self.fail('No command allowed'))

    def test_successful_orchestration_requires_every_check(self):
        subject = self.make_ci()
        for name in ('image_check', 'service_check', 'migrations', 'failed_job_test',
                     'scheduler_failure_test', 'production_check'):
            setattr(subject, name, lambda: None)
        subject.fixture = lambda *a, **k: result(20, 'CI_EXPECTED_CONNECTION_FAILURE') if a[0].startswith('bad-') else result(0, 'CI_PROBE_OK')

        def queue_test():
            subject.check('missing_worker', lambda: None)

        def scheduler_test():
            subject.check('missing_scheduler', lambda: None)

        subject.queue_test = queue_test
        subject.scheduler_test = scheduler_test
        subject.test()
        self.assertEqual({'passed'}, set(subject.report['checks'].values()))
        self.assertEqual('passed', subject.report['status'])

    def test_incomplete_negative_checks_cannot_pass_overall(self):
        subject = self.make_ci()
        for name in ('image_check', 'service_check', 'migrations', 'failed_job_test',
                     'scheduler_failure_test', 'production_check', 'queue_test', 'scheduler_test'):
            setattr(subject, name, lambda: None)
        subject.fixture = lambda *a, **k: result(20, 'CI_EXPECTED_CONNECTION_FAILURE') if a[0].startswith('bad-') else result(0, 'CI_PROBE_OK')
        with self.assertRaisesRegex(ci.CiError, 'INCOMPLETE_TESTS'):
            subject.test()
        self.assertEqual('failed', subject.report['status'])

    def test_compose_and_workflow_static_isolation_contract(self):
        compose = ci.COMPOSE.read_text(encoding='utf-8')
        workflow = (ci.ROOT / '.github/workflows/php-runtime-integration.yml').read_text(encoding='utf-8')
        for forbidden in ('ports:', 'container_name:', 'external:', '/etc/shishli', 'privileged:', '/var/run/docker.sock'):
            self.assertNotIn(forbidden, compose)
        self.assertIn('read_only: true', compose)
        self.assertIn('internal: true', compose)
        self.assertIn('mysql:8.4', compose)
        self.assertIn('redis:7.4', compose)
        self.assertIn('target: production', workflow)
        self.assertIn('platforms: linux/amd64', workflow)
        self.assertIn('push: false', workflow)
        self.assertNotIn('packages: write', workflow)
        self.assertNotIn('continue-on-error', workflow)
        self.assertNotIn('ghcr.yml', workflow)
        self.assertIn('runs-on: ubuntu-24.04', workflow)
        self.assertIn('if: always()', workflow)
        self.assertIn('/report.json', workflow)
        self.assertNotIn('path: ${{ runner.temp }}\n', workflow)


if __name__ == '__main__':
    unittest.main(verbosity=2)
