"""0.17 real local HTTP tests. No carrier, payment, translation or object-store network."""
from datetime import datetime, timezone, timedelta
for tab17 in ['search_index','learning','logistics','reconciliation','support']:
    rr17=admin.get(base+'/admin/index.php',params={'tab':tab17})
    check('017 admin '+tab17,rr17.status_code==200,rr17.text[-250:])
    check('017 member denied admin '+tab17,user.get(base+'/admin/index.php',params={'tab':tab17}).status_code==403)
save_group('modules',courses_enabled='1',support_enabled='1')
save_group('commerce',tracking_enabled='1')
save_group('discovery',search_engine='portable')
for route17 in ['courses','learning','support']:
    rr17=user.get(base+'/index.php',params={'r':route17})
    check('017 member route '+route17,rr17.status_code==200,rr17.text[-250:])
for route17 in ['learning','support']:
    check('017 private route requires login '+route17,'r=login' in guest.get(base+'/index.php',params={'r':route17}).url)
for asset17 in ['platform.css','platform.js']:
    check('017 production asset '+asset17,guest.get(base+'/assets/'+asset17).status_code==200)
for action17 in ['search_rebuild','layout_discard','layout_restore','course_save','lesson_save','tracking_attach','tracking_event','tracking_refresh','statement_import','statement_check','support_template']:
    expect_post(user,action17,403)
rr17=admin.post(base+'/action.php',data={'action':'course_save','title':'Missing CSRF'},headers={'Accept':'application/json'})
check('017 new actions preserve CSRF boundary',rr17.status_code==419,rr17.text)

# Context preferences must survive visiting the other application's pages.
expect_post(admin,'locale_select',locale='en-US',scope='admin')
expect_post(admin,'locale_select',locale='zh-CN',scope='site')
check('017 frontend language preference independent',html(admin).html.get('lang')=='zh-CN')
check('017 backend language preference independent',html(admin,'/admin/index.php?tab=learning').html.get('lang')=='en-US')
expect_post(admin,'locale_select',locale='zh-CN',scope='admin')
check('017 new admin copy Chinese','课程' in html(admin,'/admin/index.php?tab=learning').get_text())

# Build a real course and keep paid chapters inaccessible until confirmed checkout.
content17_http=create_content(title='HTTP orchidlesson course',access_level='paid',price_currency='balance',price='0',body='COURSE-PUBLIC-017',protected_body='SOURCE-SECRET-017')
expect_post(admin,'course_save',content_id=content17_http,title='HTTP course seventeen',description='Public outline',active='1')
course17_http=db_one('SELECT * FROM cy_courses WHERE content_id=?',(content17_http,))
expect_post(admin,'lesson_save',course_id=course17_http['id'],title='Private chapter',body='CHAPTER-SECRET-017',duration_seconds='120',active='1',sort_order='2')
lesson17_http=db_one('SELECT * FROM cy_lessons WHERE course_id=?',(course17_http['id'],))
expect_post(admin,'lesson_save',course_id=course17_http['id'],title='Public preview',body='CHAPTER-PREVIEW-017',preview='1',active='1',sort_order='1')
preview17_http=db_one('SELECT * FROM cy_lessons WHERE course_id=? AND preview=1',(course17_http['id'],))
rr17=guest.get(base+'/index.php',params={'r':'course','id':course17_http['id']})
check('017 course outline omits private chapter body',rr17.status_code==200 and 'CHAPTER-SECRET-017' not in rr17.text)
check('017 unpaid chapter fails closed',guest.get(base+'/index.php',params={'r':'learn','id':lesson17_http['id']}).status_code==403)
check('017 explicit preview renders','CHAPTER-PREVIEW-017' in guest.get(base+'/index.php',params={'r':'learn','id':preview17_http['id']}).text)
expect_post(other,'learning_progress',403,lesson_id=lesson17_http['id'],position_seconds='1',revision='0')
expect_post(user,'checkout_review',gateway='wallet',lines=json.dumps([{'item_id':content17_http,'kind':'content','quantity':1}]))
intent17_http=html(user,'/index.php?r=checkout_review').select_one('input[name=intent]')['value']
expect_post(user,'checkout_confirm',intent=intent17_http,confirm='1')
rr17=user.get(base+'/index.php',params={'r':'learn','id':lesson17_http['id']})
check('017 paid chapter delivered after confirmed checkout',rr17.status_code==200 and 'CHAPTER-SECRET-017' in rr17.text,rr17.text[-150:])
expect_post(user,'learning_progress',lesson_id=lesson17_http['id'],position_seconds='45',revision='0',note='PRIVATE-NOTE-017')
progress17_http=db_one('SELECT * FROM cy_learning_progress WHERE lesson_id=? AND user_id=?',(lesson17_http['id'],uid))
check('017 manual progress saved',progress17_http['position_seconds']==45 and progress17_http['note']=='PRIVATE-NOTE-017')
expect_post(user,'learning_progress',409,lesson_id=lesson17_http['id'],position_seconds='50',revision='0')
check('017 note not in course outline','PRIVATE-NOTE-017' not in guest.get(base+'/index.php',params={'r':'course','id':course17_http['id']}).text)
check('017 learning history lists authorized lesson','Private chapter' in user.get(base+'/index.php?r=learning').text)
expect_post(admin,'course_save',id=course17_http['id'],revision=course17_http['revision'],content_id=content17_http,title='HTTP course seventeen',description='Archived')
check('017 archived course withdraws paid chapter',user.get(base+'/index.php',params={'r':'learn','id':lesson17_http['id']}).status_code==404)
expect_post(admin,'course_save',id=course17_http['id'],revision=course17_http['revision']+1,content_id=content17_http,title='HTTP course seventeen',description='Public outline',active='1')

