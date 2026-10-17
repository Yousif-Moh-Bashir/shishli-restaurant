"""Run mandatory scans and publish only allowlisted, non-secret evidence."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile

VERSION = '0.69.3'
DB_REPOSITORY = 'ghcr.io/aquasecurity/trivy-db:2'
DB_FALLBACK_REPOSITORY = 'public.ecr.aws/aquasecurity/trivy-db:2'
IMAGES = {'php': '/tmp/app.tar', 'nginx': '/tmp/nginx.tar'}
ALL_SEVERITIES = 'UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL'


class EvidenceError(Exception):
    """Messages are fixed diagnostic codes, never subprocess/report contents."""


FAILURES = (EvidenceError, OSError, ValueError, TypeError, KeyError, IndexError,
            AttributeError, tarfile.TarError, subprocess.SubprocessError)

DIAGNOSTIC_CODES = frozenset('''
invalid_timestamp invalid_source_sha source_sha_mismatch invalid_repository
invalid_run_id invalid_run_attempt unsupported_db_schema database_expired
archive_manifest_missing ambiguous_image_archive archive_config_missing
image_platform_mismatch image_source_mismatch build_digest_missing
scanner_version_mismatch database_update_failed invalid_report_schema
invalid_report_artifact report_image_mismatch report_scanner_mismatch
incomplete_report invalid_result unexpected_secret_payload
unexpected_vulnerability_payload invalid_findings invalid_finding
invalid_severity invalid_vulnerability_id invalid_package invalid_version
invalid_fixed_version invalid_fix_status scan_exit_report_mismatch
prepare_not_successful database_changed archive_changed stale_report
step_outcome_mismatch source_changed image_required report_missing_or_unsafe
invalid_json scanner_timeout invalid_image_archive evidence_io_error
invalid_evidence_structure unreliable_evidence vulnerabilities_found
secrets_found no_findings evidence_collection_failed
evidence_collected_security_blocked evidence_collected
'''.split())


def diagnostic(error):
    if isinstance(error, EvidenceError) and len(error.args) == 1:
        code = error.args[0]
        if isinstance(code, str) and code in DIAGNOSTIC_CODES:
            return code
    if isinstance(error, subprocess.TimeoutExpired):
        return 'scanner_timeout'
    if isinstance(error, tarfile.TarError):
        return 'invalid_image_archive'
    if isinstance(error, OSError):
        return 'evidence_io_error'
    if isinstance(error, (ValueError, TypeError, KeyError, IndexError, AttributeError)):
        return 'invalid_evidence_structure'
    return 'unreliable_evidence'


def announce(scope, code):
    # Only fixed scopes and allowlisted codes; never exception messages/values.
    if not re.fullmatch(r'(?:evidence|prepare|collection|(?:scan|collect)/(?:php|nginx)/(?:vuln|secret))', scope):
        scope = 'evidence'
    if code not in DIAGNOSTIC_CODES:
        code = 'unreliable_evidence'
    print('Trivy evidence [' + scope + ']: code=' + code)


def result_code(state, kind):
    if state['gate'] == 'passed':
        return 'no_findings'
    return 'vulnerabilities_found' if kind == 'vuln' else 'secrets_found'


def require(condition, code):
    if not condition:
        raise EvidenceError(code)


def token(value, pattern, code):
    require(isinstance(value, str) and re.fullmatch(pattern, value) is not None, code)
    return value


def timestamp(value):
    require(isinstance(value, str), 'invalid_timestamp')
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
        require(parsed.tzinfo is not None, 'invalid_timestamp')
        return parsed.astimezone(timezone.utc).isoformat()
    except ValueError:
        raise EvidenceError('invalid_timestamp') from None


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def load(path):
    require(path.is_file() and not path.is_symlink(), 'report_missing_or_unsafe')
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (ValueError, UnicodeError):
        raise EvidenceError('invalid_json') from None


def write(path, data):
    # Never serialize a raw Trivy report, Docker inspect result or process output.
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(data, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    temporary.replace(path)


def invoke(command):
    # Both stdout and stderr may contain secret matches or an image's ENV.
    return subprocess.run(command, capture_output=True, text=True, timeout=600, check=False)


def source():
    sha = token(os.environ.get('IMAGE_SHA'), r'[a-f0-9]{40}', 'invalid_source_sha')
    actual = invoke(['git', 'rev-parse', 'HEAD'])
    require(actual.returncode == 0 and actual.stdout.strip() == sha, 'source_sha_mismatch')
    return {
        'sha': sha,
        'repository': token(os.environ.get('GITHUB_REPOSITORY'), r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', 'invalid_repository'),
        'run_id': token(os.environ.get('GITHUB_RUN_ID'), r'[0-9]+', 'invalid_run_id'),
        'run_attempt': token(os.environ.get('GITHUB_RUN_ATTEMPT'), r'[0-9]+', 'invalid_run_attempt'),
    }


def scanner(cache, repository=None):
    if repository is None:
        marker = cache / 'database-source.json'
        repository = load(marker) if marker.exists() else DB_REPOSITORY
    require(repository in (DB_REPOSITORY, DB_FALLBACK_REPOSITORY), 'database_update_failed')
    metadata = load(cache / 'db' / 'metadata.json')
    require(metadata.get('Version') == 2, 'unsupported_db_schema')
    result = {key: timestamp(metadata.get(key)) for key in ('UpdatedAt', 'DownloadedAt', 'NextUpdate')}
    now = datetime.now(timezone.utc)
    # Only normalized timestamps and constants reach the log, never raw metadata
    # or subprocess output. Log before rejecting an expired database.
    print('Trivy database: ' + json.dumps({
        **result, 'now_utc': now.isoformat(), 'configured_repository': repository,
        'cache_location': 'selected-cache/db',
        'trivy_version': VERSION, 'db_schema': 2,
    }, sort_keys=True))
    require(datetime.fromisoformat(result['NextUpdate']) > now, 'database_expired')
    result.update({'version': VERSION, 'db_schema': 2, 'db_sha256': digest(cache / 'db' / 'trivy.db')})
    return result


def identity(path, build_digest, source_sha):
    # Read only the hash of the config, never export config/ENV or extract files.
    with tarfile.open(path, 'r:*') as archive:
        member = archive.extractfile('manifest.json')
        require(member is not None, 'archive_manifest_missing')
        manifests = json.load(member)
        require(isinstance(manifests, list) and len(manifests) == 1, 'ambiguous_image_archive')
        config = archive.extractfile(manifests[0]['Config'])
        require(config is not None, 'archive_config_missing')
        config_bytes = config.read()
        config_digest = 'sha256:' + hashlib.sha256(config_bytes).hexdigest()
        parsed = json.loads(config_bytes)
        require(parsed.get('os') == 'linux' and parsed.get('architecture') == 'amd64', 'image_platform_mismatch')
        require(parsed.get('config', {}).get('Labels', {}).get('org.opencontainers.image.revision') == source_sha, 'image_source_mismatch')
    return {
        'archive_sha256': digest(path), 'config_digest': config_digest,
        'build_digest': token(build_digest, r'sha256:[a-f0-9]{64}', 'build_digest_missing'),
    }


def prepare(private, cache):
    private.mkdir(mode=0o700, parents=True, exist_ok=False)
    version = invoke(['trivy', '--version'])
    require(version.returncode == 0 and version.stdout.splitlines()[0] == 'Version: ' + VERSION, 'scanner_version_mismatch')
    # --download-db-only still consults Trivy's local freshness policy. A zero
    # exit code alone does not establish that a new database was downloaded.
    print('Trivy database cache before prepare: ' + json.dumps({
        'database_present': (cache / 'db' / 'trivy.db').exists(),
        'metadata_present': (cache / 'db' / 'metadata.json').exists(),
        'configured_repository': DB_REPOSITORY,
        'cache_location': '$RUNNER_TEMP/shishli-trivy-cache/db',
    }, sort_keys=True))
    require(invoke(['trivy', 'image', '--cache-dir', str(cache), '--db-repository',
                    DB_REPOSITORY, '--download-db-only']).returncode == 0, 'database_update_failed')
    try:
        scanner(cache, DB_REPOSITORY)
    except EvidenceError as error:
        if error.args != ('database_expired',):
            raise
        announce('prepare', 'database_expired')
        # A fresh, isolated cache prevents Trivy's one-hour DownloadedAt policy
        # from reusing the expired primary snapshot on the fallback attempt.
        with tempfile.TemporaryDirectory(prefix='db-fallback-', dir=private) as directory:
            fallback = Path(directory)
            try:
                require(invoke(['trivy', 'image', '--cache-dir', str(fallback),
                                '--db-repository', DB_FALLBACK_REPOSITORY,
                                '--download-db-only']).returncode == 0, 'database_update_failed')
                scanner(fallback, DB_FALLBACK_REPOSITORY)
            except FAILURES as failure:
                announce('prepare', diagnostic(failure))
                raise EvidenceError('database_expired') from None
            # Promote only a validated snapshot; scan/collect retain their
            # existing freshness and database-hash checks on the stable cache.
            for filename in ('trivy.db', 'metadata.json'):
                (fallback / 'db' / filename).replace(cache / 'db' / filename)
        write(cache / 'database-source.json', DB_FALLBACK_REPOSITORY)
    else:
        write(cache / 'database-source.json', DB_REPOSITORY)
    context = {'source': source(), 'scanner': scanner(cache), 'images': {}}
    for name, path in IMAGES.items():
        context['images'][name] = identity(Path(path), os.environ.get(name.upper() + '_BUILD_DIGEST'), context['source']['sha'])
    write(private / 'context.json', context)


def check_report(raw, image, returncode, kind):
    require(isinstance(raw, dict) and raw.get('SchemaVersion') == 2, 'invalid_report_schema')
    require(raw.get('ArtifactType') == 'container_image', 'invalid_report_artifact')
    require(isinstance(raw.get('Metadata'), dict) and raw['Metadata'].get('ImageID') == image['config_digest'], 'report_image_mismatch')
    require(isinstance(raw.get('Trivy'), dict) and raw['Trivy'].get('Version') == VERSION, 'report_scanner_mismatch')
    created_at = timestamp(raw.get('CreatedAt'))
    results = raw.get('Results')
    if kind == 'secret' and results is None:
        results = []  # Trivy omits empty secret-only results.
    require(isinstance(results, list) and (kind == 'secret' or any(isinstance(r, dict) and r.get('Class') == 'os-pkgs' for r in results)), 'incomplete_report')
    findings = []
    count = 0
    for result in results:
        require(isinstance(result, dict), 'invalid_result')
        if kind == 'vuln':
            require(not result.get('Secrets'), 'unexpected_secret_payload')
            items = result.get('Vulnerabilities', [])
        else:
            require(not result.get('Vulnerabilities'), 'unexpected_vulnerability_payload')
            items = result.get('Secrets', [])
        if items is None:
            items = []
        require(isinstance(items, list), 'invalid_findings')
        for item in items:
            require(isinstance(item, dict), 'invalid_finding')
            severity = item.get('Severity')
            require(severity in (('HIGH', 'CRITICAL') if kind == 'vuln' else ALL_SEVERITIES.split(',')), 'invalid_severity')
            count += 1
            if kind == 'vuln':
                # Deliberately omit Target, paths, descriptions, Code, Match, ENV,
                # references and all unknown fields, including nested metadata.
                findings.append({
                    'id': token(item.get('VulnerabilityID'), r'(?:CVE-[0-9]{4}-[0-9]{4,}|GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})', 'invalid_vulnerability_id'),
                    'package': token(item.get('PkgName'), r'[A-Za-z0-9@_.+:/-]{1,200}', 'invalid_package'),
                    'installed_version': token(item.get('InstalledVersion'), r'[A-Za-z0-9_.+~:!/-]{1,200}', 'invalid_version'),
                    'fixed_version': token(item.get('FixedVersion') or '', r'[A-Za-z0-9_.+~:!/,<>= -]{0,300}', 'invalid_fixed_version'),
                    'severity': severity,
                    'status': token(item.get('Status', 'unknown'), r'(?:unknown|not_affected|affected|fixed|under_investigation|will_not_fix|fix_deferred|end_of_life)', 'invalid_fix_status'),
                })
    require(type(returncode) is int and returncode == (1 if count else 0), 'scan_exit_report_mismatch')
    return {'status': 'completed', 'gate': 'failed' if count else 'passed', 'exit_code': returncode,
            'finding_count': count, 'created_at': created_at}, findings


def scan_image(private, cache, name):
    require(os.environ.get('PREPARE_OUTCOME') == 'success', 'prepare_not_successful')
    context = load(private / 'context.json')
    require(scanner(cache) == context['scanner'], 'database_changed')
    image = identity(Path(IMAGES[name]), context['images'][name]['build_digest'], context['source']['sha'])
    require(image == context['images'][name], 'archive_changed')
    executions = {}
    failed = False
    # No short-circuit: secret scanning still runs after a vulnerability failure.
    for kind in ('vuln', 'secret'):
        report = private / (name + '-' + kind + '.json')
        execution = {'returncode': None}
        try:
            require(not report.exists(), 'stale_report')
            command = ['trivy', 'image', '--cache-dir', str(cache), '--skip-db-update', '--input', IMAGES[name],
                       '--scanners', kind, '--severity', 'HIGH,CRITICAL' if kind == 'vuln' else ALL_SEVERITIES,
                       '--ignore-unfixed=false', '--exit-code', '1', '--format', 'json', '--output', str(report)]
            execution['returncode'] = invoke(command).returncode
            checked, _ = check_report(load(report), image, execution['returncode'], kind)
            announce('scan/' + name + '/' + kind, result_code(checked, kind))
            failed = failed or checked['gate'] == 'failed'
        except FAILURES as error:
            announce('scan/' + name + '/' + kind, diagnostic(error))
            failed = True
        executions[kind] = execution
    write(private / (name + '-execution.json'), executions)
    return 1 if failed else 0


def collect(private, cache, public, outcomes):
    public.mkdir(parents=True, exist_ok=False)
    preparation = os.environ.get('PREPARE_OUTCOME')
    if preparation not in ('success', 'failure', 'skipped', 'cancelled'):
        preparation = 'unknown'
    manifest = {'schema_version': 1, 'preparation': preparation, 'source': None, 'scanner': None, 'images': {
        name: {'identity': None, 'vulnerabilities': {'status': 'interrupted'}, 'secrets': {'status': 'interrupted'}}
        for name in IMAGES
    }, 'errors': [], 'diagnostics': []}
    unreliable = False
    blocked = False

    def record(scope, code):
        manifest['diagnostics'].append({'scope': scope, 'code': code})
        announce(scope, code)

    try:
        require(preparation == 'success', 'prepare_not_successful')
        context = load(private / 'context.json')
        manifest['source'] = source()
        require(manifest['source'] == context['source'], 'source_changed')
        manifest['scanner'] = scanner(cache)
        require(manifest['scanner'] == context['scanner'], 'database_changed')
        for name in IMAGES:
            entry = {'identity': None, 'vulnerabilities': {'status': 'interrupted'}, 'secrets': {'status': 'interrupted'}}
            manifest['images'][name] = entry
            try:
                image = identity(Path(IMAGES[name]), context['images'][name]['build_digest'], manifest['source']['sha'])
                require(image == context['images'][name], 'archive_changed')
                entry['identity'] = image
                executions = load(private / (name + '-execution.json'))
                report = None
                for kind, label in [('vuln', 'vulnerabilities'), ('secret', 'secrets')]:
                    try:
                        state, findings = check_report(load(private / (name + '-' + kind + '.json')), image, executions[kind]['returncode'], kind)
                        entry[label] = state
                        record('collect/' + name + '/' + kind, result_code(state, kind))
                        if kind == 'vuln':
                            report = {
                                'schema_version': 1, 'image': name, 'source_sha': manifest['source']['sha'],
                                'config_digest': image['config_digest'], 'scan': state, 'findings': findings,
                            }
                    except FAILURES as error:
                        record('collect/' + name + '/' + kind, diagnostic(error))
                        unreliable = True
                clean = all(entry[label].get('gate') == 'passed' for label in ('vulnerabilities', 'secrets'))
                expected = 'success' if clean else 'failure'
                require(outcomes[name] == expected, 'step_outcome_mismatch')
                if report is not None:
                    write(public / (name + '-vulnerabilities.json'), report)
                blocked = blocked or not clean
            except FAILURES as error:
                record('collection', diagnostic(error))
                entry['vulnerabilities'] = {'status': 'interrupted'}
                entry['secrets'] = {'status': 'interrupted'}
                entry['evidence_error'] = 'image_evidence_unreliable'
                unreliable = True
    except FAILURES as error:
        record('collection', diagnostic(error))
        manifest['errors'].append('source_or_scanner_evidence_unavailable')
        unreliable = True
    # Collection success never erases a failed mandatory scan step. The build
    # and publish gates still fail; this exit code describes evidence integrity.
    manifest['gate'] = 'failed' if unreliable or blocked else 'passed'
    manifest['collection_status'] = 'failed' if unreliable else 'completed'
    write(public / 'manifest.json', manifest)
    announce('collection', 'evidence_collection_failed' if unreliable else
             'evidence_collected_security_blocked' if blocked else 'evidence_collected')
    return 1 if unreliable else 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=['prepare', 'scan', 'collect'])
    parser.add_argument('--image', choices=list(IMAGES))
    args = parser.parse_args()
    root = Path(os.environ['RUNNER_TEMP'])
    private, cache, public = root / 'shishli-trivy-private', root / 'shishli-trivy-cache', root / 'shishli-trivy-evidence'
    try:
        if args.operation == 'prepare':
            prepare(private, cache)
            code = 0
        elif args.operation == 'scan':
            require(args.image is not None, 'image_required')
            code = scan_image(private, cache, args.image)
        else:
            code = collect(private, cache, public, {'php': os.environ.get('PHP_SCAN_OUTCOME'), 'nginx': os.environ.get('NGINX_SCAN_OUTCOME')})
    except FAILURES as error:
        # Do not expose exception text, raw scanner diagnostics or report content.
        announce('evidence', diagnostic(error))
        print('Trivy evidence operation failed: trustworthy evidence could not be produced.')
        return 1
    if args.operation == 'collect' and code == 0:
        print('Trivy evidence collection completed; security verdict is recorded separately in manifest.json.')
    else:
        print('Trivy evidence operation: ' + ('passed.' if code == 0 else 'failed; review sanitized evidence.'))
    return code


if __name__ == '__main__':
    raise SystemExit(main())
