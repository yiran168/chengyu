"""Local HTTP coverage for integrations/settings/badges/lifetime/cart. Never calls a provider."""
for asset in ['connections.css','connections.js']:
    r=guest.get(base+'/assets/'+asset);check('015 production asset '+asset,r.status_code==200 and len(r.content)>1000)
for tab in ['integrations','configuration','badges']:
    r=admin.get(base+'/admin/index.php?tab='+tab);check('015 admin page '+tab,r.status_code==200,r.text[:150])
    check('015 ordinary user denied '+tab,user.get(base+'/admin/index.php?tab='+tab).status_code==403)
for route in ['badges','login','register','membership']:
    check('015 public route '+route,guest.get(base+'/index.php?r='+route).status_code==200)
check('015 connection completion requires proof',guest.get(base+'/index.php?r=connect').status_code==403)
check('015 forged provider callback denied',guest.get(base+'/oauth.php?provider=github&state='+('f'*64)+'&code=forged').status_code==403)
expect_post(guest,'oauth_begin',403,provider='github',purpose='login')
save_group('connections',social_enabled='1',oauth_github_enabled='1',oauth_github_id='fixture-client',oauth_github_secret='fixture-secret-not-a-real-app')
expect_post(guest,'oauth_begin',400,provider='github',purpose='login')
check('015 provider secret excluded from public login', 'fixture-secret-not-a-real-app' not in guest.get(base+'/index.php?r=login').text)
check('015 enabled provider has real CSRF POST form',bool(html(guest,'/index.php?r=login').select('form input[name=action][value=oauth_begin]')))
expect_post(user,'oauth_unlink',403,id='1',current_password='WrongPassword!2026')
expect_post(user,'oauth_unlink',404,id='999999',current_password='TestPassword!2026')
expect_post(guest,'oauth_login',403,nonce='f'*64)
expect_post(guest,'oauth_register',403,nonce='f'*64)
# Cosmetic badge lifecycle: grants never change the recipient's role.
save_group('modules',badges_enabled='1')
expect_post(user,'admin_badge',403,name='Unauthorized')
expect_post(admin,'admin_badge',name='HTTP recognition',description='A verified disposable badge',symbol='shield',tone='tide',active='1',sort_order='1')
badge15=db_one("SELECT id FROM cy_badges WHERE name='HTTP recognition'")['id'];role15=db_one('SELECT role FROM cy_users WHERE id=?',(uid,))['role']
expect_post(admin,'admin_badge_grant',badge_id=badge15,user_id=uid)
expect_post(admin,'admin_badge_grant',badge_id=badge15,user_id=uid)
check('015 repeated HTTP badge grant creates one record',db_one('SELECT COUNT(*) n FROM cy_user_badges WHERE user_id=? AND badge_id=?',(uid,badge15))['n']==1)
check('015 badge appears on member profile','HTTP recognition' in user.get(base+'/index.php?r=profile').text)
check('015 badge catalog counts real holders','HTTP recognition' in guest.get(base+'/index.php?r=badges').text)
check('015 cosmetic badge never changes permissions',db_one('SELECT role FROM cy_users WHERE id=?',(uid,))['role']==role15)
expect_post(admin,'admin_badge_grant',400,badge_id=badge15,user_id=uid,expires_at='2020-01-01T00:00')
expect_post(admin,'admin_badge',400,id=badge15,name='unsafe',symbol='<script>',tone='tide')
expect_post(admin,'admin_badge',404,id='999999',name='No phantom badge',symbol='shield',tone='tide')
expect_post(admin,'admin_badge_revoke',badge_id=badge15,user_id=uid)
check('015 badge revoke removes public collection item','HTTP recognition' not in user.get(base+'/index.php?r=profile').text)
expect_post(admin,'admin_badge_grant',badge_id=badge15,user_id=uid)
save_group('modules',badges_enabled='0')
check('015 disabled badges module blocks gallery',guest.get(base+'/index.php?r=badges').status_code==403)
check('015 disabled badges module hides collection','HTTP recognition' not in user.get(base+'/index.php?r=profile').text)
save_group('modules',badges_enabled='1')
# Encrypted snapshot capture, masked review, protected export, real multipart import and restore.
expect_post(user,'admin_snapshot_capture',403,label='Unauthorized snapshot')
expect_post(admin,'admin_snapshot_capture',label='HTTP vault baseline')
snapshot15=db_one("SELECT id FROM cy_setting_snapshots WHERE label='HTTP vault baseline'")['id']
r=post(admin,'admin_snapshot_export',id=snapshot15,current_password='WrongPassword!2026');check('015 snapshot export requires fresh password',r.status_code==403)
r=post(admin,'admin_snapshot_export',id=snapshot15,current_password='TestPassword!2026');archive15=r.content
check('015 encrypted export has download and no-store headers',r.status_code==200 and 'attachment' in r.headers.get('Content-Disposition','') and 'no-store' in r.headers.get('Cache-Control',''))
check('015 snapshot export never exposes service secrets',b'fixture-secret-not-a-real-app' not in archive15 and b'password_hash' not in archive15)
save_group('connections',oauth_github_secret='fixture-rotated-not-a-real-app')
preview15=html(admin,'/admin/index.php?tab=configuration&preview='+str(snapshot15));revision15=preview15.select_one('input[name=revision]')['value']
check('015 diff masks old and current secrets','fixture-secret-not-a-real-app' not in str(preview15) and 'fixture-rotated-not-a-real-app' not in str(preview15))
save_group('site',site_name='Changed after preview')
expect_post(admin,'admin_snapshot_restore',409,id=snapshot15,revision=revision15,current_password='TestPassword!2026')
preview15=html(admin,'/admin/index.php?tab=configuration&preview='+str(snapshot15));revision15=preview15.select_one('input[name=revision]')['value'];count15=db_one('SELECT COUNT(*) n FROM cy_setting_snapshots')['n']
expect_post(admin,'admin_snapshot_restore',id=snapshot15,revision=revision15,current_password='TestPassword!2026')
check('015 restore captures rollback snapshot first',db_one('SELECT COUNT(*) n FROM cy_setting_snapshots')['n']==count15+1)
check('015 restored settings visible on next request','Changed after preview' not in guest.get(base+'/').text)
r=admin.post(base+'/action.php',data={'action':'admin_snapshot_import','csrf':csrf(admin),'label':'HTTP imported vault','current_password':'TestPassword!2026'},files={'archive':('backup.cysettings',archive15,'application/octet-stream')},headers={'Accept':'application/json'})
check('015 real multipart encrypted archive import',r.status_code==200 and db_one("SELECT id FROM cy_setting_snapshots WHERE label='HTTP imported vault'") is not None,r.text)
r=admin.post(base+'/action.php',data={'action':'admin_snapshot_import','csrf':csrf(admin),'label':'Invalid','current_password':'TestPassword!2026'},files={'archive':('bad.cysettings',b'{"settings":{"site_name":"tamper"}}','application/octet-stream')},headers={'Accept':'application/json'})
check('015 unsigned plaintext archive rejected',r.status_code==400,r.text)
# Plan editing and commerce go through production POST actions, not direct DB inserts.
expect_post(admin,'admin_entity',entity='plans',name='HTTP lifetime tier 3',days='0',tier='3',price_currency='points',price='12',active='1',sort_order='99')
lifetime15=db_one("SELECT id FROM cy_plans WHERE name='HTTP lifetime tier 3'")['id']
expect_post(user,'buy',kind='membership',item_id=lifetime15,intent=os.urandom(16).hex(),price_currency='points',price_ceiling='12')
check('015 lifetime membership stored independently per tier',db_one('SELECT until_at FROM cy_memberships WHERE user_id=? AND tier=3',(uid,))['until_at']==253402214400)
check('015 lifetime date rendered without year 9999','9999' not in user.get(base+'/index.php?r=membership').text)
expect_post(user,'buy',409,kind='membership',item_id=lifetime15,intent=os.urandom(16).hex())
# A zero cap must roll back all writes, not merely hide a button.
cart15=create_content(kind='product',product_type='digital',price_currency='points',price='5',title='015 cart ceiling item',access_level='paid')
expect_post(user,'cart_add',item_id=cart15,quantity='1')
points15=db_one('SELECT points FROM cy_users WHERE id=?',(uid,))['points'];norders15=db_one('SELECT COUNT(*) n FROM cy_orders')['n']
expect_post(user,'cart_checkout',409,intent=os.urandom(16).hex(),ceiling_balance='0',ceiling_points='0',ceiling_tokens='0')
check('015 cart ceiling rejection fully rolls back debit and order',db_one('SELECT points FROM cy_users WHERE id=?',(uid,))['points']==points15 and db_one('SELECT COUNT(*) n FROM cy_orders')['n']==norders15)
expect_post(user,'cart_checkout',intent=os.urandom(16).hex(),ceiling_balance='0',ceiling_points='5',ceiling_tokens='0')
expect_post(user,'payment_reconcile',404,order_id='999999')
expect_post(guest,'payment_reconcile',401,order_id='999999')
# Every backend entry is present once despite visual grouping. Native links work without JS.
nav15=html(admin,'/admin/index.php?tab=integrations');anchors15=nav15.select('.admin-nav a')
check('015 grouped backend navigation contains each target once',len(anchors15)>25 and len({a['href'] for a in anchors15})==len(anchors15))
check('015 grouped navigation has one active link',len(nav15.select('.admin-sidebar a[aria-current=page]'))==1)
check('015 default navigation opens active group',bool(nav15.select('details[open] a[aria-current=page]')))
import re
expected_schema15=int(re.search(r'const VERSION\s*=\s*(\d+)',(ROOT/'site/app/Core/Migrations.php').read_text()).group(1))
check('015+ applied migration matches the current source',int(db_one("SELECT value FROM cy_settings WHERE name='_schema_version'")['value'])==expected_schema15)