# Metadata index rebuild, observable on frontend with no JavaScript.
state17=expect_post(admin,'search_rebuild',reset='1').json()['index']
guard17=0
while not state17['completed'] and guard17<100:
    state17=post(admin,'search_rebuild').json()['index'];guard17+=1
check('017 bounded HTTP index rebuild completes',bool(state17['completed']))
search17=guest.get(base+'/index.php',params={'r':'search','q':'orchidlesson course'})
check('017 indexed multiword search returns course source',search17.status_code==200 and 'HTTP orchidlesson course' in search17.text)
search17=guest.get(base+'/index.php',params={'r':'search','q':'CHAPTER-SECRET-017'})
check('017 private lesson is never a search document','HTTP orchidlesson course' not in search17.text)
search17=guest.get(base+'/index.php',params={'r':'search','q':'SOURCE-SECRET-017'})
check('017 paid source body is never indexed','HTTP orchidlesson course' not in search17.text)

# Draft visibility, optimistic conflict, publish and restoration.
layout17_doc={'format':'chengyu-layout','version':1,'blocks':[{'id':'intro17','type':'heading','title':'DRAFT-ONLY-HTTP-017'}]}
slot17='page12'
live17=db_one('SELECT revision FROM cy_layouts WHERE slot=?',(slot17,))
rev17=live17['revision'] if live17 else 0
expect_post(admin,'admin_layout',slot=slot17,revision=rev17,draft_revision=0,document=json.dumps(layout17_doc),operation='draft')
check('017 draft does not change live HTML','DRAFT-ONLY-HTTP-017' not in guest.get(base+'/index.php',params={'r':'landing','slot':slot17}).text)
expect_post(admin,'admin_layout',409,slot=slot17,revision=rev17,draft_revision=0,document=json.dumps(layout17_doc),operation='draft')
expect_post(admin,'admin_layout',slot=slot17,revision=rev17,draft_revision=1,document=json.dumps(layout17_doc),operation='publish')
check('017 explicit publish changes live HTML','DRAFT-ONLY-HTTP-017' in guest.get(base+'/index.php',params={'r':'landing','slot':slot17}).text)
layout17_doc['blocks'][0]['title']='SECOND-HTTP-017'
expect_post(admin,'admin_layout',slot=slot17,revision=rev17+1,draft_revision=0,document=json.dumps(layout17_doc),operation='publish')
expect_post(admin,'layout_restore',slot=slot17,revision=rev17+2,history_revision=rev17+1)
check('017 historical restore republishes as new revision','DRAFT-ONLY-HTTP-017' in guest.get(base+'/index.php',params={'r':'landing','slot':slot17}).text)
check('017 canonical keeps page identity',f'slot={slot17}' in html(guest,'/index.php?r=landing&slot='+slot17).select_one('link[rel=canonical]')['href'])

