"""0.24.1 real-browser regression on run_live_browser.py's disposable PHP site."""
from pathlib import Path
import os,json,secrets,sqlite3,time,base64,hmac,hashlib,struct
import requests
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright,expect

base=os.environ['CY_SNAPSHOT_BASE'];out=Path(os.environ['CY_VISUAL_OUT']);out.mkdir(parents=True,exist_ok=True)
db=sqlite3.connect(Path(os.environ['CY_TEST_IDS']).parent/'site.sqlite');db.row_factory=sqlite3.Row
results=[];errors=[];password='BrowserAuditPassword241!';admin=requests.Session();user=requests.Session();guest=requests.Session()
def check(name,ok,detail=''):
 results.append({'test':name,'ok':bool(ok),**({'detail':str(detail)[:450]} if not ok else {})})
def query(q,args=()):return [dict(r) for r in db.execute(q,args)]
def html(s,path='/'):return BeautifulSoup(s.get(base+path,timeout=20).text,'html.parser')
def post(s,action,**data):
 csrf=html(s).select_one('meta[name=csrf-token]')['content'];return s.post(base+'/action.php',data={'csrf':csrf,'action':action,'_back':'/',**data},headers={'Accept':'application/json'},timeout=30)
def api(s,action,**data):
 r=post(s,action,**data);check('HTTP '+action,r.status_code==200,r.text);assert r.status_code==200,r.text;return r
def form(action):return page.locator('form').filter(has=page.locator('[name=action][value='+action+']')).first
def submit(f):
 with page.expect_navigation(wait_until='load'):f.locator('button[type=submit]').first.click()
def go(path):
 response=page.goto(base+path,wait_until='load');check('GET '+path,response.status==200)
