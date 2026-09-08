"""0.16 HTTP integration, real local requests. No external payment/storage request."""
import hashlib
for tab16 in ['commerce','shipping','service','media_lab','translations']:
    rr16=admin.get(base+'/admin/index.php',params={'tab':tab16})
    check('016 admin GET '+tab16,rr16.status_code==200,rr16.text[-250:])
    check('016 ordinary account denied '+tab16,user.get(base+'/admin/index.php',params={'tab':tab16}).status_code==403)
for group16 in ['international','checkout','direct_payments','automation','media_plus']:
    check('016 settings '+group16,admin.get(base+'/admin/index.php',params={'tab':'settings','group':group16}).status_code==200)
for route16 in ['guest_orders']:
    check('016 frontend GET '+route16,user.get(base+'/index.php',params={'r':route16}).status_code==200)
# Language is a CSRF-protected preference, never a template path.
expect_post(guest,'locale_select',locale='en-US')
check('016 English document language',html(guest).html.get('lang')=='en-US')
expect_post(guest,'locale_select',400,locale='../../config')
expect_post(guest,'locale_select',locale='zh-CN')
check('016 Chinese document language',html(guest).html.get('lang')=='zh-CN')
expect_post(user,'object_prepare',403,name='x.txt',bytes='10')
expect_post(admin,'object_prepare',403,name='x.txt',bytes='10')
# Anonymous zero-price purchase exercises the exact same reservation/receipt boundary.
free16=create_content(title='016 guest entitlement',kind='article',access_level='paid',price_currency='balance',price='0',protected_body='GUEST-ENTITLEMENT-016')
visitor16=requests.Session();stranger16=requests.Session()
expect_post(visitor16,'checkout_review',email='visitor016@example.test',gateway='wallet',lines=json.dumps([{'item_id':free16,'kind':'content','quantity':1}]))
review16=html(visitor16,'/index.php?r=checkout_review')
intent16=review16.select_one('input[name=intent]')['value']
check('016 review is an explicit native POST',review16.select_one('input[value=checkout_confirm]').find_parent('form').get('method','').lower()=='post')
expect_post(visitor16,'checkout_confirm',intent=intent16,confirm='1')
sale16=db_one('SELECT * FROM cy_sales WHERE user_id IN (SELECT id FROM cy_users WHERE is_guest=1) ORDER BY id DESC LIMIT 1')
order16=db_one('SELECT * FROM cy_orders WHERE sale_id=?',(sale16['id'],))
count16=db_one('SELECT COUNT(*) n FROM cy_orders')['n']
expect_post(visitor16,'checkout_confirm',intent=intent16,confirm='1')
check('016 guest confirm retry adds no duplicate orders',db_one('SELECT COUNT(*) n FROM cy_orders')['n']==count16 and sale16['status']=='paid')
check('016 guest receipt owner sees receipt',visitor16.get(base+'/index.php',params={'r':'receipt','id':sale16['id']}).status_code==200)
denied16=stranger16.get(base+'/index.php',params={'r':'receipt','id':sale16['id']})
check('016 other session cannot see receipt','r=guest_orders' in denied16.url and sale16['sale_no'] not in denied16.text)
check('016 guest pass does not authenticate a regular profile','r=login' in visitor16.get(base+'/index.php?r=profile').url)
pass16=html(visitor16,'/index.php?r=guest_orders').select_one('.guest-pass').get_text()
check('016 guest pass has full entropy-length',len(pass16)==64)
expect_post(stranger16,'guest_unlock',403,token='0'*64)
expect_post(stranger16,'guest_unlock',token=pass16)
check('016 portable pass unlocks only its purchases',stranger16.get(base+'/index.php',params={'r':'receipt','id':sale16['id']}).status_code==200)
expect_post(stranger16,'guest_forget')
denied16=stranger16.get(base+'/index.php',params={'r':'receipt','id':sale16['id']})
check('016 locking guest session removes receipt access','r=guest_orders' in denied16.url and sale16['sale_no'] not in denied16.text)
expect_post(other,'aftersale_request',404,order_id=order16['id'],kind='refund',reason='not mine')
# Chunk endpoint uses genuine multipart uploads, checks CSRF and then verifies complete bytes.
blob16=(b'Safe resumable text fixture 016\n'*12000)
rr16=expect_post(admin,'upload_start',name='resumable-http.txt',bytes=len(blob16),sha256=hashlib.sha256(blob16).hexdigest(),private='1')
upload16=rr16.json()['upload']; key16=upload16['upload_key'];step16=upload16['chunk_bytes']
expect_post(user,'upload_status',403,upload_key=key16)
expect_post(admin,'upload_finish',409,upload_key=key16)
for part16,offset16 in enumerate(range(0,len(blob16),step16)):
    rr16=admin.post(base+'/action.php',data={'action':'upload_chunk','csrf':csrf(admin),'upload_key':key16,'part':part16},files={'file':('chunk.bin',blob16[offset16:offset16+step16],'application/octet-stream')},headers={'Accept':'application/json'})
    check('016 real HTTP chunk '+str(part16),rr16.status_code==200,rr16.text)