# Owner-isolated tickets, idempotent retries, template and status transitions.
ticket_key17=os.urandom(16).hex()
rr17=expect_post(user,'support_create',subject='HTTP assistance seventeen',body='SUPPORT-PRIVATE-017',request_key=ticket_key17)
ticket_id17=int(parse_qs(urlparse(rr17.json()['redirect']).query)['id'][0])
expect_post(user,'support_create',subject='HTTP assistance seventeen',body='SUPPORT-PRIVATE-017',request_key=ticket_key17)
check('017 ticket retry creates only one row',db_one('SELECT COUNT(*) n FROM cy_support_tickets WHERE subject=?',('HTTP assistance seventeen',))['n']==1)
expect_post(user,'support_create',409,subject='Changed request',body='SUPPORT-PRIVATE-017',request_key=ticket_key17)
check('017 owner reads ticket','SUPPORT-PRIVATE-017' in user.get(base+'/index.php',params={'r':'support','id':ticket_id17}).text)
check('017 unrelated member cannot enumerate ticket',other.get(base+'/index.php',params={'r':'support','id':ticket_id17}).status_code==404)
check('017 ticket not in another member list','HTTP assistance seventeen' not in other.get(base+'/index.php?r=support').text)
expect_post(other,'support_reply',404,ticket_id=ticket_id17,revision=1,body='Spoof',request_key=os.urandom(16).hex())
expect_post(admin,'support_template',title='HTTP reply template',body='A considered response.',active='1')
check('017 staff template visible in reply form','A considered response.' in admin.get(base+'/admin/index.php',params={'tab':'support','id':ticket_id17}).text)
reply_key17=os.urandom(16).hex()
expect_post(admin,'support_reply',ticket_id=ticket_id17,revision=1,body='STAFF-RESPONSE-017',request_key=reply_key17)
expect_post(admin,'support_reply',ticket_id=ticket_id17,revision=1,body='STAFF-RESPONSE-017',request_key=reply_key17)
check('017 staff reply visible only to owner','STAFF-RESPONSE-017' in user.get(base+'/index.php',params={'r':'support','id':ticket_id17}).text)
check('017 staff reply sets waiting state',db_one('SELECT status FROM cy_support_tickets WHERE id=?',(ticket_id17,))['status']=='waiting')
expect_post(user,'support_reply',409,ticket_id=ticket_id17,revision=1,body='Stale',request_key=os.urandom(16).hex())
expect_post(user,'support_status',ticket_id=ticket_id17,revision=2,status='closed')
expect_post(user,'support_reply',409,ticket_id=ticket_id17,revision=3,body='Closed',request_key=os.urandom(16).hex())
expect_post(user,'support_status',ticket_id=ticket_id17,revision=3,status='open')
expect_post(user,'support_reply',ticket_id=ticket_id17,revision=4,body='Thank you.',request_key=os.urandom(16).hex())
check('017 private ticket is noindex','noindex' in html(user,'/index.php?r=support&id='+str(ticket_id17)).select_one('meta[name=robots]')['content'])

