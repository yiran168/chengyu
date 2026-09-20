"""Run Playwright with real localhost URL navigation and an isolated PHP backend.
Requires a local Chromium, Playwright, requests and PHP; never uses production.
"""
from pathlib import Path
import json,os,secrets,shutil,socket,subprocess,tempfile,time,sys
import requests
ROOT=Path(__file__).resolve().parents[1]
if (ROOT/'site/app/config.php').exists():raise SystemExit('Refusing an installed source tree')
with tempfile.TemporaryDirectory(prefix='chengyu-live-') as directory:
    work=Path(directory);site=work/'site';shutil.copytree(ROOT/'site',site);private=work/'private';private.mkdir(mode=0o700)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    config={'url':base,'secret':secrets.token_hex(32),'database':{'driver':'sqlite','path':str(private/'site.sqlite')},'storage':str(site/'storage'),'schema_version':1}
    config_file=private/'config.json';config_file.write_text(json.dumps(config));config_file.chmod(0o600)
    env=dict(os.environ,CY_TEST_ROOT=str(site),CY_SNAPSHOT_BASE=base,CY_ADMIN_USER='preview_admin',CY_ADMIN_PASSWORD=secrets.token_urlsafe(24),CY_TEST_IDS=str(private/'visual_ids.json'))
    subprocess.run(['php','-d','opcache.enable=0','-d','ffi.enable=1',str(ROOT/'tests/seed_visual.php'),str(config_file),str(site)],env=env,check=True)
    with (private/'http.log').open('w') as log:
        server=subprocess.Popen(['php','-d','opcache.enable=0','-d','ffi.enable=1','-S','127.0.0.1:'+str(port),str(ROOT/'tests/router.php')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:requests.get(base,timeout=.3).raise_for_status();break
                except requests.RequestException:time.sleep(.05)
            suite=os.environ.get('CY_BROWSER_SUITE','browser_live.py')
            if suite not in ['browser_live.py','browser_024.py']:raise ValueError('Unknown browser suite')
            result=subprocess.run([sys.executable,str(ROOT/'tests'/suite)],env=env)
            code=result.returncode
        finally:
            server.terminate();server.wait(timeout=5)
raise SystemExit(code)
