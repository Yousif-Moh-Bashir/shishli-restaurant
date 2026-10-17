import contextlib
import importlib.util
import io
import json
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('image_inspector', Path(__file__).with_name('inspect-images.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def tar_bytes(entries):
    result = io.BytesIO()
    with tarfile.open(fileobj=result, mode='w') as archive:
        for name, content in entries.items():
            info = tarfile.TarInfo(name)
            info.size = len(content)
            archive.addfile(info, io.BytesIO(content))
    return result.getvalue()


class ImageInspectionTest(unittest.TestCase):
    def check_image(self, extra=None, deleted=None, env=None):
        files = {f'var/www/html/{name}': b'fixture' for name in ['artisan', 'public/index.php', 'vendor/autoload.php', 'routes/api.php']}
        files.update(extra or {})
        archive = {
            'manifest.json': json.dumps([{'Config': 'config.json', 'Layers': ['layer.tar', 'deleted.tar']}]).encode(),
            'config.json': json.dumps({'architecture': 'amd64', 'os': 'linux', 'config': {'Env': env or []}}).encode(),
            'layer.tar': tar_bytes(files), 'deleted.tar': tar_bytes(deleted or {}),
        }
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'image.tar'
            path.write_bytes(tar_bytes(archive))
            with contextlib.redirect_stdout(io.StringIO()):
                module.inspect(path, 'app')

    def test_clean_image(self):
        self.check_image()

    def test_env_removed_in_later_layer_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'forbidden application file'):
            self.check_image({'var/www/html/.env': b'fixture'}, {'var/www/html/.wh..env': b''})

    def test_private_key_in_innocent_filename_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'private key content'):
            self.check_image({'var/www/html/example.txt': b'-----BEGIN PRIVATE KEY-----'})

    def test_backup_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'forbidden application file'):
            self.check_image({'var/www/html/dump.sql.gz': b'fixture'})

    def test_secret_environment_is_rejected(self):
        with self.assertRaisesRegex(AssertionError, 'image environment'):
            self.check_image(env=['APP_KEY=fixture'])


if __name__ == '__main__':
    unittest.main()
