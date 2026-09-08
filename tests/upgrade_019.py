"""Upgrade the original 0.18 code's isolated database using current 0.19 code.
Usage: python tests/upgrade_019.py OLD_SITE RUNTIME TEMP OUT_JSON
No production config or database is read or modified.
"""
from pathlib import Path
import json,os,secrets,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1];old=Path(sys.argv[1]).resolve();runtime=Path(sys.argv[2]).resolve();temp=Path(sys.argv[3]).resolve();out=Path(sys.argv[4]).resolve();results=[]
for version in ['7.4','8.5']:
    binary=runtime/('php-'+version)/('php.exe' if os.name=='nt' else 'php')
    with tempfile.TemporaryDirectory(prefix='upgrade019-',dir=temp) as directory:
        private=Path(directory);(private/'storage').mkdir()
        config={'url':'https://upgrade.example.test','secret':secrets.token_hex(32),'database':{'driver':'sqlite','path':str(private/'site.sqlite')},'storage':str(private/'storage'),'schema_version':1}
        (private/'config.json').write_text(json.dumps(config),encoding='utf-8')
        phases=[]
        for phase,site in [('seed',old),('upgrade',root/'site')]:
            done=subprocess.run([str(binary),str(root/'tests/upgrade_019.php'),str(site),phase,str(private)],capture_output=True,text=True,encoding='utf-8',timeout=120)
            if done.returncode or done.stderr.strip():raise RuntimeError(done.stdout+done.stderr)
            phases.append(json.loads(done.stdout))
        assert phases[0]['schema']==10 and phases[1]['version']=='0.19.0'
        results.append({'php_minor':version,'native_pdo':'sqlite','ok':True,'phases':phases})
out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(results,indent=2),encoding='utf-8');print(json.dumps(results,indent=2))
