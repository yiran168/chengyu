"""0.17 actual PHP-rendered pages and original demonstration content.
Offline DOM checks are not a substitute for full URL/CSP/cookie integration."""
from datetime import datetime, timezone, timedelta
# Scoped preferences, instead of depending on the previously visited page.
post16('locale_select',locale='zh-CN',scope='admin')
post16('locale_select',locale='zh-CN',scope='site')

def id17(result):
    return int(parse_qs(urlparse(result['redirect']).query)['id'][0])
def form_values17(path,action):
    soup=BeautifulSoup(SESSION.get(BASE+path).text,'html.parser')
    form=soup.select_one('input[value="'+action+'"]').find_parent('form')
    return {n['name']:n.get('value','') for n in form.select('input[type=hidden][name]')}
titles17=['把灵感，变成可以分享的作品','从零开始，搭建自己的内容空间','更清晰的表达，更温暖的交流']
descriptions17=['用三个章节，整理想法、构建结构，再给作品一点恰到好处的细节。','认识栏目、权限与发布流程，让小站以自己的节奏慢慢成长。','让文字成为人与人之间的连接。从一次认真阅读，到一句真诚回应。']
courses17=[];lessons17=[]
for num17,title17 in enumerate(titles17):
    source17=id17(post16('admin_content',kind='article',title=title17,excerpt=descriptions17[num17],body='这是独立编写的界面演示课程。上线前请替换为自己的内容。',status='published',access_level='public',price_currency='balance',price='0',cover='cover-'+str(num17+1)+'.svg'))
    course17=id17(post16('course_save',content_id=source17,title=title17,description=descriptions17[num17],active='1'));courses17.append(course17)
    for step17,lesson_title17 in enumerate(['先给想法一个位置','搭建清晰的内容结构','让细节轻轻发光']):
        post16('lesson_save',course_id=course17,title=lesson_title17,body='## 从一张空白的纸开始\n\n不必一开始就拥有完整答案。先把今天最想留下的一件小事写下来，让想法有一个可以安放的位置。\n\n## 把复杂的事情，拆成清晰的步骤\n\n1. 说清楚：这次创作是为了帮助谁。\n2. 留下来：整理三个真正有用的发现。\n3. 再打磨：删掉多余的说明，保留必要的细节。\n\n> 好的节奏，不是一直向前冲，而是知道在哪里停一下。\n\n## 留一点自己的思考\n\n读完这一节，记录一个准备尝试的小行动。笔记仅自己可见，记得点击保存。',duration_seconds=720+step17*240,sort_order=step17,active='1',preview='1' if step17==0 else '0')
    soup17=BeautifulSoup(SESSION.get(BASE+'/admin/index.php?tab=learning&id='+str(course17)).text,'html.parser')
    link17=soup17.select_one('.lesson-outline a')
    lesson17=int(parse_qs(urlparse(link17['href']).query)['lesson'][0]);lessons17.append(lesson17)