def shot(name):page.screenshot(path=str(out/name),full_page=False)
try:
 api(admin,'login',identifier=os.environ['CY_ADMIN_USER'],password=os.environ['CY_ADMIN_PASSWORD'],admin_login=1)
 api(user,'register',username='browser241',email='browser241@example.test',password=password,agree=1);uid=query("SELECT id FROM cy_users WHERE username='browser241'")[0]['id']
 # Valid ledger fixtures exercise both filtering and pagination without exhausting admin mutation quotas.
 points=query('SELECT points FROM cy_users WHERE id=?',[uid])[0]['points']
 for n in range(31):db.execute('INSERT INTO cy_ledger(user_id,currency,delta,balance_after,idempotency_key,reason,created_at) VALUES(?,?,?,?,?,?,?)',[uid,'points',1,points+n+1,'241-page-'+str(n),'241-filter',int(time.time())])
 db.execute('UPDATE cy_users SET points=points+31 WHERE id=?',[uid]);db.commit()
 api(admin,'admin_wallet',user_id=1,currency='points',delta=1,reason='241-filter-other',intent=secrets.token_hex(16),current_password=os.environ['CY_ADMIN_PASSWORD'])
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ['CY_CHROMIUM'],headless=True,args=['--disable-gpu']);ctx=browser.new_context(viewport={'width':1440,'height':1000});page=ctx.new_page();page.set_default_timeout(12000);page.on('pageerror',lambda e:errors.append(str(e)))
  go('/admin/');form('login').locator('[name=identifier]').fill(os.environ['CY_ADMIN_USER']);form('login').locator('[name=password]').fill(os.environ['CY_ADMIN_PASSWORD']);submit(form('login'));expect(page.locator('.admin-sidebar')).to_be_visible()
  check('login success survives navigation',page.locator('.alert').filter(has_text='欢迎回来').count()==1)
  go('/admin/index.php?tab=ledger&user_id='+str(uid)+'&q=241-filter');check('ledger filter excludes other accounts',page.locator('tbody tr').count()==30 and all('/ '+str(uid) in s for s in page.locator('tbody tr td:first-child').all_text_contents()))
  next_link=page.locator('.pagination a').last;check('ledger pagination preserves filters','user_id='+str(uid) in next_link.get_attribute('href') and 'q=241-filter' in next_link.get_attribute('href'));next_link.click();expect(page.locator('tbody tr')).to_have_count(1);shot('0241-ledger.png')
  go('/admin/index.php?tab=ledger&user_id=');check('blank ledger user filter means all',page.locator('tbody tr').count()>1)
  go('/admin/index.php?tab=invitations');f=form('admin_invitation_disable');invite=f.locator('[name=id]').input_value();before=query('SELECT uses,max_uses FROM cy_invitations WHERE id=?',[invite])[0];submit(f);expect(page.locator('[name=action][value=admin_invitation_enable]')).to_have_count(1);submit(form('admin_invitation_enable'));check('invitation re-enabled without resetting usage',query('SELECT uses,max_uses FROM cy_invitations WHERE id=?',[invite])[0]==before and query('SELECT active FROM cy_invitations WHERE id=?',[invite])[0]['active']==1);shot('0241-invitations.png')
  r=guest.get(base+'/index.php?r=upgrade&plan_id=1');check('guest upgrade leads to login with guidance',r.status_code==200 and 'r=login' in r.url and '请先登录后继续' in r.text)
  api(admin,'admin_content',kind='article',title='Paid review241',body='Public description',protected_body='PROTECTED241',status='published',access_level='paid',price='1.00',price_currency='balance')
  item=query("SELECT id FROM cy_contents WHERE title='Paid review241'")[0]['id'];api(admin,'admin_wallet',user_id=uid,currency='balance',delta='5',reason='241 fixture',intent=secrets.token_hex(16),current_password=os.environ['CY_ADMIN_PASSWORD']);api(user,'buy',kind='content',item_id=item,intent=secrets.token_hex(16));order=query('SELECT id FROM cy_orders WHERE user_id=? AND item_id=?',[uid,item])[0]['id'];api(user,'refund',order_id=order,reason='Review from shared service desk')
  go('/admin/index.php?tab=service&section=refunds');pending=page.locator('[data-refund-order="'+str(order)+'"]');expect(pending).to_have_count(1);pending.locator('summary').click();pending.locator('[name=current_password]').fill(os.environ['CY_ADMIN_PASSWORD']);submit(pending.locator('form'))
  check('shared refund desk processes classic wallet requests',query('SELECT status FROM cy_orders WHERE id=?',[order])[0]['status']=='refunded' and query('SELECT balance FROM cy_users WHERE id=?',[uid])[0]['balance']==500)
  check('service queue labels are translated',all(label not in page.locator('main').inner_text() for label in ['Service requests','Refund queue','Payout queue','Membership reconciliation']));shot('0241-service.png')
  r=post(admin,'admin_upgrade_refund',order_id=order,current_password=os.environ['CY_ADMIN_PASSWORD']);check('wrong refunded order rejects upgrade rollback',r.status_code==400 and not r.json()['ok'])
  # Factor recovery is a real privileged HTTP operation; test form proof and forbidden user access.
  api(user,'factor_begin',current_password=password);secret=html(user,'/index.php?r=security').select_one('.setup-key code').get_text();key=base64.b32decode(secret+'='*((8-len(secret)%8)%8));digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15;code=str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6);api(user,'factor_activate',current_password=password,factor_code=code)
  r=post(user,'admin_factor_recover',user_id=uid,confirm_recovery=1);check('non-admin cannot recover an authenticator',r.status_code==403)
  go('/admin/index.php?tab=edit_user&id='+str(uid));f=form('admin_factor_recover');f.locator('[name=target_username]').fill('browser241');f.locator('[name=reason]').fill('Identity confirmed for isolated regression');f.locator('[name=current_password]').fill(os.environ['CY_ADMIN_PASSWORD']);f.locator('[name=confirm_recovery]').check();submit(f)
  check('recovery form clears factor and leaves explicit result',not query('SELECT user_id FROM cy_second_factors WHERE user_id=?',[uid]) and '验证器已重置' in page.locator('main').inner_text());check('recovery invalidates existing target session','r=login' in user.get(base+'/index.php?r=profile').url);shot('0241-factor-recovery.png')
  ctx.close();ctx=browser.new_context(viewport={'width':1280,'height':900});page=ctx.new_page();page.set_default_timeout(12000);page.on('pageerror',lambda e:errors.append(str(e)))
  go('/index.php?r=login');form('login').locator('[name=identifier]').fill('browser241');form('login').locator('[name=password]').fill(password);submit(form('login'))
  go('/index.php?r=profile');f=form('password');f.locator('[name=current_password]').fill(password);f.locator('[name=password]').fill('ChangedBrowserPassword241!');submit(f);expect(page.locator('.alert').filter(has_text='已保存')).to_have_count(1);shot('0241-password-feedback.png');page.reload();check('success flash is consumed once',page.locator('.alert').filter(has_text='已保存').count()==0)
  check('old password no longer works',post(requests.Session(),'login',identifier='browser241',password=password).status_code==401);check('new password works',post(requests.Session(),'login',identifier='browser241',password='ChangedBrowserPassword241!').status_code==200)
  f=form('profile');png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');f.locator('[data-upload-target=avatar_id]').set_input_files({'name':'avatar241.png','mimeType':'image/png','buffer':png});expect(f.locator('[name=avatar_id]')).not_to_have_value('0');preview=f.locator('[data-upload-preview] img');expect(preview).to_be_visible();expect(f.locator('[data-upload-preview] figcaption')).to_contain_text('尚未保存');check('uploaded avatar really loads in preview',preview.evaluate('(img)=>img.complete && img.naturalWidth>0'));shot('0241-avatar-preview.png')
  submit(f);expect(page.locator('.alert').filter(has_text='已保存')).to_have_count(1);check('saved profile uses chosen avatar',int(query('SELECT avatar_id FROM cy_users WHERE id=?',[uid])[0]['avatar_id'])>0 and '/media.php?id=' in page.locator('.profile-hero img').get_attribute('src'));shot('0241-avatar-saved.png')
  for width in [390,768,1280]:page.set_viewport_size({'width':width,'height':900});check('profile mobile layout '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
  check('no uncaught JS errors',not errors,errors);browser.close()
except Exception as exc:check('0241 browser suite completes',False,repr(exc))
finally:
 db.close();report={'total':len(results),'passed':sum(x['ok'] for x in results),'failed':sum(not x['ok'] for x in results),'tests':results,'browser_errors':errors,'scope':'Disposable localhost PHP/native SQLite; admin recovery, filters, native refund, form navigation and upload previews'};(out/'browser0241.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');print(json.dumps(report,ensure_ascii=False))
raise SystemExit(bool(report['failed']))
