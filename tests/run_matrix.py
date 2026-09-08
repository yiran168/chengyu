"""Run real PHP binaries against native PDO SQLite, capturing exact evidence.
Usage: python tests/run_matrix.py /path/to/runtime /path/to/output /path/to/temp
Runtime contains php-7.4/php[.exe] ... php-8.5/php[.exe]. No downloads are made.
"""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import json, os, subprocess, sys, tempfile, time

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=Path(sys.argv[1]).resolve();OUT=Path(sys.argv[2]).resolve();TMP=Path(sys.argv[3]).resolve()
OUT.mkdir(parents=True,exist_ok=True);TMP.mkdir(parents=True,exist_ok=True)

def run(minor):
    binary=RUNTIME/('php-'+minor)/('php.exe' if os.name=='nt' else 'php')
    started=time.monotonic()
    with tempfile.TemporaryDirectory(prefix='matrix-'+minor+'-',dir=TMP) as tmp:
        env=dict(os.environ,TMP=tmp,TEMP=tmp,TMPDIR=tmp)
        config=binary.parent/'extras/ssl/openssl.cnf'
        if config.exists():env['OPENSSL_CONF']=str(config)
        env['PATH']=str(binary.parent)+os.pathsep+env['PATH']
        check=subprocess.run([str(binary),'-r','exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);'],env=env,capture_output=True,text=True)
        if check.returncode:raise RuntimeError('Native pdo_sqlite required for '+minor)
        done=subprocess.run([str(binary),'tests/run.php'],cwd=ROOT,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=900)
        (OUT/('php-'+minor+'.json')).write_text(done.stdout,encoding='utf-8')
        (OUT/('php-'+minor+'.stderr.txt')).write_text(done.stderr,encoding='utf-8')
        try:data=json.loads(done.stdout)
        except ValueError:data={}
        result={'minor':minor,'php':data.get('php'),'adapter':data.get('adapter'),'exit':done.returncode,'total':data.get('total'),'passed':data.get('passed'),'failed':data.get('failed'),'stderr_clean':not done.stderr.strip(),'seconds':round(time.monotonic()-started,2)}
        result['ok']=done.returncode==0 and bool(data.get('total')) and data.get('failed')==0 and result['stderr_clean']
        print(json.dumps(result),flush=True)
        return result

with ThreadPoolExecutor(max_workers=3) as pool:results=list(pool.map(run,['7.4','8.0','8.1','8.2','8.3','8.4','8.5']))
(OUT/'native-matrix.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
sys.exit(0 if all(r['ok'] for r in results) else 1)
