"""Inspect every saved image layer, including files deleted in later layers.

No Docker daemon or extraction of untrusted tar paths is required.
"""
import json
import re
import sys
import tarfile
from pathlib import PurePosixPath


def inspect(path, kind):
    files = set()
    index = None
    findings = set()
    api_base_present = False
    nginx_config = None
    with tarfile.open(path) as archive:
        manifest = json.load(archive.extractfile('manifest.json'))[0]
        config = json.load(archive.extractfile(manifest['Config']))
        assert config['architecture'] == 'amd64' and config['os'] == 'linux'
        for item in config.get('config', {}).get('Env', []):
            name, _, value = item.partition('=')
            if re.search(r'(PASSWORD|TOKEN|SECRET|APP_KEY|PRIVATE_KEY)', name, re.I) and value:
                findings.add(f'image environment: {name}')
        for layer_name in manifest['Layers']:
            with tarfile.open(fileobj=archive.extractfile(layer_name), mode='r|*') as layer:
                for member in layer:
                    name = member.name.removeprefix('./').lstrip('/')
                    application = name.startswith(('var/www/html/', 'usr/share/nginx/html/')) or name == 'etc/nginx/conf.d/default.conf'
                    if not application:
                        continue
                    base = PurePosixPath(name).name
                    if (base.startswith('.env') or base in ('auth.json', '.npmrc', '.netrc')
                            or re.search(r'\.(sql(?:\..*)?|bak|key|pem|p12|pfx)$', base, re.I)
                            or re.search(r'(^|/)(\.git|\.kilo|\.codex|\.agents|backups?|\.local-mobile-https)(/|$)', name, re.I)
                            or base.startswith(('id_rsa', 'id_ed25519'))):
                        findings.add(f'forbidden application file: {name}')
                    if not member.isfile():
                        continue
                    if base.startswith('.wh.'):
                        files.discard(str(PurePosixPath(name).with_name(base[4:])))
                        continue
                    files.add(name)
                    content = layer.extractfile(member).read()
                    if re.search(rb'-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----', content):
                        findings.add(f'private key content: {name}')
                    if name == 'usr/share/nginx/html/index.html':
                        index = content.decode()
                    if name == 'etc/nginx/conf.d/default.conf':
                        nginx_config = content.decode()
                    if name.startswith('usr/share/nginx/html/assets/') and name.endswith('.js') and b'/api/v1' in content:
                        api_base_present = True
        assert not findings, '\n'.join(sorted(findings))
        if kind == 'app':
            for required in ('artisan', 'public/index.php', 'vendor/autoload.php', 'routes/api.php'):
                assert f'var/www/html/{required}' in files, f'Missing {required}'
        else:
            assert index is not None, 'Missing Vue index.html'
            assert api_base_present, 'Missing same-origin /api/v1 in Vue bundle'
            assert nginx_config and 'location /api/' in nginx_config and 'fastcgi_pass app:9000' in nginx_config
            assert 'SCRIPT_FILENAME /var/www/html/public/index.php' in nginx_config
            assert 'try_files $uri $uri/ /index.html' in nginx_config, 'Missing SPA fallback'
            assets = re.findall(r'(?:src|href)="(/assets/[^"?#]+)', index)
            assert assets, 'No Vue assets in index.html'
            for asset in assets:
                assert 'usr/share/nginx/html' + asset in files, f'Missing Vue asset {asset}'
    print(f'{kind}: linux/amd64; every application layer checked; required paths/assets present; no forbidden files or private keys')


if __name__ == '__main__':
    assert len(sys.argv) == 3, 'Usage: inspect-images.py APP.tar NGINX.tar'
    inspect(sys.argv[1], 'app')
    inspect(sys.argv[2], 'nginx')