# Native forms must finish on a same-origin 200 document under form-action self.
# Only the disposable config's canonical URL changes; requests still go to local HTTP.
# No browser follows the destination, and no identity/payment server is contacted.
save_group('epay',epay_enabled='1',epay_endpoint='https://merchant.example.test/submit.php',epay_merchant='fixture-merchant',epay_secret='fixture-payment-secret',epay_alipay='1')
config15=site/'app/config.php';original15=config15.read_text();guest_token15=csrf(guest);user_token15=csrf(user)
try:
    assert base in original15, 'Disposable canonical URL must be identifiable'
    config15.write_text(original15.replace(base,'https://island.example.test'))
    native15=guest.post(base+'/action.php',data={'action':'oauth_begin','provider':'github','purpose':'login','csrf':guest_token15,'_back':'https://untrusted.example.test/'},headers={'Accept':'text/html'},allow_redirects=False)
    handoff15=BeautifulSoup(native15.text,'html.parser').select_one('a[data-external-handoff]')
    check('015 native OAuth POST ends with same-origin document, not external 303',native15.status_code==200 and 'Location' not in native15.headers,native15.text[:150])
    check('015 handoff destination comes only from provider service',handoff15 is not None and urlparse(handoff15['href']).hostname=='github.com')
    check('015 handoff retains PKCE and original state',handoff15 is not None and parse_qs(urlparse(handoff15['href']).query).get('code_challenge_method')==['S256'] and len(parse_qs(urlparse(handoff15['href']).query).get('state',[''])[0])==64)
    check('015 strict CSP and no-referrer survive continuation',"form-action 'self'" in native15.headers.get('Content-Security-Policy','') and native15.headers.get('Referrer-Policy')=='no-referrer')
    check('015 continuation is private and never indexed','no-store' in native15.headers.get('Cache-Control','') and 'noindex' in native15.headers.get('X-Robots-Tag',''))
    check('015 no-script fallback is an actual link, not external form',handoff15 is not None and handoff15.name=='a' and all(urlparse(f.get('action','')).hostname is None for f in BeautifulSoup(native15.text,'html.parser').select('form')))
    payment_intent15=os.urandom(16).hex()
    payload15={'action':'topup','csrf':user_token15,'amount':'10.00','gateway':'epay','method':'alipay','intent':payment_intent15}
    nativepay15=user.post(base+'/action.php',data=payload15,headers={'Accept':'text/html'},allow_redirects=False)
    paylink15=BeautifulSoup(nativepay15.text,'html.parser').select_one('a[data-external-handoff]')
    check('015 native topup ends in a safe 200 continuation',nativepay15.status_code==200 and 'Location' not in nativepay15.headers and paylink15 is not None,nativepay15.text[:150])
    check('015 signed checkout URL stays complete',paylink15 is not None and urlparse(paylink15['href']).hostname=='merchant.example.test' and parse_qs(urlparse(paylink15['href']).query).get('money')==['10.00'] and len(parse_qs(urlparse(paylink15['href']).query).get('sign',[''])[0])==32)
    pending15=db_one('SELECT id,status FROM cy_orders WHERE request_key=?',(str(uid)+':'+payment_intent15,))
    check('015 viewing continuation cannot mark a payment paid',pending15 is not None and pending15['status']=='pending')
    if pending15:
        resumepay15=user.get(base+'/index.php?r=pay&id='+str(pending15['id']),allow_redirects=False)
        check('015 pending payment resume uses same protected continuation',resumepay15.status_code==200 and 'Location' not in resumepay15.headers and 'data-external-handoff' in resumepay15.text)
        check('015 foreign account cannot open checkout continuation',other.get(base+'/index.php?r=pay&id='+str(pending15['id'])).status_code==404)
    user.post(base+'/action.php',data=payload15,headers={'Accept':'text/html'},allow_redirects=False)
    check('015 native POST refresh does not create another topup',db_one('SELECT COUNT(*) n FROM cy_orders WHERE request_key=?',(str(uid)+':'+payment_intent15,))['n']==1)
finally:
    config15.write_text(original15)
check('015 arbitrary external continuation GET does not exist',guest.get(base+'/index.php?r=external_handoff&target=https://untrusted.example.test/').status_code==404)
