"""Package a clean source delivery, never an installed production website.
Usage: python tools/package_release.py /absolute/output/directory
The maintained RELEASE.json, reports and manuals must already be up to date.
"""
from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else ROOT.parent
OUT.mkdir(parents=True, exist_ok=True)
if OUT == ROOT or ROOT in OUT.parents:
    raise SystemExit('Output directory must be outside the delivery tree.')
release = json.loads((ROOT / 'RELEASE.json').read_text(encoding='utf-8'))
version = release['version']
if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    raise SystemExit('Invalid release version.')
if (ROOT / 'site/app/config.php').exists():
    raise SystemExit('Refusing to package an installed website configuration.')
EXCLUDE = {'__pycache__', '.git', '.pytest_cache', '.DS_Store', '.work', 'INSTALL_KEY.txt', '.env'}
FONT = {'.woff', '.woff2', '.ttf', '.otf', '.eot'}
def files(base):
    return sorted(p for p in base.rglob('*') if p.is_file() and not any(x in EXCLUDE for x in p.relative_to(base).parts) and p.suffix != '.pyc')
def sha(data):
    return hashlib.sha256(data).hexdigest()
def make_zip(destination, entries):
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path, name in entries:
            z.write(path, name)

allfiles = files(ROOT)
if any(p.is_symlink() for p in ROOT.rglob('*')):
    raise SystemExit('Symlinks are not allowed in a clean release.')
if any(p.suffix.lower() in FONT for p in allfiles):
    raise SystemExit('Font files must not be distributed in this delivery.')
for folder in ('storage', 'uploads'):
    extras = [p for p in files(ROOT / 'site' / folder) if p.name not in ('.htaccess', 'index.php')]
    if extras: raise SystemExit('Runtime data found in ' + folder)
verifier = (ROOT / 'site/install/key.php').read_text(encoding='utf-8')
if not re.search(r"return\s+'';", verifier):
    raise SystemExit('Public release must have an empty installation verifier. Never publish a shared owner key.')
shutil.copy2(ROOT / 'DEPLOY_PREPARE.html', ROOT / 'site/DEPLOY_PREPARE.html')
sitefiles = files(ROOT / 'site')
private_file = ROOT / 'INSTALL_KEY.txt'
if private_file.exists():
    key = private_file.read_text(encoding='utf-8').strip().encode()
    if key and any(key in p.read_bytes() for p in files(ROOT)):
        raise SystemExit('A private owner key appears in a distributable file.')

upload = OUT / ('chengyu-' + version + '-upload.zip')
complete = OUT / ('chengyu-' + version + '-complete.zip')
make_zip(upload, [(p, p.relative_to(ROOT / 'site').as_posix()) for p in sitefiles])
release['upload_archive_bytes'] = upload.stat().st_size
release['complete_archive_size_location'] = 'External package-verification.json (not self-referential metadata).'
(ROOT / 'RELEASE.json').write_text(json.dumps(release, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')
manifest = []
for p in files(ROOT):
    if p.name == 'FILE_MANIFEST.sha256': continue
    manifest.append(sha(p.read_bytes()) + '  ' + p.relative_to(ROOT).as_posix())
(ROOT / 'FILE_MANIFEST.sha256').write_text('\n'.join(manifest) + '\n', encoding='utf-8', newline='\n')
make_zip(complete, [(p, 'chengyu/' + p.relative_to(ROOT).as_posix()) for p in files(ROOT)])

checks = []
def check(name, ok, detail=''):
    checks.append({'test': name, 'ok': bool(ok), 'detail': detail})
with zipfile.ZipFile(upload) as uz, zipfile.ZipFile(complete) as cz:
    check('Upload archive CRC', uz.testzip() is None)
    check('Complete archive CRC', cz.testzip() is None)
    check('Safe relative archive paths', all(not n.startswith(('/', '\\')) and '..' not in Path(n).parts and '\\' not in n for z in (uz, cz) for n in z.namelist()))
    check('Upload has no enclosing folder', 'index.php' in uz.namelist() and 'admin/index.php' in uz.namelist())
    check('Upload contains production protection files', all(n in uz.namelist() for n in ['.htaccess', 'web.config', 'app/.htaccess', 'storage/.htaccess']))
    check('No private installation key in either public archive', 'INSTALL_KEY.txt' not in uz.namelist() and 'chengyu/INSTALL_KEY.txt' not in cz.namelist())
    check('Public package requires a site-specific owner verifier', b"return '';" in uz.read('install/key.php') and 'DEPLOY_PREPARE.html' in uz.namelist())
    check('Installed configuration excluded', 'app/config.php' not in uz.namelist() and 'chengyu/site/app/config.php' not in cz.namelist())
    check('No font or bytecode files', all(Path(n).suffix.lower() not in FONT | {'.pyc'} for z in (uz, cz) for n in z.namelist()))
    check('Complete and upload production bytes match', all(cz.read('chengyu/site/' + n) == uz.read(n) for n in uz.namelist()))
    lines = cz.read('chengyu/FILE_MANIFEST.sha256').decode().splitlines()
    bad = []
    for line in lines:
        expected, rel = line.split('  ', 1)
        if sha(cz.read('chengyu/' + rel)) != expected: bad.append(rel)
    check('Complete manifest hashes match', not bad, str(len(lines)) + ' records; ' + str(bad))
    check('Archive contains no unlisted files', len(cz.namelist()) == len(lines) + 1)
    with tempfile.TemporaryDirectory(prefix='chengyu-release-check-') as tmp:
        uz.extractall(tmp)
        php = sorted(Path(tmp).rglob('*.php'))
        failures = []
        for path in php:
            done = subprocess.run(['php', '-l', str(path)], capture_output=True, text=True)
            if done.returncode: failures.append(str(path.relative_to(tmp)) + ': ' + done.stdout + done.stderr)
        check('Extracted upload PHP syntax', not failures, str(len(php)) + ' files; ' + str(failures))

report = {
    'version': version,
    'archives': [{'name': p.name, 'bytes': p.stat().st_size, 'sha256': sha(p.read_bytes())} for p in (complete, upload)],
    'total': len(checks), 'passed': sum(c['ok'] for c in checks), 'failed': sum(not c['ok'] for c in checks),
    'manifest_records': len(manifest), 'tests': checks,
    'scope': 'Packaging validation only; does not certify hosts, native databases, all PHP runtimes or external merchant services.'
}
report_path = OUT / ('chengyu-' + version + '-package-verification.json')
report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')
for src, suffix in [('docs/FEATURE_MATRIX.md', 'FEATURE_MATRIX.md'), ('docs/TEST_REPORT.md', 'TEST_REPORT.md'), ('docs/UPGRADE.md', 'UPGRADE.md')]:
    shutil.copy2(ROOT / src, OUT / ('chengyu-' + version + '-' + suffix))
artifacts = sorted(OUT.glob('chengyu-' + version + '-*'))
sums_path = OUT / ('chengyu-' + version + '-SHA256SUMS.txt')
sums_path.write_text('\n'.join(sha(p.read_bytes()) + '  ' + p.name for p in artifacts if p.is_file() and p != sums_path) + '\n', encoding='utf-8', newline='\n')
print(json.dumps({k:v for k,v in report.items() if k != 'tests'}, indent=2))
if report['failed']:
    for c in checks:
        if not c['ok']: print(c, file=sys.stderr)
    raise SystemExit(1)
