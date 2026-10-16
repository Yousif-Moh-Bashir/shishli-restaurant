"""Regression tests run real Compose config, never containers or a Docker daemon."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('compose_check', Path(__file__).with_name('validate-ghcr-compose.py'))
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)
COMMAND = [os.environ['SHISHLI_TEST_COMPOSE_BINARY']] if os.environ.get('SHISHLI_TEST_COMPOSE_BINARY') else ['docker', 'compose']
TAG = 'sha-' + '0' * 40


class ComposeValidationTest(unittest.TestCase):
    def execute(self, compose_file, image_tag=TAG, fails=False):
        original = subprocess.run
        temporary_paths = []

        def inspect_and_run(command, **kwargs):
            self.assertEqual(command[-2:], ['config', '--quiet'])
            self.assertEqual(command[:len(COMMAND)], COMMAND)
            env = kwargs['env']
            for name in ['SHISHLI_RUNTIME_ENV_FILE', 'SHISHLI_MYSQL_ENV_FILE']:
                path = Path(env[name])
                self.assertTrue(path.is_file())
                self.assertNotIn(validator.ROOT, path.resolve().parents)
                temporary_paths.append(path)
            self.assertFalse(any(name.startswith('COMPOSE_') for name in env))
            temporary_paths.append(Path(command[command.index('--env-file') + 1]))
            return original(command, **kwargs)

        try:
            with patch.object(validator.subprocess, 'run', side_effect=inspect_and_run):
                if fails:
                    with self.assertRaises(subprocess.CalledProcessError):
                        validator.validate(COMMAND, compose_file, image_tag)
                else:
                    validator.validate(COMMAND, compose_file, image_tag)
        finally:
            self.assertEqual(len(temporary_paths), 3)
            for path in temporary_paths:
                self.assertFalse(path.exists(), 'Temporary files must be removed on success and failure')

    def test_real_deployment_model_uses_temporary_files_without_modification(self):
        path = validator.ROOT / 'compose.ghcr.yaml'
        before = path.read_bytes()
        with patch.dict(os.environ, {
            'SHISHLI_RUNTIME_ENV_FILE': '/must-not-read/app.env',
            'SHISHLI_MYSQL_ENV_FILE': '/must-not-read/mysql.env',
            'COMPOSE_ENV_FILES': '/must-not-read/interpolation.env',
            'COMPOSE_FILE': '/must-not-read/compose.yaml',
        }):
            self.execute(path)
        self.assertEqual(before, path.read_bytes())

    def test_missing_required_image_tag_is_rejected_and_files_are_removed(self):
        self.execute(validator.ROOT / 'compose.ghcr.yaml', image_tag='', fails=True)

    def test_invalid_compose_model_is_rejected_and_files_are_removed(self):
        with tempfile.TemporaryDirectory(prefix='shishli-invalid-compose-') as directory:
            path = Path(directory) / 'invalid.yaml'
            path.write_text('services:\n  app:\n    image: nginx\n    depends_on: [missing]\n', encoding='utf-8')
            self.execute(path, fails=True)


if __name__ == '__main__':
    unittest.main()
