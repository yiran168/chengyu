"""0.19 owner setup, real resource delivery and server-rendered layouts."""
check('019 resource administration',admin.get(base+'/admin/index.php?tab=resources').status_code==200)
check('019 resource administration rejects members',user.get(base+'/admin/index.php?tab=resources').status_code==403)
content19=create_content(title='019 downloadable guide',access_level='paid',price_currency='balance',price='0',protected_body='RESOURCE-BODY-019')
rr19=expect_post(admin,'resource_save',content_id=content19,revision=0,label='019 private edition',release_version='1.0',media_id=media16,active=1)
file19=rr19.json()['resource_id']
mirror_url19='https://downloads.example.test/019-private-edition.zip'
rr19=expect_post(admin,'resource_save',content_id=content19,revision=0,label='019 alternate mirror',mirror_url=mirror_url19,extraction_code='CODE-019',active=1)
mirror19=rr19.json()['resource_id']
expect_post(user,'resource_save',403,content_id=content19,label='forged',media_id=media16)
expect_post(admin,'resource_save',400,content_id=content19,label='ambiguous',media_id=media16,mirror_url=mirror_url19)
locked19=guest.get(base+'/index.php',params={'r':'article','id':content19})
check('019 locked article has no edition or mirror data','019 alternate mirror' not in locked19.text and mirror_url19 not in locked19.text)
check('019 resource route rechecks content permission',guest.get(base+'/index.php',params={'r':'resource','id':mirror19}).status_code==403)
expect_post(user,'checkout_review',gateway='wallet',lines=json.dumps([{'item_id':content19,'kind':'content','quantity':1}]))
intent19=html(user,'/index.php?r=checkout_review').select_one('input[name=intent]')['value']
expect_post(user,'checkout_confirm',intent=intent19,confirm='1')
unlocked19=user.get(base+'/index.php',params={'r':'article','id':content19})
check('019 unlocked edition metadata renders with protected mirror withheld','019 alternate mirror' in unlocked19.text and mirror_url19 not in unlocked19.text)
download19=user.get(base+'/index.php',params={'r':'resource','id':file19})
check('019 resource private file streams original multipart bytes',download19.status_code==200 and download19.content==blob16)
mirror_page19=user.get(base+'/index.php',params={'r':'resource','id':mirror19})
check('019 authorized mirror continuation reveals code without redirect',mirror_page19.status_code==200 and mirror_url19 in mirror_page19.text and 'CODE-019' in mirror_page19.text and mirror_page19.headers.get('Referrer-Policy')=='no-referrer')
rr19=expect_post(user,'resource_report',resource_id=mirror19,reason='outdated',body='Please refresh the mirror')
report19=rr19.json()['report_id']
expect_post(admin,'resource_resolve',id=report19,revision=1,status='resolved',resolution='The current edition is available.')
expect_post(admin,'resource_resolve',409,id=report19,revision=1,status='resolved',resolution='Do not send twice.')
expect_post(admin,'resource_save',id=mirror19,content_id=content19,revision=1,label='019 alternate mirror',mirror_url=mirror_url19,active=0)
check('019 disabled edition revokes direct route',user.get(base+'/index.php',params={'r':'resource','id':mirror19}).status_code==404)
blocks19=[{'id':'features019','type':'features','title':'FEATURE-019','items':[{'title':'One useful thing','text':'Original reusable entry','icon':'leaf'}]}, {'id':'tabs019','type':'tabs','title':'TABS-019','items':[{'title':'First panel','text':'NOJS-FIRST-019'},{'title':'Second panel','text':'NOJS-SECOND-019'}]}, {'id':'timeline019','type':'timeline','title':'TIMELINE-019','items':[{'title':'Begin here','text':'A real instruction'}]}, {'id':'categories019','type':'categories','title':'CATEGORIES-019'}, {'id':'plans019','type':'plans','title':'PLANS-019'}, {'id':'titles019','type':'titles','title':'TITLES-019'}, {'id':'notice019','type':'noticeboard','title':'NOTICES-019'}, {'id':'creators019','type':'creators','title':'CREATORS-019'}]
blocks19.extend([{'id':'slider019','type':'slider','title':'SLIDER-019','autoplay':True,'interval':6,'items':[{'title':'First slide','text':'SLIDE-FIRST-019'},{'title':'Second slide','text':'SLIDE-SECOND-019'}]}, {'id':'buttons019','type':'buttons','title':'BUTTONS-019','items':[{'title':'Official documentation','url':'https://www.php.net/','icon':'book'}]}])
layout19=db_one('SELECT revision FROM cy_layouts WHERE slot=?',('page12',))
expect_post(admin,'admin_layout',slot='page12',revision=layout19['revision'] if layout19 else 0,document=json.dumps({'format':'chengyu-layout','version':1,'blocks':blocks19}))
landing19=guest.get(base+'/index.php?r=landing&slot=page12')
check('019 all new dynamic blocks render without JavaScript',landing19.status_code==200 and all(x['title'] in landing19.text for x in blocks19) and 'NOJS-FIRST-019' in landing19.text and 'NOJS-SECOND-019' in landing19.text)
builder19=html(admin,'/admin/index.php?tab=builder&slot=page12')
labels19=json.loads(builder19.select_one('#studio-i18n').string)
check('019 studio renders all 22 block types and translated controls',len(builder19.select('[data-block-add]'))==22 and labels19.get('background_id')=='背景图片 ID（公开图片）')
listing19=guest.get(base+'/index.php?r=shop&currency=balance&min_price=0&sort=price')
check('019 native catalog filter and view controls',listing19.status_code==200 and 'data-catalog-view="list"' in listing19.text and 'name="min_price"' in listing19.text)
check('019 malformed price interval rejected',guest.get(base+'/index.php?r=shop&min_price=9&max_price=1').status_code==400)
for art19 in ['chengyu-mascot.webp','favicon.png','island-anime.webp','blue-hour.webp']:
    r19=guest.get(base+'/assets/art/'+art19)
    check('019 original anime asset '+art19,r19.status_code==200 and r19.headers['Content-Type'].startswith('image/'))

carousel19=BeautifulSoup(landing19.text,'html.parser')
check('019 native carousel preserves both slides without JavaScript',len(carousel19.select('.carousel-slide'))==2 and carousel19.select_one('[data-carousel-pause]') is not None and 'SLIDE-SECOND-019' in landing19.text)
check('019 button group emits real links',carousel19.select_one('.block-button-group a')['href']=='https://www.php.net/')