rr16=expect_post(admin,'upload_finish',upload_key=key16);media16=rr16.json()['media_id']
rr16=expect_post(admin,'upload_finish',upload_key=key16)
check('016 finalize retry returns same media',rr16.json()['media_id']==media16)
check('016 private assembled media is byte-exact',admin.get(base+'/media.php',params={'id':media16}).content==blob16)
check('016 assembled media never becomes public',stranger16.get(base+'/media.php',params={'id':media16}).status_code in [401,403])
# Revision-bound human-reviewed translation; paid text is neither indexed nor replaced.
article16=create_content(title='016 original translation source',body='Original public body 016',protected_body='DO-NOT-INDEX-016-SECRET',access_level='paid',price_currency='balance',price='5')
source16=db_one('SELECT edit_version FROM cy_contents WHERE id=?',(article16,))['edit_version']
expect_post(admin,'translation_save',content_id=article16,locale='en-US',source_version=source16,revision='0',title='Orbital Handbook 016',excerpt='Rare orbital public handbook',body='Translated public guide 016',status='published')
expect_post(admin,'translation_save',409,content_id=article16,locale='en-US',source_version=source16,revision='0',title='Stale overwrite',status='published')
expect_post(admin,'translation_generate',400,content_id=article16,source='zh',locale='en-US',revision='1')
expect_post(visitor16,'locale_select',locale='en-US')
article_response16=visitor16.get(base+'/index.php',params={'r':'article','id':article16})
check('016 approved translation renders without protected text','Translated public guide 016' in article_response16.text and 'DO-NOT-INDEX-016-SECRET' not in article_response16.text)
search16=visitor16.get(base+'/index.php',params={'r':'search','q':'Orbital Handbook'})
check('016 AND-token search finds approved public translation','Orbital Handbook 016' in search16.text)
search16=visitor16.get(base+'/index.php',params={'r':'search','q':'DO-NOT-INDEX-016-SECRET'})
search_dom16=BeautifulSoup(search16.text,'html.parser')
check('016 search never indexes paid protected body',search16.status_code==200 and len(search_dom16.select('.main-grid > div > .layout-grid .card-grid .card'))==0 and '0 ' in search_dom16.select_one('.main-grid .filters .muted').get_text())
# A page slot, conditional visibility and global insert render through normal controller.
page16={'format':'chengyu-layout','version':1,'blocks':[{'id':'hero016','type':'hero','title':'PAGE-016-HERO','text':'A composed page'},{'id':'faq016','type':'faq','title':'PAGE-016-FAQ','text':'Question?|Answer.'},{'id':'members016','type':'text','title':'MEMBERS-ONLY-016','text':'Hidden from guest HTML','audience':'member'}]}
expect_post(admin,'admin_layout',slot='page1',revision=0,document=json.dumps(page16))
landing16=stranger16.get(base+'/index.php?r=landing&slot=page1')
check('016 independently composed page renders',landing16.status_code==200 and 'PAGE-016-HERO' in landing16.text and 'PAGE-016-FAQ' in landing16.text)
check('016 audience restriction omits entire block in guest HTML','MEMBERS-ONLY-016' not in landing16.text)
check('016 members see their conditional block','MEMBERS-ONLY-016' in user.get(base+'/index.php?r=landing&slot=page1').text)
check('016 arbitrary page slot rejected',stranger16.get(base+'/index.php?r=landing&slot=../../config').status_code==400)
# Rulebook configuration is validated and revision-guarded via the real administrator actions.
shipping16={'mode':'piece','default':{'first':1,'first_fee':500,'step':1,'step_fee':100,'free_above':10000},'zones':{}}
expect_post(admin,'admin_rule_save',kind='shipping',name='016 HTTP parcel',document=json.dumps(shipping16),revision='0',active='1')
shipid16=db_one("SELECT id FROM cy_shipping_templates WHERE name='016 HTTP parcel'")['id']
expect_post(admin,'admin_rule_save',409,kind='shipping',id=shipid16,name='Stale shipping',document=json.dumps(shipping16),revision='0',active='1')
expect_post(user,'admin_rule_save',403,kind='shipping',name='forged',document=json.dumps(shipping16),revision='0')
check('016 jobs endpoint rejects unsigned trigger',stranger16.post(base+'/jobs.php').status_code in [403,503])
check('016 direct webhook rejects unsigned provider payload',stranger16.post(base+'/notify-direct.php?gateway=stripe',json={}).status_code in [400,403,404])
# A real one-cent wallet purchase, password-gated service approval and idempotent refund.
paid16=create_content(title='016 wallet refund HTTP',kind='article',access_level='paid',price_currency='balance',price='0.01',protected_body='REFUND-ENTITLEMENT-016')
balance16=db_one('SELECT balance FROM cy_users WHERE id=?',(uid,))['balance']
expect_post(user,'checkout_review',gateway='wallet',lines=json.dumps([{'item_id':paid16,'kind':'content','quantity':1}]))
intent16=html(user,'/index.php?r=checkout_review').select_one('input[name=intent]')['value']
expect_post(user,'checkout_confirm',intent=intent16,confirm='1')
refund_order16=db_one('SELECT * FROM cy_orders WHERE user_id=? AND item_id=? ORDER BY id DESC LIMIT 1',(uid,paid16))
check('016 HTTP wallet checkout deducts exactly one cent',db_one('SELECT balance FROM cy_users WHERE id=?',(uid,))['balance']==balance16-1)
expect_post(user,'aftersale_request',order_id=refund_order16['id'],kind='refund',reason='HTTP original asset refund fixture')
service16=db_one('SELECT * FROM cy_aftersales WHERE order_id=?',(refund_order16['id'],))
expect_post(admin,'admin_aftersale_review',403,id=service16['id'],revision=service16['revision'],operation='approve',note='Missing proof fixture',current_password='wrong')
expect_post(admin,'admin_aftersale_review',id=service16['id'],revision=service16['revision'],operation='approve',note='Approved HTTP fixture',current_password='TestPassword!2026')
rid16=db_one('SELECT id FROM cy_refunds WHERE order_id=?',(refund_order16['id'],))['id']
expect_post(admin,'admin_refund_run',id=rid16,current_password='TestPassword!2026')
expect_post(admin,'admin_refund_run',id=rid16,current_password='TestPassword!2026')
check('016 refund retry restores only the original amount',db_one('SELECT balance FROM cy_users WHERE id=?',(uid,))['balance']==balance16)
check('016 refund and case complete together',db_one('SELECT status FROM cy_orders WHERE id=?',(refund_order16['id'],))['status']=='refunded' and db_one('SELECT state FROM cy_aftersales WHERE id=?',(service16['id'],))['state']=='completed')
check('016 refunded purchaser loses protected body','REFUND-ENTITLEMENT-016' not in user.get(base+'/index.php',params={'r':'article','id':paid16}).text)
check('016 checkout page accepts a valid item',admin.get(base+'/index.php',params={'r':'checkout','kind':'content','item_id':paid16}).status_code==200)
check('016 upgrade page accepts a valid plan',user.get(base+'/index.php',params={'r':'upgrade','plan_id':lifetime15}).status_code==200)
# Signed task scheduler rejects replayed requests. No real gateway is contacted.
import hmac
job_secret16=os.urandom(32).hex()
save_group('automation',jobs_secret=job_secret16)
job_body16=b'{"limit":1}'
job_time16=str(int(time.time()));job_nonce16=os.urandom(16).hex()
job_sig16=hmac.new(job_secret16.encode(),(job_time16+'\n'+job_nonce16+'\n'+hashlib.sha256(job_body16).hexdigest()).encode(),hashlib.sha256).hexdigest()
job_headers16={'Content-Type':'application/json','X-CY-Time':job_time16,'X-CY-Nonce':job_nonce16,'X-CY-Signature':job_sig16}
job_response16=stranger16.post(base+'/jobs.php',data=job_body16,headers=job_headers16)
check('016 signed task scheduler executes bounded work',job_response16.status_code==200 and job_response16.json().get('ok') is True,job_response16.text)
check('016 signed task scheduler rejects nonce replay',stranger16.post(base+'/jobs.php',data=job_body16,headers=job_headers16).status_code==409)
save_group('automation',clear_jobs_secret='1')
# Execute the distributed CLI command, with the same explicitly test-only PDO adapter.
cli_prepend16=work/'private/cli-adapter.php'
cli_prepend16.write_text("<?php\nrequire_once "+repr(str(site/'app/autoload.php'))+";\nif(!in_array('sqlite',PDO::getAvailableDrivers(),true)){require_once "+repr(str(ROOT/'tests/Support/FfiSqlite.php'))+";\\Chengyu\\Core\\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});}\n")
cli_result16=subprocess.run(['php','-d','ffi.enable=1','-d','auto_prepend_file='+str(cli_prepend16),str(ROOT/'tools/run_jobs.php'),str(site),'1'],capture_output=True,text=True,timeout=20)
try:
    cli_json16=json.loads(cli_result16.stdout);cli_ok16=cli_result16.returncode==0 and 0<=cli_json16['checked']<=1
except (ValueError,KeyError,TypeError): cli_ok16=False
check('016 distributed CLI task script resolves app config and runs',cli_ok16,cli_result16.stderr+cli_result16.stdout)
