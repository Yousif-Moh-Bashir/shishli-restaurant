"""Validate only the deployment model, using disposable non-secret env files."""
import argparse
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def validate(command, compose_file, image_tag):
    # Do not inherit Compose overrides or load a developer's repository .env.
    env = {key: value for key, value in os.environ.items() if not key.startswith('COMPOSE_')}
    with tempfile.TemporaryDirectory(prefix='shishli-compose-check-') as directory:
        temporary = Path(directory)
        app = temporary / 'app.env'
        mysql = temporary / 'mysql.env'
        interpolation = temporary / 'interpolation.env'
        app.write_text('APP_ENV=testing\nAPP_DEBUG=false\n', encoding='utf-8')
        mysql.write_text('MYSQL_DATABASE=shishli_compose_validation\n', encoding='utf-8')
        interpolation.write_text('', encoding='utf-8')
        env.update({
            'GHCR_IMAGE_TAG': image_tag,
            'SHISHLI_RUNTIME_ENV_FILE': str(app),
            'SHISHLI_MYSQL_ENV_FILE': str(mysql),
            'SHISHLI_GHCR_HTTP_PORT': '8089',
        })
        # --quiet suppresses the rendered environment; config never starts services.
        # Real env files are necessary with Compose 2.38.2, even with
        # --no-env-resolution, because its initial project loader resolves them.
        return subprocess.run(
            [*command, '--env-file', str(interpolation), '-f', str(compose_file),
             'config', '--quiet'],
            env=env, check=True, capture_output=True, text=True,
        )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compose-binary', help='Standalone Compose executable for compatibility checks')
    args = parser.parse_args()
    command = [args.compose_binary] if args.compose_binary else ['docker', 'compose']
    subprocess.run([*command, 'version'], check=True)
    try:
        validate(command, ROOT / 'compose.ghcr.yaml', os.environ.get('GHCR_IMAGE_TAG', ''))
    except subprocess.CalledProcessError as error:
        # Do not print rendered configuration or inherited environment values.
        raise SystemExit(f'Compose model validation failed (exit {error.returncode}).') from None
    print('Deployment Compose model validated with temporary non-secret environment files.')


if __name__ == '__main__':
    main()
