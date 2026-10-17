"""Security regression tests; no Docker, Trivy process or database is started."""
from contextlib import redirect_stdout
from datetime import datetime, timedelta, timezone
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('evidence', Path(__file__).with_name('collect-trivy-evidence.py'))
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)
SHA = 'a' * 40
DIGEST = 'sha256:' + 'b' * 64
SENSITIVE = 'DO_NOT_EXPORT_PRIVATE_CONTENT'


class EvidenceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.console = io.StringIO()
        redirect = redirect_stdout(self.console)
        redirect.__enter__()
        self.addCleanup(redirect.__exit__, None, None, None)
        self.private, self.cache, self.public = [self.root / name for name in ('shishli-trivy-private', 'shishli-trivy-cache', 'shishli-trivy-evidence')]
        self.now = datetime.now(timezone.utc)
        self.db = {'Version': 2, 'UpdatedAt': self.now.isoformat(), 'DownloadedAt': self.now.isoformat(),
                   'NextUpdate': (self.now + timedelta(hours=2)).isoformat()}
        self.images = {}
        for name in ('php', 'nginx'):
            path = self.root / (name + '.tar')
            config = json.dumps({'os': 'linux', 'architecture': 'amd64', 'config': {
                'Env': ['PRIVATE_KEY=' + SENSITIVE], 'Labels': {'org.opencontainers.image.revision': SHA},
            }}).encode()
            with tarfile.open(path, 'w') as archive:
                for filename, data in [('config.json', config), ('manifest.json', json.dumps([{'Config': 'config.json'}]).encode())]:
                    member = tarfile.TarInfo(filename)
                    member.size = len(data)
                    archive.addfile(member, io.BytesIO(data))
            self.images[name] = str(path)
        self.findings = {}
        self.behavior = {}
        self.commands = []
        self.databases = {}
        self.environment = patch.dict(os.environ, {
            'IMAGE_SHA': SHA, 'GITHUB_REPOSITORY': 'owner/repo', 'GITHUB_RUN_ID': '123',
            'GITHUB_RUN_ATTEMPT': '2', 'PHP_BUILD_DIGEST': DIGEST, 'NGINX_BUILD_DIGEST': DIGEST,
            'PREPARE_OUTCOME': 'success',
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.image_patch = patch.dict(evidence.IMAGES, self.images, clear=True)
        self.image_patch.start()
        self.addCleanup(self.image_patch.stop)
        self.invoke_patch = patch.object(evidence, 'invoke', side_effect=self.fake_command)
        self.invoke_patch.start()
        self.addCleanup(self.invoke_patch.stop)
        evidence.prepare(self.private, self.cache)

    def report(self, name, kind):
        context = evidence.load(self.private / 'context.json')
        rows = self.findings.get((name, kind), [])
        result = {
            'SchemaVersion': 2, 'ArtifactType': 'container_image', 'CreatedAt': self.now.isoformat(),
            'Trivy': {'Version': evidence.VERSION},
            'Metadata': {'ImageID': context['images'][name]['config_digest'], 'ImageConfig': {'Env': [SENSITIVE]}},
            'unexpected': {'private': SENSITIVE},
        }
        if kind == 'vuln':
            result['Results'] = [{'Class': 'os-pkgs', 'Target': SENSITIVE, 'Vulnerabilities': rows, 'Code': SENSITIVE}]
        elif rows:
            result['Results'] = [{'Secrets': rows, 'Target': SENSITIVE}]
        return result

    def fake_command(self, command):
        self.commands.append(command)
        if command == ['git', 'rev-parse', 'HEAD']:
            return subprocess.CompletedProcess(command, 0, SHA + '\n', '')
        if command == ['trivy', '--version']:
            return subprocess.CompletedProcess(command, 0, 'Version: ' + evidence.VERSION + '\n', '')
        if '--download-db-only' in command:
            repository = command[command.index('--db-repository') + 1]
            metadata = self.databases.get(repository, self.db)
            if metadata == 'timeout':
                raise subprocess.TimeoutExpired(command, 600, output=SENSITIVE)
            if metadata is None:
                return subprocess.CompletedProcess(command, 1, SENSITIVE, SENSITIVE)
            folder = Path(command[command.index('--cache-dir') + 1]) / 'db'
            folder.mkdir(parents=True, exist_ok=True)
            evidence.write(folder / 'metadata.json', metadata)
            (folder / 'trivy.db').write_bytes(b'test database snapshot')
            return subprocess.CompletedProcess(command, 0, '', '')
        name = Path(command[command.index('--input') + 1]).stem
        kind = command[command.index('--scanners') + 1]
        self.assertEqual(command[command.index('--exit-code') + 1], '1')
        self.assertIn('--ignore-unfixed=false', command)
        self.assertIn('--skip-db-update', command)
        self.assertEqual(command[command.index('--severity') + 1], 'HIGH,CRITICAL' if kind == 'vuln' else evidence.ALL_SEVERITIES)
        mode = self.behavior.get((name, kind))
        if mode == 'timeout':
            raise subprocess.TimeoutExpired(command, 600, output=SENSITIVE)
        output = Path(command[command.index('--output') + 1])
        report = self.report(name, kind)
        code = 1 if self.findings.get((name, kind)) else 0
        if mode == 'missing':
            return subprocess.CompletedProcess(command, 2, SENSITIVE, SENSITIVE)
        if mode == 'malformed':
            output.write_text(SENSITIVE)
            return subprocess.CompletedProcess(command, 2, SENSITIVE, SENSITIVE)
        if mode == 'wrong-image':
            report['Metadata']['ImageID'] = 'sha256:' + 'f' * 64
        if mode == 'partial':
            report.pop('Results', None)
        if mode == 'crash-with-json':
            code = 2
        if mode == 'false-success':
            code = 0
        if mode == 'wrong-scanner':
            report['Trivy']['Version'] = '0.1.0'
        if mode == 'schema-mismatch':
            report['SchemaVersion'] = 1
        if mode == 'invalid-fix-status':
            report['Results'][0]['Vulnerabilities'][0]['Status'] = SENSITIVE
        evidence.write(output, report)
        return subprocess.CompletedProcess(command, code, SENSITIVE, SENSITIVE)

    def vulnerability(self, severity='HIGH'):
        return {'VulnerabilityID': 'CVE-2026-12345', 'PkgName': 'libxml2', 'InstalledVersion': '2.9.14+deb12u6',
                'FixedVersion': '', 'Status': 'affected', 'Severity': severity,
                'Description': SENSITIVE, 'Code': {'Lines': [SENSITIVE]}, 'Match': SENSITIVE}

    def scan_both(self):
        return {name: 'success' if evidence.scan_image(self.private, self.cache, name) == 0 else 'failure' for name in self.images}

    def collected(self, outcomes):
        code = evidence.collect(self.private, self.cache, self.public, outcomes)
        manifest = evidence.load(self.public / 'manifest.json')
        for file in self.public.iterdir():
            self.assertNotIn(SENSITIVE, file.read_text())
        self.assertNotIn(SENSITIVE, self.console.getvalue())
        self.assertTrue(all(item['code'] in evidence.DIAGNOSTIC_CODES for item in manifest['diagnostics']))
        self.assertTrue(set(file.name for file in self.public.iterdir()) <= {'manifest.json', 'php-vulnerabilities.json', 'nginx-vulnerabilities.json'})
        return code, manifest

    def test_clean_scans_pass_and_preserve_identity_and_database_fingerprint(self):
        outcomes = self.scan_both()
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 0)
        self.assertEqual(manifest['gate'], 'passed')
        self.assertEqual(manifest['collection_status'], 'completed')
        self.assertEqual(manifest['source']['sha'], SHA)
        self.assertEqual(manifest['scanner']['db_sha256'], evidence.digest(self.cache / 'db/trivy.db'))
        self.assertEqual(manifest['images']['php']['identity']['build_digest'], DIGEST)
        self.assertEqual(manifest['images']['php']['secrets']['finding_count'], 0)

    def test_completed_php_77_high_3_critical_collects_successfully_without_passing_security_gate(self):
        rows = []
        for index in range(80):
            row = self.vulnerability('HIGH' if index < 77 else 'CRITICAL')
            row['VulnerabilityID'] = 'CVE-2026-' + str(10000 + index)
            rows.append(row)
        self.findings[('php', 'vuln')] = rows
        outcomes = self.scan_both()
        self.assertEqual(outcomes, {'php': 'failure', 'nginx': 'success'})
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 0, 'Reliable evidence collection must succeed despite a failed security verdict')
        self.assertEqual(manifest['gate'], 'failed')
        self.assertEqual(manifest['collection_status'], 'completed')
        self.assertEqual(manifest['preparation'], 'success')
        self.assertFalse(manifest['errors'])
        php = evidence.load(self.public / 'php-vulnerabilities.json')
        self.assertEqual(php['scan']['status'], 'completed')
        self.assertEqual(php['scan']['gate'], 'failed')
        self.assertEqual(php['scan']['exit_code'], 1)
        self.assertEqual(php['scan']['finding_count'], 80)
        self.assertEqual(sum(row['severity'] == 'HIGH' for row in php['findings']), 77)
        self.assertEqual(sum(row['severity'] == 'CRITICAL' for row in php['findings']), 3)
        self.assertTrue((self.public / 'nginx-vulnerabilities.json').exists())

    def test_collect_cli_returns_zero_but_does_not_claim_security_pass_for_php_findings(self):
        self.findings[('php', 'vuln')] = [self.vulnerability('CRITICAL')]
        outcomes = self.scan_both()
        with patch.dict(os.environ, {'RUNNER_TEMP': str(self.root),
                                    'PHP_SCAN_OUTCOME': outcomes['php'], 'NGINX_SCAN_OUTCOME': outcomes['nginx']}), patch.object(sys, 'argv', ['evidence.py', 'collect']):
            self.assertEqual(evidence.main(), 0)
        manifest = evidence.load(self.public / 'manifest.json')
        self.assertEqual(manifest['gate'], 'failed')
        self.assertIn('security verdict is recorded separately', self.console.getvalue())
        self.assertIn('code=vulnerabilities_found', self.console.getvalue())
        self.assertNotIn('Trivy evidence operation: passed.', self.console.getvalue())

    def test_high_and_critical_each_fail_but_both_images_and_secret_scans_are_attempted(self):
        for severity in ['HIGH', 'CRITICAL']:
            with self.subTest(severity=severity):
                row = self.vulnerability(severity)
                checked, findings = evidence.check_report({**self.report('php', 'vuln'), 'Results': [{'Class': 'os-pkgs', 'Vulnerabilities': [row]}]},
                    evidence.load(self.private / 'context.json')['images']['php'], 1, 'vuln')
                self.assertEqual(checked['gate'], 'failed')
                self.assertEqual(findings[0]['severity'], severity)
        self.findings[('php', 'vuln')] = [self.vulnerability()]
        outcomes = self.scan_both()
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 0)
        self.assertEqual(outcomes, {'php': 'failure', 'nginx': 'success'})
        scans = [(Path(c[c.index('--input') + 1]).stem, c[c.index('--scanners') + 1]) for c in self.commands if '--scanners' in c]
        self.assertEqual(scans, [('php', 'vuln'), ('php', 'secret'), ('nginx', 'vuln'), ('nginx', 'secret')])
        self.assertEqual(manifest['images']['php']['vulnerabilities']['status'], 'completed')
        self.assertEqual(manifest['images']['php']['vulnerabilities']['finding_count'], 1)
        self.assertTrue((self.public / 'nginx-vulnerabilities.json').exists())

    def test_low_severity_secret_still_fails_without_exporting_match_code_or_path(self):
        self.findings[('nginx', 'secret')] = [{'Severity': 'LOW', 'Match': SENSITIVE, 'Code': {'Lines': [SENSITIVE]}}]
        outcomes = self.scan_both()
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 0)
        self.assertEqual(manifest['images']['nginx']['secrets']['gate'], 'failed')
        self.assertEqual(manifest['images']['nginx']['secrets']['finding_count'], 1)
        self.assertEqual(manifest['gate'], 'failed')
        self.assertEqual(manifest['collection_status'], 'completed')
        self.assertEqual(set(manifest['images']['nginx']['secrets']), {'status', 'gate', 'exit_code', 'finding_count', 'created_at'})

    def test_missing_report_is_interrupted_not_zero_and_other_scans_still_run(self):
        self.behavior[('php', 'vuln')] = 'missing'
        code, manifest = self.collected(self.scan_both())
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['vulnerabilities'], {'status': 'interrupted'})
        self.assertFalse((self.public / 'php-vulnerabilities.json').exists())
        self.assertEqual(manifest['images']['php']['secrets']['status'], 'completed')
        self.assertEqual(manifest['images']['nginx']['vulnerabilities']['status'], 'completed')

    def test_secret_scanner_failure_is_mandatory_even_when_vulnerabilities_are_clean(self):
        self.behavior[('php', 'secret')] = 'missing'
        code, manifest = self.collected(self.scan_both())
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['secrets'], {'status': 'interrupted'})

    def assert_interrupted(self, mode):
        self.behavior[('php', 'vuln')] = mode
        code, manifest = self.collected(self.scan_both())
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['vulnerabilities'], {'status': 'interrupted'})
        self.assertFalse((self.public / 'php-vulnerabilities.json').exists())
        self.assertEqual(manifest['images']['nginx']['vulnerabilities']['status'], 'completed')

    def test_malformed_report_is_not_uploaded(self):
        self.assert_interrupted('malformed')

    def test_wrong_image_report_is_rejected(self):
        self.assert_interrupted('wrong-image')

    def test_wrong_scanner_report_is_rejected(self):
        self.assert_interrupted('wrong-scanner')
        self.assertIn('code=report_scanner_mismatch', self.console.getvalue())

    def test_invalid_trivy_schema_has_specific_safe_diagnostic(self):
        self.assert_interrupted('schema-mismatch')
        self.assertIn('code=invalid_report_schema', self.console.getvalue())

    def test_invalid_package_status_has_specific_diagnostic_without_its_value(self):
        self.findings[('php', 'vuln')] = [self.vulnerability()]
        self.assert_interrupted('invalid-fix-status')
        self.assertIn('code=invalid_fix_status', self.console.getvalue())

    def test_untrusted_exception_and_scope_never_become_diagnostic_content(self):
        self.assertEqual(evidence.diagnostic(evidence.EvidenceError(SENSITIVE)), 'unreliable_evidence')
        evidence.announce(SENSITIVE, SENSITIVE)
        self.assertNotIn(SENSITIVE, self.console.getvalue())
        self.assertIn('[evidence]: code=unreliable_evidence', self.console.getvalue())

    def test_partial_report_cannot_claim_zero_vulnerabilities(self):
        self.assert_interrupted('partial')

    def test_scanner_crash_with_json_is_not_treated_as_completed(self):
        self.assert_interrupted('crash-with-json')

    def test_timeout_does_not_skip_secrets_or_nginx(self):
        self.assert_interrupted('timeout')

    def test_report_with_findings_and_success_exit_cannot_pass(self):
        self.findings[('php', 'vuln')] = [self.vulnerability()]
        self.assert_interrupted('false-success')

    def test_raw_secret_payload_in_vulnerability_report_is_rejected(self):
        raw = self.report('php', 'vuln')
        raw['Results'][0]['Secrets'] = [{'Severity': 'HIGH', 'Match': SENSITIVE}]
        with self.assertRaises(evidence.EvidenceError):
            evidence.check_report(raw, evidence.load(self.private / 'context.json')['images']['php'], 0, 'vuln')

    def test_inconsistent_github_outcome_fails_collection(self):
        outcomes = self.scan_both()
        outcomes['php'] = 'skipped'
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['evidence_error'], 'image_evidence_unreliable')
        self.assertEqual(manifest['images']['php']['vulnerabilities'], {'status': 'interrupted'})
        self.assertFalse((self.public / 'php-vulnerabilities.json').exists())

    def test_unsuccessful_prepare_cannot_reuse_clean_context_and_reports(self):
        outcomes = self.scan_both()
        self.assertEqual(outcomes, {'php': 'success', 'nginx': 'success'})
        for preparation in ('failure', 'skipped', 'cancelled', '', SENSITIVE):
            with self.subTest(preparation=preparation), patch.dict(os.environ, {'PREPARE_OUTCOME': preparation}), patch.object(self, 'public', self.root / ('public-' + (preparation or 'missing'))):
                code, manifest = self.collected(outcomes)
                self.assertEqual(code, 1)
                self.assertEqual(manifest['gate'], 'failed')
                self.assertNotEqual(manifest['preparation'], 'success')
                for image in ('php', 'nginx'):
                    self.assertEqual(manifest['images'][image]['vulnerabilities'], {'status': 'interrupted'})
                    self.assertEqual(manifest['images'][image]['secrets'], {'status': 'interrupted'})
                    self.assertFalse((self.public / (image + '-vulnerabilities.json')).exists())

    def test_failed_prepare_blocks_scan_and_cli_exits_one_without_scanner_execution(self):
        before = len(self.commands)
        with patch.dict(os.environ, {'PREPARE_OUTCOME': 'failure', 'RUNNER_TEMP': str(self.root)}):
            for image in ('php', 'nginx'):
                with self.assertRaises(evidence.EvidenceError):
                    evidence.scan_image(self.private, self.cache, image)
                with patch.object(sys, 'argv', ['evidence.py', 'scan', '--image', image]), redirect_stdout(io.StringIO()):
                    self.assertEqual(evidence.main(), 1)
        self.assertEqual(len(self.commands), before)

    def test_atomic_report_write_failure_does_not_leave_publishable_json(self):
        path = self.root / 'report.json'
        with patch.object(Path, 'replace', side_effect=OSError(SENSITIVE)):
            with self.assertRaises(OSError):
                evidence.write(path, {'status': 'completed'})
        self.assertFalse(path.exists())

    def test_context_missing_does_not_create_empty_success_reports(self):
        with patch.object(evidence, 'load', side_effect=evidence.EvidenceError('report_missing_or_unsafe')):
            self.assertEqual(evidence.collect(self.private, self.cache, self.public, {'php': 'failure', 'nginx': 'failure'}), 1)
        manifest = evidence.load(self.public / 'manifest.json')
        self.assertEqual(manifest['gate'], 'failed')
        self.assertEqual(manifest['images']['php']['vulnerabilities'], {'status': 'interrupted'})
        self.assertFalse((self.public / 'php-vulnerabilities.json').exists())

    def test_no_execution_record_or_skipped_step_cannot_pass(self):
        code, manifest = self.collected({'php': 'skipped', 'nginx': 'skipped'})
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['evidence_error'], 'image_evidence_unreliable')

    def test_changed_database_fails_closed_without_exporting_context_extra_fields(self):
        outcomes = self.scan_both()
        (self.cache / 'db/trivy.db').write_bytes(b'changed snapshot')
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 1)
        self.assertTrue(manifest['errors'])
        self.assertIn('code=database_changed', self.console.getvalue())

    def test_valid_but_different_source_sha_is_rejected_with_specific_code(self):
        outcomes = self.scan_both()
        with patch.dict(os.environ, {'IMAGE_SHA': 'c' * 40}):
            code, manifest = self.collected(outcomes)
        self.assertEqual(code, 1)
        self.assertEqual(manifest['collection_status'], 'failed')
        self.assertEqual(manifest['gate'], 'failed')
        self.assertIn('code=source_sha_mismatch', self.console.getvalue())

    def test_changed_archive_fails_closed(self):
        outcomes = self.scan_both()
        with Path(self.images['php']).open('ab') as stream:
            stream.write(b'changed archive')
        code, manifest = self.collected(outcomes)
        self.assertEqual(code, 1)
        self.assertEqual(manifest['images']['php']['evidence_error'], 'image_evidence_unreliable')

    def test_archive_revision_must_match_checked_out_source(self):
        with self.assertRaises(evidence.EvidenceError):
            evidence.identity(Path(self.images['php']), DIGEST, 'c' * 40)

    def test_unknown_context_fields_are_never_exported(self):
        context = evidence.load(self.private / 'context.json')
        context['private'] = SENSITIVE
        evidence.write(self.private / 'context.json', context)
        code, _ = self.collected(self.scan_both())
        self.assertEqual(code, 0)

    def test_invalid_source_or_missing_context_retains_failure_manifest(self):
        with patch.dict(os.environ, {'IMAGE_SHA': 'bad-sha-' + SENSITIVE}):
            code, manifest = self.collected({'php': 'failure', 'nginx': 'failure'})
        self.assertEqual(code, 1)
        self.assertIsNone(manifest['source'])
        self.assertEqual(manifest['gate'], 'failed')

    def test_expired_database_is_rejected(self):
        expired = dict(self.db, NextUpdate=(self.now - timedelta(seconds=1)).isoformat())
        evidence.write(self.cache / 'db/metadata.json', expired)
        with self.assertRaises(evidence.EvidenceError):
            evidence.scanner(self.cache)

    def test_expired_database_logs_only_normalized_allowlisted_diagnostics(self):
        metadata = dict(self.db, NextUpdate=(self.now - timedelta(seconds=1)).isoformat(),
                        private=SENSITIVE)
        evidence.write(self.cache / 'db/metadata.json', metadata)
        output = io.StringIO()
        with redirect_stdout(output):
            with self.assertRaisesRegex(evidence.EvidenceError, '^database_expired$'):
                evidence.scanner(self.cache)
        prefix = 'Trivy database: '
        diagnostic = json.loads(output.getvalue().removeprefix(prefix))
        self.assertEqual(set(diagnostic), {'UpdatedAt', 'DownloadedAt', 'NextUpdate',
                         'now_utc', 'configured_repository', 'cache_location',
                         'trivy_version', 'db_schema'})
        self.assertEqual(diagnostic['NextUpdate'], metadata['NextUpdate'])
        self.assertGreaterEqual(datetime.fromisoformat(diagnostic['now_utc']),
                                datetime.fromisoformat(diagnostic['NextUpdate']))
        self.assertEqual(diagnostic['configured_repository'], evidence.DB_REPOSITORY)
        self.assertNotIn(SENSITIVE, output.getvalue())
        self.assertNotIn(str(self.root), output.getvalue())

    def test_cache_presence_is_reported_before_download(self):
        prefix = 'Trivy database cache before prepare: '
        first = next(line for line in self.console.getvalue().splitlines() if line.startswith(prefix))
        self.assertFalse(json.loads(first.removeprefix(prefix))['database_present'])
        output = io.StringIO()
        with redirect_stdout(output):
            evidence.prepare(self.root / 'second-private', self.cache)
        state = json.loads(output.getvalue().splitlines()[0].removeprefix(prefix))
        self.assertTrue(state['database_present'])
        self.assertTrue(state['metadata_present'])

    def test_utc_offsets_and_go_nanoseconds_are_interpreted_as_same_instant(self):
        self.assertEqual(evidence.timestamp('2026-10-17T12:00:00.123456789Z'),
                         evidence.timestamp('2026-10-17T14:00:00.123456789+02:00'))
        metadata = dict(self.db, NextUpdate=(self.now + timedelta(hours=1)).astimezone(
            timezone(timedelta(hours=-5))).isoformat())
        evidence.write(self.cache / 'db/metadata.json', metadata)
        self.assertEqual(evidence.scanner(self.cache)['NextUpdate'],
                         (self.now + timedelta(hours=1)).isoformat())

    def test_invalid_timestamp_is_never_echoed_in_diagnostics(self):
        evidence.write(self.cache / 'db/metadata.json', dict(self.db, NextUpdate=SENSITIVE))
        output = io.StringIO()
        with redirect_stdout(output):
            with self.assertRaisesRegex(evidence.EvidenceError, '^invalid_timestamp$'):
                evidence.scanner(self.cache)
        self.assertNotIn(SENSITIVE, output.getvalue())

    def test_incompatible_database_schema_is_rejected(self):
        evidence.write(self.cache / 'db/metadata.json', dict(self.db, Version=3))
        with self.assertRaisesRegex(evidence.EvidenceError, '^unsupported_db_schema$'):
            evidence.scanner(self.cache)

    def test_download_uses_upstream_database_instead_of_default_mirror(self):
        downloads = [command for command in self.commands if '--download-db-only' in command]
        self.assertEqual(len(downloads), 1)
        self.assertEqual(downloads[0][downloads[0].index('--db-repository') + 1],
                         'ghcr.io/aquasecurity/trivy-db:2')
        self.assertEqual(evidence.load(self.private / 'context.json')['scanner'],
                         evidence.scanner(self.cache))

    def test_successful_download_with_expired_metadata_cannot_prepare_evidence(self):
        self.db['NextUpdate'] = (self.now - timedelta(seconds=1)).isoformat()
        # DownloadedAt remains recent: downloading an old snapshot must not
        # extend its validity or allow scans to start.
        with self.assertRaisesRegex(evidence.EvidenceError, '^database_expired$'):
            evidence.prepare(self.root / 'expired-private', self.cache)
        self.assertFalse((self.root / 'expired-private/context.json').exists())
        self.assertFalse(any('--input' in command for command in self.commands))

    def test_database_expiring_after_prepare_blocks_scan_before_execution(self):
        evidence.write(self.cache / 'db/metadata.json',
                       dict(self.db, NextUpdate=(self.now - timedelta(seconds=1)).isoformat()))
        before = len(self.commands)
        with self.assertRaisesRegex(evidence.EvidenceError, '^database_expired$'):
            evidence.scan_image(self.private, self.cache, 'php')
        self.assertEqual(len(self.commands), before)

    def test_expired_primary_falls_back_to_fresh_isolated_cache_and_scans(self):
        self.databases[evidence.DB_REPOSITORY] = dict(
            self.db, NextUpdate=(self.now - timedelta(seconds=1)).isoformat())
        self.commands.clear()
        evidence.prepare(self.root / 'fallback-private', self.cache)
        downloads = [command for command in self.commands if '--download-db-only' in command]
        self.assertEqual(len(downloads), 2)
        self.assertEqual([command[command.index('--db-repository') + 1] for command in downloads],
                         [evidence.DB_REPOSITORY, evidence.DB_FALLBACK_REPOSITORY])
        self.assertNotEqual(downloads[0][downloads[0].index('--cache-dir') + 1],
                            downloads[1][downloads[1].index('--cache-dir') + 1])
        self.assertEqual(evidence.load(self.cache / 'database-source.json'),
                         evidence.DB_FALLBACK_REPOSITORY)
        self.assertEqual(evidence.load(self.cache / 'db/metadata.json'), self.db)
        self.assertEqual(evidence.scanner(self.cache),
                         evidence.load(self.root / 'fallback-private/context.json')['scanner'])
        self.assertEqual(self.scan_both(), {'php': 'success', 'nginx': 'success'})
        self.assertIn(evidence.DB_FALLBACK_REPOSITORY, self.console.getvalue())

    def test_fallback_failure_never_produces_context_or_starts_scan(self):
        expired = dict(self.db, NextUpdate=(self.now - timedelta(seconds=1)).isoformat())
        self.databases[evidence.DB_REPOSITORY] = expired
        for index, fallback in enumerate((expired, None, 'timeout',
                                          dict(self.db, Version=3),
                                          dict(self.db, NextUpdate=SENSITIVE))):
            with self.subTest(fallback=index):
                self.databases[evidence.DB_FALLBACK_REPOSITORY] = fallback
                self.commands.clear()
                private = self.root / ('failed-fallback-' + str(index))
                with self.assertRaisesRegex(evidence.EvidenceError, '^database_expired$'):
                    evidence.prepare(private, self.cache)
                self.assertFalse((private / 'context.json').exists())
                self.assertFalse(any('--input' in command for command in self.commands))
                self.assertEqual(evidence.load(self.cache / 'db/metadata.json'), expired)
                self.assertFalse(list(private.glob('db-fallback-*')))
        self.assertNotIn(SENSITIVE, self.console.getvalue())

    def test_invalid_primary_metadata_does_not_trigger_expiry_fallback(self):
        self.databases[evidence.DB_REPOSITORY] = dict(self.db, NextUpdate=SENSITIVE)
        self.commands.clear()
        with self.assertRaisesRegex(evidence.EvidenceError, '^invalid_timestamp$'):
            evidence.prepare(self.root / 'invalid-private', self.cache)
        self.assertEqual(sum('--download-db-only' in command for command in self.commands), 1)

    def test_duplicate_scan_does_not_reuse_stale_report(self):
        self.assertEqual(evidence.scan_image(self.private, self.cache, 'php'), 0)
        self.assertEqual(evidence.scan_image(self.private, self.cache, 'php'), 1)

    def test_tool_failure_diagnostics_are_not_printed(self):
        with patch.dict(os.environ, {'RUNNER_TEMP': str(self.root)}), patch.object(evidence, 'prepare', side_effect=OSError(SENSITIVE)), patch.object(sys, 'argv', ['evidence.py', 'prepare']):
            output = io.StringIO()
            with redirect_stdout(output):
                self.assertEqual(evidence.main(), 1)
            self.assertNotIn(SENSITIVE, output.getvalue())


