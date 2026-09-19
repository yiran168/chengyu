"""Disposable HTTP smoke suite. No production host, DB or merchant is contacted."""
from pathlib import Path
from bs4 import BeautifulSoup
from smtp_fixture import SmtpFixture
import base64, hashlib, json, os, secrets, shutil, socket, subprocess, tempfile, time
import requests
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
work=Path(tempfile.mkdtemp(prefix='chengyu-http-'))
site=work/'site'; shutil.copytree(ROOT/'site',site); (work/'private').mkdir()
assert not (site/'app/config.php').exists(), 'Source package must not include an installed config'
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
base=f'http://127.0.0.1:{port}'
log=(work/'server.log').open('w+')
env=dict(os.environ,CY_TEST_ROOT=str(site))
smtp=SmtpFixture(work/'private')
server=subprocess.Popen(['php','-d','opcache.enable=0','-d','ffi.enable=1','-d','openssl.cafile='+str(smtp.cert),'-S',f'127.0.0.1:{port}',str(ROOT/'tests/router.php')],env=env,stdout=log,stderr=log)
results=[]
runtime={}
probe=site/'runtime-fixture232.php'
probe.write_text('<?php header("Content-Type: application/json"); echo json_encode(["php"=>PHP_VERSION,"fileinfo"=>extension_loaded("fileinfo"),"finfo"=>class_exists("finfo"),"gd"=>extension_loaded("gd"),"zip"=>extension_loaded("zip")]);')
def check(name,ok,detail=''):
    if os.environ.get('CY_HTTP_TRACE'):
        import sys
        print(('PASS ' if ok else 'FAIL ')+name,file=sys.stderr,flush=True)
    results.append({'test':name,'ok':bool(ok),**({'detail':str(detail)[:300]} if not ok else {})})
def html(session,path='/'):
    return BeautifulSoup(session.get(base+path).text,'html.parser')
def csrf(session):
    soup=html(session)
    return soup.select_one('meta[name=csrf-token]')['content']
def post(session,action,**data):
    return session.post(base+'/action.php',data={'action':action,'csrf':csrf(session),'_back':'/',**data},headers={'Accept':'application/json'})
def expect_post(session,action,expected=200,**data):
    r=post(session,action,**data); check('action '+action+' '+str(data.get('kind','')),r.status_code==expected,r.text); return r
