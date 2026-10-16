#!/usr/bin/env python3
"""Hosted-runner-only orchestration. Never forwards subprocess logs to artifacts."""

import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT / 'docker/ci/compose.php-runtime.yaml'
OWNER = 'php-runtime-integration'
SERVICES = {'mysql', 'redis', 'runtime', 'runtime-production'}
CHECKS = (
    'image', 'compose', 'services', 'connections', 'fresh_database', 'migrations',
    'redis_ttl_locks', 'mysql_connection_failure', 'redis_connection_failure',
    'missing_worker', 'queue_effect', 'failed_job', 'missing_scheduler',
    'scheduler_effect', 'scheduler_failure', 'production_entrypoint',
)
ERROR_CODES = {
    'COMMAND_UNAVAILABLE_OR_TIMEOUT', 'COMMAND_FAILED', 'INVALID_MACHINE_OUTPUT',
    'PROBE_CONTRACT_FAILED', 'IMAGE_IDENTITY_MISMATCH', 'INCOMPLETE_SERVICE_INVENTORY',
    'SERVICE_NOT_HEALTHY', 'INVALID_SERVICE_IMAGE', 'RUNTIME_IMAGE_MISMATCH',
    'UNEXPECTED_VOLUME_REFUSE_CLEANUP', 'INVALID_RESOURCE_ID', 'INVALID_RESOURCE_INSPECTION',
    'RESOURCE_IDENTITY_MISMATCH', 'UNEXPECTED_CONTAINER_RESOURCE', 'UNEXPECTED_NETWORK_RESOURCE',
    'RESOURCE_ID_MISMATCH', 'FOREIGN_NETWORK_ENDPOINT', 'INCOMPLETE_TESTS',
    'CLEANUP_MARKER_MISMATCH', 'CLEANUP_INCOMPLETE', 'INTERNAL_ERROR',
    'RESOURCE_ALREADY_EXISTS',
    'REPORT_IDENTITY_MISMATCH',
}


def safe_error(error):
    return str(error) if isinstance(error, CiError) and str(error) in ERROR_CODES else 'INTERNAL_ERROR'


class CiError(Exception):
    """Only fixed, non-sensitive error codes may reach the report."""