# Physical order tracking from real wallet checkout, without an external provider.
physical17=create_content(title='HTTP parcel seventeen',kind='product',product_type='physical',price_currency='balance',price='0',inventory='3')
expect_post(user,'checkout_review',gateway='wallet',lines=json.dumps([{'item_id':physical17,'kind':'content','quantity':1}]),recipient='Alice',phone='0000000000',address='TEST-ADDRESS-017',region='CN')
intent17=html(user,'/index.php?r=checkout_review').select_one('input[name=intent]')['value']
expect_post(user,'checkout_confirm',intent=intent17,confirm='1')
parcel17=db_one('SELECT * FROM cy_orders WHERE user_id=? AND item_id=? ORDER BY id DESC',(uid,physical17))
expect_post(admin,'tracking_attach',order_id=parcel17['id'],revision=0,carrier='shunfeng',tracking_number='SFHTTP0017')
shipment17=db_one('SELECT * FROM cy_shipments WHERE order_id=?',(parcel17['id'],))
now17=datetime.now(timezone.utc)
expect_post(admin,'tracking_event',shipment_id=shipment17['id'],revision=shipment17['revision'],event_at=now17.strftime('%Y-%m-%dT%H:%M:%SZ'),description='TIMELINE-HTTP-017')
check('017 owner sees carrier timeline','TIMELINE-HTTP-017' in user.get(base+'/index.php',params={'r':'tracking','id':parcel17['id']}).text)
check('017 other account cannot see carrier timeline',other.get(base+'/index.php',params={'r':'tracking','id':parcel17['id']}).status_code in [403,404])
check('017 tracking update does not confirm receipt',db_one('SELECT status FROM cy_orders WHERE id=?',(parcel17['id'],))['status']=='paid')
expect_post(admin,'tracking_refresh',400,shipment_id=shipment17['id'])
expect_post(admin,'tracking_attach',409,order_id=parcel17['id'],revision=0,carrier='shunfeng',tracking_number='STALE')

# Strict normalized statement, report only; no counterfeit credits.
start17=(now17-timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ')
end17=(now17+timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ')
csv17='event_type,reference,trade_id,currency,amount_minor,state,event_at\npayment,UNKNOWNHTTP17,REMOTEHTTP17,CNY,100,succeeded,'+now17.strftime('%Y-%m-%dT%H:%M:%SZ')+'\n'
balance17=db_one('SELECT balance FROM cy_users WHERE id=?',(uid,))['balance']
rr17=expect_post(admin,'statement_import',gateway='alipay_direct',csv=csv17,starts_at=start17,ends_at=end17)
batch17=int(parse_qs(urlparse(rr17.json()['redirect']).query)['id'][0])
expect_post(admin,'statement_import',gateway='alipay_direct',csv=csv17,starts_at=start17,ends_at=end17)
check('017 identical statement import idempotent',db_one('SELECT COUNT(*) n FROM cy_recon_batches WHERE id=?',(batch17,))['n']==1)
check('017 unmatched statement marked external-only',db_one('SELECT outcome FROM cy_recon_rows WHERE batch_id=?',(batch17,))['outcome']=='external_only')
expect_post(admin,'statement_check',batch_id=batch17)
check('017 statement result screen renders',admin.get(base+'/admin/index.php',params={'tab':'reconciliation','id':batch17}).status_code==200)
check('017 statement import cannot credit money',db_one('SELECT balance FROM cy_users WHERE id=?',(uid,))['balance']==balance17)
expect_post(admin,'statement_import',400,gateway='alipay_direct',csv=csv17.replace('UNKNOWNHTTP17','=FORMULA'),starts_at=start17,ends_at=end17)
# Exercise multipart upload parser with a distinct, still untrusted, UTF-8 CSV.
rr17=admin.post(base+'/action.php',data={'action':'statement_import','csrf':csrf(admin),'gateway':'stripe','starts_at':start17,'ends_at':end17},files={'statement':('statement.csv',csv17.encode(),'text/csv')},headers={'Accept':'application/json'})
check('017 statement multipart import accepted',rr17.status_code==200,rr17.text)
save_group('modules',courses_enabled='0',support_enabled='0')
check('017 courses switch closes public routes',guest.get(base+'/index.php?r=courses').status_code==403)
check('017 support switch closes member route',user.get(base+'/index.php?r=support').status_code==403)
save_group('modules',courses_enabled='1',support_enabled='1')