try:
    for _ in range(100):
        try:
            requests.get(base+'/install/',timeout=.2); break
        except requests.RequestException: time.sleep(.05)
    runtime=requests.get(base+'/runtime-fixture232.php').json();probe.unlink()
    if os.environ.get('CY_EXPECT_NO_FILEINFO')=='1':check('0232 actual web PHP has neither Fileinfo nor finfo',runtime['fileinfo'] is False and runtime['finfo'] is False,runtime)
    guest=requests.Session(); admin=requests.Session(); user=requests.Session(); other=requests.Session()
    install=admin.get(base+'/install/'); soup=BeautifulSoup(install.text,'html.parser'); token=soup.select_one('input[name=csrf]')['value']
    check('0232 missing verifier has specific preparation advice','DEPLOY_PREPARE.html' in install.text and soup.select_one('form button[type=submit]') is None)
    payload={'csrf':token,'install_key':'wrong','site_name':'Chengyu HTTP test','site_url':base,'driver':'sqlite','sqlite_path':str(work/'private/site.sqlite'),'username':'admin','email':'admin@example.test','password':'TestPassword!2026','examples':'1'}
    admin.post(base+'/install/',data=payload)
    check('public package cannot initialize without its own verifier',not(site/'app/config.php').exists())
    prepare=subprocess.run(['php',str(ROOT/'tools/prepare_install.php'),str(site)],capture_output=True,text=True)
    check('CLI creates a site-specific owner key',prepare.returncode==0,prepare.stderr)
    install_secret=(work/'INSTALL_KEY.txt').read_text().strip()
    check('CLI owner key is private, random and not printed',len(install_secret)==64 and install_secret not in prepare.stdout and not(site/'INSTALL_KEY.txt').exists())
    retry_prepare=subprocess.run(['php',str(ROOT/'tools/prepare_install.php'),str(site)],capture_output=True,text=True)
    check('CLI will not overwrite an existing owner verifier',retry_prepare.returncode!=0 and (work/'INSTALL_KEY.txt').read_text().strip()==install_secret)
    admin.post(base+'/install/',data=payload)
    check('installer rejects incorrect configured owner key',not(site/'app/config.php').exists())
    ready232=html(admin,'/install/')
    check('0232 installation submit is available without Fileinfo',ready232.select_one('form button[type=submit]') is not None and 'Fileinfo' in ready232.get_text())
    payload['install_key']=install_secret
    r=admin.post(base+'/install/',data=payload)
    check('installer creates configuration and schema',(site/'app/config.php').exists(),r.text)
    r=admin.get(base+'/install/',allow_redirects=False)
    check('installer locked after installation',r.status_code==303)
    for route in ['', 'index.php?r=articles','index.php?r=forum','index.php?r=shop','index.php?r=membership','index.php?r=article&id=1','index.php?r=article&id=4','index.php?r=product&id=9','index.php?r=register','index.php?r=login','index.php?r=terms','index.php?r=rankings','index.php?r=user&id=1','index.php?r=search&q=does-not-exist','admin/']:
        r=guest.get(base+'/'+route);check('anonymous GET /'+route,r.status_code==200,r.text[:200])
    for asset in ['app.css','app.js','boot.js','favicon.svg']+[f'cover-{i}.svg' for i in range(1,7)]:
        r=guest.get(base+'/assets/'+asset);check('asset '+asset,r.status_code==200 and len(r.content)>100)
    for route in ['feed.php','sitemap.php']:
        r=guest.get(base+'/'+route)
        try: ET.fromstring(r.content);valid=True
        except ET.ParseError:valid=False
        check('valid XML '+route,r.status_code==200 and valid,r.text[:200])
    check('unauthenticated private page redirects to login','r=login' in guest.get(base+'/index.php?r=wallet').url)
    r=guest.post(base+'/action.php',data={'action':'login','identifier':'admin','password':'TestPassword!2026'},headers={'Accept':'application/json'})
    check('POST missing CSRF rejected',r.status_code==419,r.text)
    expect_post(admin,'login',401,identifier='admin',password='bad')
    expect_post(admin,'login',identifier='admin',password='TestPassword!2026',admin_login='1')
    tabs=['dashboard','contents','users','orders','settings','categories','plans','coupons','navigation','vouchers','stock','moderation','ledger','media','system','withdrawals','logs','edit_content']
    for tab in tabs:
        r=admin.get(base+'/admin/index.php',params={'tab':tab});check('admin tab '+tab,r.status_code==200,r.text[:100])
    for group in ['site','appearance','layout','modules','community','commerce','epay','codepay','email','security','seo']:
        r=admin.get(base+'/admin/index.php',params={'tab':'settings','group':group});check('settings group '+group,r.status_code==200,r.text[:100])
    # Preserve every existing setting when posting the group, just like the real browser form.
    page=html(admin,'/admin/index.php?tab=settings&group=appearance');form=page.select_one('input[name=action][value=admin_settings]').find_parent('form');values={}
    for x in form.select('input[name],select[name],textarea[name]'):
        if x.name=='select':sel=x.select_one('option[selected]') or x.select_one('option'); values[x['name']]=sel['value']
        elif x.name=='textarea':values[x['name']]=x.get_text()
        elif x.get('type')!='checkbox' or x.has_attr('checked'):values[x['name']]=x.get('value','')
    values.update(theme='dusk',glass='prism',motion_curve='snappy')
    r=admin.post(base+'/action.php',data=values,headers={'Accept':'application/json'});check('save appearance group',r.status_code==200,r.text)
    home=html(guest);check('backend theme affects frontend',home.html.get('data-theme')=='dusk' and home.html.get('data-glass')=='prism')
    expect_post(user,'register',username='alice',email='alice@example.test',display_name='Alice',password='TestPassword!2026',agree='1')
    expect_post(other,'register',username='bob',email='bob@example.test',display_name='Bob',password='TestPassword!2026',agree='1')
    r=user.get(base+'/admin/');check('ordinary user blocked from admin',r.status_code==403)
    expect_post(user,'admin_settings',403,group='site',site_name='hijacked')
    expect_post(user,'checkin');expect_post(user,'checkin',409)
    for route in ['profile','wallet','orders','favorites','notifications','messages','write','cart']:
        r=user.get(base+'/index.php',params={'r':route});check('authenticated route '+route,r.status_code==200,r.text[:100])
    # Admin creates a real private text attachment and a paid content item.
    r=admin.post(base+'/action.php',data={'action':'upload','csrf':csrf(admin),'private':'1'},files={'file':('resource.txt',b'PRIVATE-BYTES-HTTP-TEST','text/plain')},headers={'Accept':'application/json'})
    check('private resource upload',r.status_code==200,r.text);mid=r.json()['media_id']
    r=post(admin,'admin_content',kind='article',title='Private HTTP resource',body='PUBLIC-MARKER',protected_body='PROTECTED-MARKER',status='published',access_level='paid',price_currency='points',price='10',resource_id=mid,comment_enabled='1')
    check('publish real paid content',r.status_code==200,r.text)
    from urllib.parse import urlparse,parse_qs
    cid=parse_qs(urlparse(r.json()['redirect']).query)['id'][0]
    page=guest.get(base+f'/index.php?r=article&id={cid}')
    check('protected body excluded from guest HTML','PROTECTED-MARKER' not in page.text and 'PUBLIC-MARKER' in page.text)
    for who,label in [(guest,'guest'),(user,'unpaid user')]:
        r=who.get(base+f'/media.php?id={mid}');check(label+' cannot download private attachment',r.status_code in (401,403),r.text[:100])
    expect_post(user,'buy',kind='content',item_id=cid,intent=os.urandom(16).hex())
    check('buyer sees protected body','PROTECTED-MARKER' in user.get(base+f'/index.php?r=article&id={cid}').text)
    check('buyer receives private file',user.get(base+f'/media.php?id={mid}').content==b'PRIVATE-BYTES-HTTP-TEST')
    check('other user cannot borrow entitlement',other.get(base+f'/media.php?id={mid}').status_code==403)
    r=user.post(base+'/action.php',data={'action':'upload','csrf':csrf(user)},files={'file':('x.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml')},headers={'Accept':'application/json'})
    check('SVG upload blocked',r.status_code==400,r.text)
    # Minimal valid GIF is an actual image, not a declared MIME type test.
    gif=base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
    r=user.post(base+'/action.php',data={'action':'upload','csrf':csrf(user)},files={'file':('avatar.gif',gif,'image/gif')},headers={'Accept':'application/json'})
    check('member image upload',r.status_code==200,r.text)
    if r.status_code==200:check('public image served byte for byte',guest.get(base+'/media.php?id='+str(r.json()['media_id'])).content==gif)
    expect_post(user,'comment',content_id=1,body='HTTP comment pending')
    expect_post(user,'reaction',content_id=1,kind='favorite')
    expect_post(user,'follow',target_id=3)
    expect_post(user,'message',recipient='bob',body='PRIVATE-MESSAGE-HTTP')
    check('recipient sees private message','PRIVATE-MESSAGE-HTTP' in other.get(base+'/index.php?r=messages').text)
    check('unrelated admin messages page does not leak private message','PRIVATE-MESSAGE-HTTP' not in admin.get(base+'/index.php?r=messages').text)
    expect_post(user,'report',content_id=1,reason='Test report')
    expect_post(user,'cart_add',item_id=9,quantity=1)
    expect_post(user,'cart_checkout',intent=os.urandom(16).hex())
    expect_post(user,'address_save',recipient='Alice',phone='000',address='TEST-ADDRESS')
    check('address in owner profile','TEST-ADDRESS' in user.get(base+'/index.php?r=profile').text)
    # Untrusted return-page parameters never credit balances or mark orders paid.
    before=html(user,'/index.php?r=wallet').get_text()
    user.get(base+'/index.php?r=orders&trade_status=TRADE_SUCCESS&money=99999')
    after=html(user,'/index.php?r=wallet').get_text()
    check('return URL cannot credit money',before==after)
    r=guest.get(base+'/notify.php?gateway=epay&out_trade_no=fake&sign='+('0'*32));check('forged HTTP callback rejected',r.status_code==400 and r.text=='fail')
    for route in ['/app/config.php','/app/seed.json','/storage/errors.php','/storage/blobs/','/uploads/']:
        check('test router denies private path '+route,guest.get(base+route).status_code==403)
    for blob in (site/'storage/blobs').glob('*.php'):
        check('blob PHP envelope stops before payload',subprocess.check_output(['php',str(blob)])==b'')
    r=guest.get(base+'/');check('security headers applied',all(k in r.headers for k in ['Content-Security-Policy','X-Content-Type-Options','X-Frame-Options','Referrer-Policy']))
    exec(compile((ROOT/'tests/http_extensions.py').read_text(),str(ROOT/'tests/http_extensions.py'),'exec'))
    exec(compile((ROOT/'tests/http_motion_community.py').read_text(),str(ROOT/'tests/http_motion_community.py'),'exec'))
    exec(compile((ROOT/'tests/http_012.py').read_text(),str(ROOT/'tests/http_012.py'),'exec'))
    exec(compile((ROOT/'tests/http_013.py').read_text(),str(ROOT/'tests/http_013.py'),'exec'))
    exec(compile((ROOT/'tests/http_014.py').read_text(),str(ROOT/'tests/http_014.py'),'exec'))
    exec(compile((ROOT/'tests/http_015.py').read_text(),str(ROOT/'tests/http_015.py'),'exec'))
    exec(compile((ROOT/'tests/http_016.py').read_text(),str(ROOT/'tests/http_016.py'),'exec'))
    exec(compile((ROOT/'tests/http_017.py').read_text(),str(ROOT/'tests/http_017.py'),'exec'))
    exec(compile((ROOT/'tests/http_018.py').read_text(),str(ROOT/'tests/http_018.py'),'exec'))
    exec(compile((ROOT/'tests/http_019.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_019.py'),'exec'))
    exec(compile((ROOT/'tests/http_020.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_020.py'),'exec'))
    exec(compile((ROOT/'tests/http_021.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_021.py'),'exec'))
    exec(compile((ROOT/'tests/http_022.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_022.py'),'exec'))
    exec(compile((ROOT/'tests/http_023.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_023.py'),'exec'))
    exec(compile((ROOT/'tests/http_audit_20260915.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_audit_20260915.py'),'exec'))
    exec(compile((ROOT/'tests/http_0232.py').read_text(encoding='utf-8'),str(ROOT/'tests/http_0232.py'),'exec'))
    expect_post(user,'logout')
    check('logout clears authenticated route','r=login' in user.get(base+'/index.php?r=profile').url)
    # Only expected user-error responses may be in app log; no internal runtime errors.
    errors=(site/'storage/errors.php').read_text() if (site/'storage/errors.php').exists() else ''
    check('no internal PHP/application exceptions','ErrorException' not in errors and 'RuntimeException' not in errors and ' TypeError ' not in errors and ' ParseError ' not in errors and ' PDOException ' not in errors,errors[-500:])
except Exception as exc:
    check('suite completed',False,repr(exc))
finally:
    server.terminate();server.wait(timeout=5);log.close();smtp.close()
    if 'conn' in globals(): conn.close()
    report={'runtime':runtime,'total':len(results),'passed':sum(x['ok'] for x in results),'failed':sum(not x['ok'] for x in results),'tests':results,'transport':'requests over real local PHP HTTP server; test SQLite adapter if native PDO absent','not_tested':['real shared host','production Apache/Nginx/IIS rules','real merchant or external SMTP provider','browser navigation (separate browser suite)']}
    print(json.dumps(report,ensure_ascii=False,indent=2))
    if os.environ.get('CY_KEEP_HTTP_FIXTURE'):
        import sys
        print('HTTP_FIXTURE='+str(work),file=sys.stderr)
    else:
        shutil.rmtree(work)
    raise SystemExit(0 if report['failed']==0 else 1)