def execute(args, *, env=None, timeout=180):
    try:
        return subprocess.run(args, env=env, capture_output=True, text=True,
                              timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise CiError('COMMAND_UNAVAILABLE_OR_TIMEOUT') from None


def require_success(result):
    if result.returncode != 0:
        raise CiError('COMMAND_FAILED')
    return result.stdout


def decode_json(raw):
    try:
        return json.loads(raw)
    except (ValueError, TypeError):
        raise CiError('INVALID_MACHINE_OUTPUT') from None


def expected_probe(result, code=0, marker='CI_PROBE_OK'):
    if result.returncode != code or result.stdout.strip() != marker:
        raise CiError('PROBE_CONTRACT_FAILED')


def hosted_identity(env):
    if (env.get('GITHUB_ACTIONS') != 'true'
            or env.get('RUNNER_ENVIRONMENT') != 'github-hosted'
            or sys.platform != 'linux'):
        raise CiError('HOSTED_RUNNER_REQUIRED')
    run = env.get('GITHUB_RUN_ID', '')
    attempt = env.get('GITHUB_RUN_ATTEMPT', '')
    sha = env.get('GITHUB_SHA', '')
    if not re.fullmatch(r'[1-9][0-9]*', run) or not re.fullmatch(r'[1-9][0-9]*', attempt):
        raise CiError('INVALID_RUN_ID')
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        raise CiError('INVALID_SOURCE_SHA')
    return f'{run}-{attempt}', sha


def write_json(path, data):
    path.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8')
    path.chmod(0o600)


class RuntimeCi:
    def __init__(self, env=None, runner=execute):
        self.env = dict(os.environ if env is None else env)
        self.identity, sha = hosted_identity(self.env)
        self.project = 'shishli-php-ci-' + self.identity
        temp = Path(self.env.get('RUNNER_TEMP', '')).resolve()
        workspace = Path(self.env.get('GITHUB_WORKSPACE', str(ROOT))).resolve()
        if not temp.is_dir() or temp == workspace or workspace in temp.parents:
            raise CiError('UNSAFE_TEMP_DIRECTORY')
        self.directory = temp / self.project
        if self.directory.is_symlink():
            raise CiError('UNSAFE_TEMP_DIRECTORY')
        self.directory.mkdir(mode=0o700, exist_ok=True)
        self.directory.chmod(0o700)
        self.env_file = self.directory / 'runtime.env'
        self.mysql_env_file = self.directory / 'mysql.env'
        self.marker = self.directory / 'initialized.json'
        self.report_file = self.directory / 'report.json'
        self.runner = runner
        self.report = {
            'schema_version': 1, 'source_sha': sha, 'run': self.identity,
            'image_id': None, 'build_digest': None, 'service_images': {},
            'status': 'not_run', 'checks': {name: 'not_run' for name in CHECKS},
            'cleanup': 'not_run', 'error_code': None,
        }
        self.compose_env = dict(self.env, CI_ID=self.identity,
                                CI_IMAGE=f'shishli-php-ci:{self.identity}',
                                CI_ENV_FILE=str(self.env_file),
                                CI_MYSQL_ENV_FILE=str(self.mysql_env_file),
                                CI_FIXTURES=str(ROOT / '.github/fixtures/php-runtime'))

    def command(self, args, timeout=180):
        return self.runner(args, env=self.compose_env, timeout=timeout)

    def compose(self, *args, timeout=180):
        return self.command(['docker', 'compose', '--project-name', self.project,
                             '--file', str(COMPOSE), *args], timeout)

    def fixture(self, *args, extra_env=None):
        options = []
        for key, value in (extra_env or {}).items():
            options.extend(['--env', f'{key}={value}'])
        return self.compose('exec', '-T', '--user', 'www-data', *options,
                            'runtime', 'php', '/ci/probe.php', *args)

    def console(self, *args, extra_env=None):
        options = []
        for key, value in (extra_env or {}).items():
            options.extend(['--env', f'{key}={value}'])
        return self.compose('exec', '-T', '--user', 'www-data', *options,
                            'runtime', 'php', '/ci/console.php', *args)

    def save(self):
        # Deliberately serialize only this allowlisted report, never process output/env.
        write_json(self.report_file, self.report)

    def check(self, name, callback):
        try:
            callback()
        except Exception:
            self.report['checks'][name] = 'failed'
            self.save()
            raise
        self.report['checks'][name] = 'passed'
        self.save()

    def prepare(self):
        if self.marker.exists() or self.env_file.exists() or self.mysql_env_file.exists():
            raise CiError('DUPLICATE_INITIALIZATION')
        database = 'shishli_ci_' + self.identity.replace('-', '_')
        password = secrets.token_hex(24)
        values = {
            'SHISHLI_RUNTIME_CI': '1', 'APP_ENV': 'testing', 'APP_DEBUG': 'false',
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode('ascii'),
            'APP_URL': 'http://runtime', 'LOG_CHANNEL': 'stderr', 'LOG_LEVEL': 'emergency',
            'DB_CONNECTION': 'mysql', 'DB_URL': '', 'DB_HOST': 'mysql', 'DB_PORT': '3306',
            'DB_DATABASE': database, 'DB_USERNAME': 'runtime_ci', 'DB_PASSWORD': password,
            'REDIS_CLIENT': 'phpredis', 'REDIS_HOST': 'redis', 'REDIS_PORT': '6379',
            'REDIS_PASSWORD': '', 'REDIS_URL': '', 'REDIS_DB': '0', 'REDIS_CACHE_DB': '1',
            'REDIS_PREFIX': self.project + ':', 'CACHE_PREFIX': self.project + ':cache:',
            'CACHE_STORE': 'redis', 'SESSION_DRIVER': 'redis', 'QUEUE_CONNECTION': 'redis',
            'QUEUE_FAILED_DRIVER': 'database-uuids', 'MAIL_MAILER': 'array',
            'NOTIFICATION_QUEUE_CONNECTION': 'redis',
        }
        mysql_values = {'MYSQL_DATABASE': database, 'MYSQL_USER': 'runtime_ci',
                        'MYSQL_PASSWORD': password, 'MYSQL_ROOT_PASSWORD': secrets.token_hex(24)}
        # Root credentials are only passed to MySQL, never to the PHP services.
        for path, contents in ((self.env_file, values), (self.mysql_env_file, mysql_values)):
            with path.open('x', encoding='utf-8', newline='\n') as handle:
                path.chmod(0o600)
                handle.write(''.join(f'{key}={value}\n' for key, value in contents.items()))
        write_json(self.marker, {'project': self.project, 'run': self.identity})
        self.save()

    def image_check(self):
        data = decode_json(require_success(self.command([
            'docker', 'image', 'inspect', '--format', '{{json .}}', self.compose_env['CI_IMAGE']])))
        image_id = data.get('Id', '')
        digest = self.env.get('CI_BUILD_DIGEST', '')
        if (not re.fullmatch(r'sha256:[0-9a-f]{64}', image_id)
                or not re.fullmatch(r'sha256:[0-9a-f]{64}', digest)
                or data.get('Architecture') != 'amd64' or data.get('Os') != 'linux'
                or data.get('Config', {}).get('Labels', {}).get('org.opencontainers.image.revision')
                != self.report['source_sha']):
            raise CiError('IMAGE_IDENTITY_MISMATCH')
        self.report['image_id'] = image_id
        self.report['build_digest'] = digest
        # Pin every subsequent Compose invocation to the locally inspected immutable ID.
        self.compose_env['CI_IMAGE'] = image_id

    def service_check(self):
        if self.inventory():
            raise CiError('RESOURCE_ALREADY_EXISTS')
        require_success(self.compose('up', '--detach', '--wait', '--wait-timeout', '240', timeout=300))
        resources = self.inventory()
        containers = [item for kind, item in resources if kind == 'container']
        if {item['Config']['Labels']['com.docker.compose.service'] for item in containers} != SERVICES:
            raise CiError('INCOMPLETE_SERVICE_INVENTORY')
        for item in containers:
            service = item['Config']['Labels']['com.docker.compose.service']
            if item.get('State', {}).get('Health', {}).get('Status') != 'healthy':
                raise CiError('SERVICE_NOT_HEALTHY')
            image = item.get('Image', '')
            if not re.fullmatch(r'sha256:[0-9a-f]{64}', image):
                raise CiError('INVALID_SERVICE_IMAGE')
            self.report['service_images'][service] = image
            if service.startswith('runtime') and image != self.report['image_id']:
                raise CiError('RUNTIME_IMAGE_MISMATCH')

    def inventory(self):
        resources = []
        for kind in ('container', 'network', 'volume'):
            listing = require_success(self.command([
                'docker', kind, 'ls', *(['--all'] if kind == 'container' else []),
                '--quiet', '--filter', f'label=com.docker.compose.project={self.project}']))
            ids = listing.split()
            if kind == 'volume' and ids:
                raise CiError('UNEXPECTED_VOLUME_REFUSE_CLEANUP')
            for resource_id in ids:
                if not re.fullmatch(r'[0-9a-f]{12,64}', resource_id):
                    raise CiError('INVALID_RESOURCE_ID')
                items = decode_json(require_success(self.command(['docker', kind, 'inspect', resource_id])))
                if not isinstance(items, list) or len(items) != 1:
                    raise CiError('INVALID_RESOURCE_INSPECTION')
                item = items[0]
                labels = item.get('Config', {}).get('Labels', {}) if kind == 'container' else item.get('Labels', {})
                if (labels.get('com.docker.compose.project') != self.project
                        or labels.get('io.shishli.ci.owner') != OWNER
                        or labels.get('io.shishli.ci.run') != self.identity):
                    raise CiError('RESOURCE_IDENTITY_MISMATCH')
                if kind == 'container':
                    if (labels.get('com.docker.compose.service') not in SERVICES
                            or any(mount.get('Type') == 'volume' for mount in item.get('Mounts', []))):
                        raise CiError('UNEXPECTED_CONTAINER_RESOURCE')
                elif labels.get('com.docker.compose.network') != 'isolated' or not item.get('Internal'):
                    raise CiError('UNEXPECTED_NETWORK_RESOURCE')
                actual_id = item.get('Id', '')
                if not re.fullmatch(r'[0-9a-f]{64}', actual_id) or not actual_id.startswith(resource_id):
                    raise CiError('RESOURCE_ID_MISMATCH')
                resources.append((kind, item))
        # A foreign container connected to this network must also stop cleanup.
        container_ids = {item['Id'] for kind, item in resources if kind == 'container'}
        for kind, item in resources:
            if kind == 'network' and not set(item.get('Containers', {})).issubset(container_ids):
                raise CiError('FOREIGN_NETWORK_ENDPOINT')
        return resources

    def migrations(self):
        require_success(self.console('migrate', '--force', '--no-interaction'))
        require_success(self.console('migrate', '--force', '--no-interaction'))
        require_success(self.console('migrate:status', '--no-interaction'))
        expected_probe(self.fixture('schema'))

    def worker(self):
        require_success(self.console('queue:work', 'redis', '--queue=runtime-ci', '--stop-when-empty',
                                     '--tries=1', '--timeout=20', '--max-time=40', '--no-interaction'))

    def queue_test(self):
        token = secrets.token_hex(16)
        expected_probe(self.fixture('dispatch', token))
        expected_probe(self.fixture('absent', token))
        # The verifier must reject a pending job when there is no worker.
        self.check('missing_worker', lambda: expected_probe(self.fixture('effect', token, 'queue'), 21, 'CI_EFFECT_MISSING'))
        self.worker()
        expected_probe(self.fixture('effect', token, 'queue'))

    def failed_job_test(self):
        token = secrets.token_hex(16)
        expected_probe(self.fixture('dispatch-failure', token))
        self.worker()
        expected_probe(self.fixture('failed-job', token))

    def scheduler_test(self):
        token = secrets.token_hex(16)
        expected_probe(self.fixture('absent', token))
        require_success(self.console('schedule:run', '--no-interaction'))
        self.check('missing_scheduler', lambda: expected_probe(
            self.fixture('effect', token, 'scheduler'), 21, 'CI_EFFECT_MISSING'))
        require_success(self.console('schedule:run', '--no-interaction', extra_env={'CI_SCHEDULE_TOKEN': token}))
        expected_probe(self.fixture('effect', token, 'scheduler'))

    def scheduler_failure_test(self):
        token = secrets.token_hex(16)
        require_success(self.console('schedule:run', '--no-interaction', extra_env={
            'CI_SCHEDULE_TOKEN': token, 'CI_SCHEDULE_FAIL': '1'}))
        # Laravel catches callback exceptions: the failure event must persist its
        # own marker and the success verifier must still reject this execution.
        expected_probe(self.fixture('scheduler-failed', token))
        expected_probe(self.fixture('effect', token, 'scheduler'), 21, 'CI_EFFECT_MISSING')

    def production_check(self):
        script = ("require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; "
                  "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
                  "exit($app->configurationIsCached() && $app->routesAreCached() "
                  "&& $app->environment('production') && config('database.connections.mysql.host')==='mysql' ? 0 : 1);")
        require_success(self.compose('exec', '-T', '--user', 'www-data', 'runtime-production', 'php', '-r', script))

    def test(self):
        self.prepare()
        self.report['status'] = 'running'
        try:
            self.check('image', self.image_check)
            self.check('compose', lambda: require_success(self.compose('config', '--quiet')))
            self.check('services', self.service_check)
            self.check('connections', lambda: expected_probe(self.fixture('connections')))
            self.check('fresh_database', lambda: expected_probe(self.fixture('fresh')))
            self.check('migrations', self.migrations)
            self.check('redis_ttl_locks', lambda: expected_probe(self.fixture('redis')))
            self.check('mysql_connection_failure', lambda: expected_probe(
                self.fixture('bad-mysql'), 20, 'CI_EXPECTED_CONNECTION_FAILURE'))
            self.check('redis_connection_failure', lambda: expected_probe(
                self.fixture('bad-redis'), 20, 'CI_EXPECTED_CONNECTION_FAILURE'))
            self.check('queue_effect', self.queue_test)
            self.check('failed_job', self.failed_job_test)
            self.check('scheduler_effect', self.scheduler_test)
            self.check('scheduler_failure', self.scheduler_failure_test)
            self.check('production_entrypoint', self.production_check)
            if set(self.report['checks'].values()) != {'passed'}:
                raise CiError('INCOMPLETE_TESTS')
            self.report['status'] = 'passed'
        except Exception as error:
            self.report['status'] = 'failed'
            self.report['error_code'] = safe_error(error)
            raise
        finally:
            self.save()

    def load_report(self):
        if self.report_file.exists():
            data = decode_json(self.report_file.read_text(encoding='utf-8'))
            # Never import arbitrary report fields into the artifact.
            digest = r'sha256:[0-9a-f]{64}'
            if (not isinstance(data, dict) or data.get('run') != self.identity
                    or data.get('source_sha') != self.report['source_sha'] or set(data) != set(self.report)
                    or data.get('schema_version') != 1
                    or data.get('status') not in {'not_run', 'running', 'passed', 'failed'}
                    or data.get('cleanup') not in {'not_run', 'passed', 'failed'}
                    or data.get('error_code') not in ERROR_CODES | {None}
                    or not isinstance(data.get('checks'), dict) or set(data['checks']) != set(CHECKS)
                    or any(value not in {'not_run', 'passed', 'failed'} for value in data['checks'].values())
                    or any(data.get(key) is not None and not re.fullmatch(digest, str(data[key]))
                           for key in ('image_id', 'build_digest'))
                    or not isinstance(data.get('service_images'), dict)
                    or not set(data['service_images']).issubset(SERVICES)
                    or any(not re.fullmatch(digest, str(value)) for value in data['service_images'].values())):
                raise CiError('REPORT_IDENTITY_MISMATCH')
            self.report = data

    def cleanup(self):
        try:
            self.load_report()
            if self.marker.exists():
                if decode_json(self.marker.read_text(encoding='utf-8')) != {'project': self.project, 'run': self.identity}:
                    raise CiError('CLEANUP_MARKER_MISMATCH')
                # Validate the complete inventory before issuing ANY deletion.
                resources = self.inventory()
                for kind in ('container', 'network'):
                    for resource_kind, item in resources:
                        if resource_kind == kind:
                            args = ['docker', kind, 'rm']
                            if kind == 'container':
                                args.append('--force')
                            require_success(self.command([*args, item['Id']]))
                if self.inventory():
                    raise CiError('CLEANUP_INCOMPLETE')
            self.report['cleanup'] = 'passed'
        except Exception as error:
            self.report['cleanup'] = 'failed'
            self.report['error_code'] = safe_error(error)
            raise
        finally:
            # Credentials are never uploaded, including after cleanup failure.
            for path in (self.env_file, self.mysql_env_file):
                if path.exists() and not path.is_symlink():
                    path.unlink()
            self.save()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('test', 'cleanup'))
    arguments = parser.parse_args()
    try:
        ci = RuntimeCi()
        getattr(ci, arguments.operation)()
        print('PHP runtime CI: operation completed; see sanitized report.')
        return 0
    except Exception:
        print('PHP runtime CI: operation failed; see sanitized report.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
