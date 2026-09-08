"""Build and remove a disposable local site; run the actual DOM/screenshot suite.
Never use this command with production data or deploy the tests directory.
"""
from pathlib import Path
import json, os, secrets, shutil, socket, subprocess, tempfile, time
import requests

ROOT = Path(__file__).resolve().parents[1]
if (ROOT / 'site/app/config.php').exists():
    raise SystemExit('Use a clean source checkout, not an installed production site.')
with tempfile.TemporaryDirectory(prefix='chengyu-visual-') as directory:
    work = Path(directory)
    site = work / 'site'
    shutil.copytree(ROOT / 'site', site)
    private = work / 'private'
    private.mkdir(mode=0o700)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    config = {'url': base, 'secret': secrets.token_hex(32),
              'database': {'driver': 'sqlite', 'path': str(private / 'site.sqlite')},
              'storage': str(site / 'storage'), 'schema_version': 1}
    config_file = private / 'config.json'
    config_file.write_text(json.dumps(config))
    config_file.chmod(0o600)
    env = dict(os.environ, CY_TEST_ROOT=str(site), CY_SNAPSHOT_BASE=base,
               CY_ADMIN_USER='preview_admin', CY_ADMIN_PASSWORD=secrets.token_urlsafe(24),CY_TEST_IDS=str(private/'visual_ids.json'))
    subprocess.run(['php', '-d', 'ffi.enable=1', str(ROOT / 'tests/seed_visual.php'),
                    str(config_file), str(site)], env=env, check=True)
    with (private / 'http.log').open('w') as log:
        server = subprocess.Popen(['php', '-d', 'opcache.enable=0', '-d', 'ffi.enable=1', '-S',
                                   '127.0.0.1:' + str(port), str(ROOT / 'tests/router.php')],
                                  env=env, stdout=log, stderr=log)
        try:
            ready = False
            for _ in range(100):
                try:
                    requests.get(base, timeout=0.3).raise_for_status()
                    ready = True
                    break
                except requests.RequestException:
                    time.sleep(0.05)
            if not ready:
                raise RuntimeError('Disposable PHP server did not start.')
            result = subprocess.run(['python', str(ROOT / 'tests/visual_snapshots.py')], env=env)
            result_code = result.returncode
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
raise SystemExit(result_code)
