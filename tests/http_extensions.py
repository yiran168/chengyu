"""Public-HTTP regression coverage for 0.10, run inside disposable http_smoke.py."""
import sqlite3
from datetime import datetime, timedelta
conn = sqlite3.connect(work/'private/site.sqlite'); conn.row_factory = sqlite3.Row
def db_one(sql, args=()):
    return conn.execute(sql,args).fetchone()
def save_group(group, **changes):
    page=html(admin,'/admin/index.php?tab=settings&group='+group)
    form=page.select_one('input[name=action][value=admin_settings]').find_parent('form'); values={}
    for node in form.select('input[name],select[name],textarea[name]'):
        if node.name=='select':
            choice=node.select_one('option[selected]') or node.select_one('option'); values[node['name']]=choice['value']
        elif node.name=='textarea':values[node['name']]=node.get_text()
        elif node.get('type')!='checkbox' or node.has_attr('checked'):values[node['name']]=node.get('value','')
    values.update(changes)
    r=admin.post(base+'/action.php',data=values,headers={'Accept':'application/json'})
    check('save group '+group+' '+','.join(changes),r.status_code==200,r.text)
    return r

def create_content(**fields):
    defaults=dict(kind='article',title='Extension HTTP content',body='PUBLIC-EXT',protected_body='PRIVATE-EXT',status='published',access_level='public',price_currency='points',price='0',inventory='-1',comment_enabled='1')
    defaults.update(fields); r=expect_post(admin,'admin_content',**defaults)
    return int(parse_qs(urlparse(r.json()['redirect']).query)['id'][0])

# Every new screen has its real controller and permissions, not just a menu item.
for tab in ['variants','collections','links','announcements','tasks','invitations','downloads','builder']:
    response=admin.get(base+'/admin/index.php',params={'tab':tab})
    check('new admin tab '+tab,response.status_code==200,response.text[-300:])
    response=user.get(base+'/admin/index.php',params={'tab':tab})
    check('new admin tab denies ordinary user '+tab,response.status_code==403)
for slot in ['home','articles','forum','shop']:
    response=admin.get(base+'/admin/index.php',params={'tab':'builder','slot':slot})
    check('layout editor slot '+slot,response.status_code==200,response.text[-300:])
for route in ['collections','links','tasks']:
    response=user.get(base+'/index.php',params={'r':route})
    check('new frontend route '+route,response.status_code==200,response.text[-300:])
for asset in ['studio.css','studio.js']:
    response=guest.get(base+'/assets/'+asset);check('studio asset '+asset,response.status_code==200 and len(response.content)>500)

# Protected-body purchase bypass, passwords, and malformed HTTP values.
vip_content=create_content(title='Tier protected HTTP',access_level='vip',min_vip_tier=2)
expect_post(user,'buy',403,kind='content',item_id=vip_content,intent=os.urandom(16).hex())
check('blocked VIP bypass did not leak body','PRIVATE-EXT' not in user.get(base+f'/index.php?r=article&id={vip_content}').text)
password_content=create_content(title='Password protected HTTP',access_level='password',content_password='correct-secret')
expect_post(guest,'content_unlock',403,content_id=password_content,content_password='wrong-secret')
expect_post(guest,'content_unlock',content_id=password_content,content_password='correct-secret')
check('password unlock renders protected body','PRIVATE-EXT' in guest.get(base+f'/index.php?r=article&id={password_content}').text)
check('password grant belongs to its session','PRIVATE-EXT' not in other.get(base+f'/index.php?r=article&id={password_content}').text)
r=user.post(base+'/action.php',data={'action':'profile','csrf':csrf(user),'display_name[]':'malformed'},headers={'Accept':'application/json'})
check('array form field returns controlled 400',r.status_code==400,r.text)

# Layout save, conflict detection, import validation and front-end rendering.
doc={'format':'chengyu-layout','version':1,'blocks':[{'id':'test_heading','type':'heading','title':'HTTP-LAYOUT-MARKER','text':'A real configured section','device':'all','tone':'glass'},{'id':'test_content','type':'content','kind':'article','title':'HTTP-CONTENT-BLOCK','size':3}]}
expect_post(admin,'admin_layout',slot='home',revision=0,document=json.dumps(doc))
check('layout renders published block','HTTP-LAYOUT-MARKER' in guest.get(base+'/').text)
expect_post(admin,'admin_layout',409,slot='home',revision=0,document=json.dumps(doc))
expect_post(admin,'admin_layout',400,slot='home',revision=1,document='{}')
expect_post(user,'admin_layout',403,slot='home',revision=1,document=json.dumps(doc))
check('conflicting update preserves published layout','HTTP-LAYOUT-MARKER' in guest.get(base+'/').text)

expect_post(admin,'admin_catalog',entity='collections',name='HTTP collection',description='Organized real articles',content_ids='1,2,1',active='1')
collection=db_one('SELECT id FROM cy_collections WHERE name=?',('HTTP collection',))['id']
check('collection deduplicates members',db_one('SELECT COUNT(*) n FROM cy_collection_items WHERE collection_id=?',(collection,))['n']==2)
check('collection page renders',user.get(base+f'/index.php?r=collection&id={collection}').status_code==200)
expect_post(admin,'admin_catalog',entity='links',name='HTTP directory',description='Official documentation',url='https://www.php.net/',group_name='Engineering',active='1')
check('directory frontend shows approved link','https://www.php.net/' in guest.get(base+'/index.php?r=links').text)
expect_post(admin,'admin_catalog',400,entity='links',name='Bad scheme',url='javascript:alert(1)',active='1')
expect_post(admin,'admin_catalog',entity='announcements',name='LOGIN-ANNOUNCEMENT-HTTP',description='Members only',audience='login',route='tasks',active='1')
check('login announcement excluded from guest HTML','LOGIN-ANNOUNCEMENT-HTTP' not in guest.get(base+'/').text)
check('login announcement shown to member','LOGIN-ANNOUNCEMENT-HTTP' in user.get(base+'/').text)