post16('learning_progress',lesson_id=lessons17[0],revision=0,position_seconds=245,note='先把首页的三件事讲清楚：这里有什么、适合谁、从哪里开始。')
ticket17=id17(post16('support_create',subject='关于课程资料与阅读进度的小问题',body='我已经开始第一节课程，希望在手机与电脑之间接着学习。请问如何保存阅读位置？',request_key=os.urandom(16).hex()))
post16('support_reply',ticket_id=ticket17,revision=1,body='欢迎开始新的学习旅程。\n\n在章节下方保存进度和笔记，登录同一账号后就能继续。媒体暂停时会填入播放位置，点击保存后才会同步到账户。',request_key=os.urandom(16).hex())
post16('support_template',title='学习进度说明',body='请打开章节下方的进度区域，保存后再切换设备。笔记仅账户本人可见。',active='1')
d17=datetime.now(timezone.utc)
csv17='event_type,reference,trade_id,currency,amount_minor,state,event_at\npayment,DEMO017,PROVIDER017,CNY,9900,succeeded,'+d17.strftime('%Y-%m-%dT%H:%M:%SZ')+'\n'
batch17=id17(post16('statement_import',gateway='alipay_direct',csv=csv17,starts_at=(d17-timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ'),ends_at=(d17+timedelta(days=1)).strftime('%Y-%m-%dT%H:%M:%SZ')))
draft_doc17={'format':'chengyu-layout','version':1,'blocks':[{'id':'hello17','type':'heading','title':'让每一个新想法，都有开始的地方','text':'先在草稿里打磨，准备好了再与大家见面。'}]}
post16('admin_layout',slot='page11',revision=0,draft_revision=0,operation='draft',document=json.dumps(draft_doc17))
pages17=[
('learning-library','/index.php?r=courses'),
('course-overview','/index.php?r=course&id='+str(courses17[0])),
('lesson-reader','/index.php?r=learn&id='+str(lessons17[0])),
('learning-progress','/index.php?r=learning'),
('course-studio','/admin/index.php?tab=learning&id='+str(courses17[0])+'&lesson='+str(lessons17[0])),
('search-workspace','/admin/index.php?tab=search_index'),
('logistics-workspace','/admin/index.php?tab=logistics'),
('statement-workspace','/admin/index.php?tab=reconciliation&id='+str(batch17)),
('support-desk','/index.php?r=support'),
('support-conversation','/index.php?r=support&id='+str(ticket17)),
('support-workspace','/admin/index.php?tab=support'),
('layout-draft','/admin/index.php?tab=builder&slot=page11')]
page.emulate_media(reduced_motion='reduce')
for name17,path17 in pages17:
    page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot(path17),wait_until='load')
    for width17 in [320,390,768,1440]:
        page.set_viewport_size({'width':width17,'height':1000 if width17>800 else 844})
        check('017 '+name17+' no horizontal overflow '+str(width17),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
    check('017 '+name17+' native forms include CSRF',page.locator('form[method=post]').evaluate_all('(forms)=>forms.every(f=>!!f.querySelector("[name=csrf]"))'))
    check('017 '+name17+' main heading present',page.locator('h1').count()>0)
    capture(path=str(OUT/(name17+'-desktop.png')))
    page.set_viewport_size({'width':390,'height':844});capture(path=str(OUT/(name17+'-mobile.png')))
page.set_content(snapshot('/index.php?r=learn&id='+str(lessons17[0])),wait_until='load')
check('017 private progress fields and revision rendered',page.locator('form.learning-progress-form [name=revision]').input_value()=='1')
check('017 persisted note visible to its owner','先把首页' in page.locator('textarea[name=note]').input_value())
check('017 mobile lesson outline not fixed',page.locator('.learning-outline').evaluate('(n)=>getComputedStyle(n).position')=='static')
page.set_content(snapshot('/admin/index.php?tab=builder&slot=page11'),wait_until='load')
check('017 builder offers distinct draft and publish submits',page.locator('button[name=operation][value=draft]').count()==1 and page.locator('button[name=operation][value=publish]').count()==1)
page.set_content(snapshot('/admin/index.php?tab=search_index'),wait_until='load')
check('017 index no-script manual batch action exists',page.locator('.platform-job button[type=submit]').count()>0)
check('017 index resumable progressive enhancement controls exist',page.locator('[data-index-run]').count()==1 and page.locator('[data-index-stop]').count()==1)
page.set_content(snapshot('/admin/index.php?tab=support&id='+str(ticket17)),wait_until='load')
template17=page.locator('[data-reply-template]')
before17=page.locator('[data-support-body]').input_value()
template17.select_option(index=1)
check('017 template inserts editable text without submitting',len(page.locator('[data-support-body]').input_value())>len(before17) and template17.input_value()=='')
post16('locale_select',locale='en-US',scope='admin')
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot('/admin/index.php?tab=learning'),wait_until='load')
check('017 independent English admin language',page.locator('html').get_attribute('lang')=='en-US' and 'New course' in page.locator('body').inner_text())
capture(path=str(OUT/'course-studio-english.png'))
page.set_content(snapshot('/index.php?r=courses'),wait_until='load')
check('017 frontend stays Chinese while admin is English',page.locator('html').get_attribute('lang')=='zh-CN')
check('017 shared hero receives translated Chinese subtitle','小小章节' in page.locator('.platform-hero-copy p').inner_text())
page.emulate_media(reduced_motion='no-preference')
page.evaluate('document.documentElement.dataset.motion="1"')
card17=page.locator('.course-card').first
card17.hover();page.wait_for_timeout(450)
check('017 original course card lift active on pointer hover',card17.evaluate('(n)=>getComputedStyle(n).transform')!='none')
page.emulate_media(reduced_motion='reduce')
check('017 reduced motion disables course card transforms',card17.evaluate('(n)=>getComputedStyle(n).transform')=='none')
page.evaluate('document.documentElement.dataset.mode="dark"')
capture(path=str(OUT/'learning-library-dark.png'))
post16('locale_select',locale='zh-CN',scope='admin')