class WorkflowContractTest(unittest.TestCase):
    def test_publish_requires_successful_gate_and_build_and_cannot_use_evidence_artifact(self):
        workflow = Path(__file__).resolve().parents[1] / 'workflows/ghcr.yml'
        text = workflow.read_text()
        publish = text.split('\n  publish:\n', 1)[1]
        self.assertIn('    needs: [gate, build]\n', publish)
        self.assertIn("    if: github.event_name == 'workflow_run' && needs.gate.result == 'success' && needs.build.result == 'success'\n", publish)
        self.assertIn('name: ghcr-images-${{ needs.gate.outputs.sha }}', publish)
        self.assertNotIn('trivy-evidence-', publish)
        images = text.split('      - name: Retain only verified images for publishing\n', 1)[1].split('\n  publish:', 1)[0]
        self.assertIn("if: github.event_name == 'workflow_run'", images)
        self.assertNotIn('always()', images)
        self.assertNotIn('!cancelled()', images)

    def test_failed_scan_does_not_skip_nginx_and_evidence_upload_has_explicit_safe_paths(self):
        workflow = Path(__file__).resolve().parents[1] / 'workflows/ghcr.yml'
        text = workflow.read_text()
        nginx = text.split('      - name: Scan Nginx image\n')[1].split('      - name:')[0]
        self.assertIn('!cancelled()', nginx)
        self.assertNotIn('steps.scan_php', nginx)
        self.assertIn('PREPARE_OUTCOME: ${{ steps.prepare_evidence.outcome }}', nginx)
        preparation = text.split('      - name: Prepare trustworthy Trivy evidence context\n')[1].split('      - name:')[0]
        self.assertIn('id: prepare_evidence', preparation)
        collection = text.split('      - name: Validate and collect sanitized Trivy evidence\n')[1].split('      - name:')[0]
        self.assertIn('if: always()', collection)
        self.assertIn('PREPARE_OUTCOME: ${{ steps.prepare_evidence.outcome }}', collection)
        upload = text.split('      - name: Retain Trivy evidence even when scans fail\n')[1].split('      - name:')[0]
        self.assertIn('if: always()', upload)
        self.assertIn('if-no-files-found: error', upload)
        self.assertNotIn('shishli-trivy-private', upload)
        self.assertNotIn('**', upload)
        self.assertEqual(upload.count('${{ runner.temp }}/shishli-trivy-evidence/'), 3)
        self.assertNotIn('continue-on-error', text)
        self.assertIn("needs.build.result == 'success'", text)


if __name__ == '__main__':
    unittest.main()