# Reward is earned from an actual earlier check-in, not a client supplied count.
expect_post(admin,'admin_task',name='HTTP check-in task',description='Complete today check-in',metric='checkin',period='daily',target_count=1,reward_points=7,reward_xp=5,active='1')
task=db_one('SELECT id FROM cy_tasks WHERE name=?',('HTTP check-in task',))['id']
uid=db_one('SELECT id FROM cy_users WHERE username=?',('alice',))['id']
points_before=db_one('SELECT points FROM cy_users WHERE id=?',(uid,))['points']
expect_post(user,'task_claim',task_id=task)
expect_post(user,'task_claim',task_id=task)
check('HTTP task retry only credits once',db_one('SELECT points FROM cy_users WHERE id=?',(uid,))['points']==points_before+7)
check('task frontend shows configured task','HTTP check-in task' in user.get(base+'/index.php?r=tasks').text)

# Invitation issuance is admin-only, one-time plaintext, and rollback safe.
expect_post(admin,'admin_invitation',count=1,max_uses=1,expires_at=(datetime.now()+timedelta(days=30)).strftime('%Y-%m-%d'),label='HTTP invite')
page=html(admin,'/admin/index.php?tab=invitations')
import re
codes=re.findall(r'[A-Fa-f0-9]{24}',page.select_one('.secret-panel pre').get_text() if page.select_one('.secret-panel pre') else '')
code=codes[0] if codes else ''
check('invitation shown after issue',bool(code))
check('invitation plaintext shown once',not code or code not in html(admin,'/admin/index.php?tab=invitations').get_text())
save_group('security',register_limit='20')
save_group('users',registration_mode='invite')
new_user=requests.Session()
expect_post(new_user,'register',400,username='invited_http',display_name='Invited',email='invited@example.test',password='TestPassword!2026',agree='1')
expect_post(new_user,'register',username='invited_http',display_name='Invited',email='invited@example.test',password='TestPassword!2026',agree='1',invitation=code)
check('invitation usage committed with account',db_one('SELECT uses FROM cy_invitations WHERE label=?',('HTTP invite',))['uses']==1)
save_group('users',registration_mode='open')

# Multi-SKU checkout, server-side price, owned address, fulfillment and receipt.
pid=create_content(kind='product',title='HTTP multi-variant product',product_type='physical',price='99',inventory='0')
expect_post(admin,'admin_variant',content_id=pid,sku='HTTP-A',name='Small',price='3',inventory=2,active='1')
expect_post(admin,'admin_variant',content_id=pid,sku='HTTP-B',name='Large',price='4',inventory=1,active='1')
va=db_one('SELECT id FROM cy_variants WHERE sku=?',('HTTP-A',))['id'];vb=db_one('SELECT id FROM cy_variants WHERE sku=?',('HTTP-B',))['id']
address=db_one('SELECT id FROM cy_addresses WHERE user_id=? ORDER BY id DESC',(uid,))['id']
expect_post(user,'cart_add',item_id=pid,variant_id=va,quantity=1)
expect_post(user,'cart_add',item_id=pid,variant_id=vb,quantity=1)
check('cart keeps two SKU lines',len(html(user,'/index.php?r=cart').select('.cart-row'))==2)
expect_post(user,'cart_checkout',intent=os.urandom(16).hex(),address_id=address)
orders=conn.execute("SELECT * FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' ORDER BY id",(uid,pid)).fetchall()
check('checkout uses server SKU prices',len(orders)==2 and sorted(x['amount'] for x in orders)==[3,4])
check('checkout decrements each SKU',db_one('SELECT inventory FROM cy_contents WHERE id=?',(pid,))['inventory']==1)
if orders:
    oid=orders[0]['id']
    expect_post(other,'order_receive',400,order_id=oid)
    expect_post(admin,'admin_ship',id=oid,tracking='TEST-TRACKING-1')
    expect_post(admin,'admin_ship',id=oid,tracking='TEST-TRACKING-2')
    expect_post(user,'order_receive',order_id=oid)
    expect_post(user,'order_receive',order_id=oid)
    check('receipt persists once',db_one('SELECT fulfillment FROM cy_orders WHERE id=?',(oid,))['fulfillment']=='received')

# Real file streaming: range auth, suffix, HEAD and invalid range.
r=user.get(base+f'/media.php?id={mid}',headers={'Range':'bytes=0-6'})
check('authorized HTTP byte range',r.status_code==206 and r.content==b'PRIVATE' and r.headers.get('Content-Range')=='bytes 0-6/23',r.text)
r=user.get(base+f'/media.php?id={mid}',headers={'Range':'bytes=-4'})
check('authorized suffix range',r.status_code==206 and r.content==b'TEST')
r=other.get(base+f'/media.php?id={mid}',headers={'Range':'bytes=0-6'})
check('range cannot bypass download authorization',r.status_code==403)
r=user.head(base+f'/media.php?id={mid}')
check('HEAD sends only headers',r.status_code==200 and not r.content and r.headers.get('Content-Length')=='23')
r=user.get(base+f'/media.php?id={mid}',headers={'Range':'bytes=200-'})
check('invalid HTTP range returns 416',r.status_code==416)
check('download logged once per file per member',db_one('SELECT COUNT(*) n FROM cy_downloads WHERE user_id=? AND media_id=?',(uid,mid))['n']==1)
conn.close()
