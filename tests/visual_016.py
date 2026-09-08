"""0.16 actual PHP output; responsive and native-form checks, offline browser DOM."""
from urllib.parse import parse_qs,urlparse

def post16(action,**values):
    raw=SESSION.get(BASE+'/').text
    token=BeautifulSoup(raw,'html.parser').select_one('meta[name=csrf-token]')['content']
    response=SESSION.post(BASE+'/action.php',data={'action':action,'csrf':token,**values},headers={'Accept':'application/json'},timeout=15)
    response.raise_for_status()
    return response.json()

post16('locale_select',locale='zh-CN',scope='admin')
item16=post16('admin_content',kind='article',title='Orbit / resource 016',body='A small resource for an intentional checkout.',status='published',access_level='paid',price_currency='balance',price='0',inventory='-1')
cid16=int(parse_qs(urlparse(item16['redirect']).query)['id'][0])
post16('checkout_review',gateway='wallet',lines=json.dumps([{'item_id':cid16,'kind':'content','quantity':1}]))
page_doc16={'format':'chengyu-layout','version':1,'blocks':[{'id':'hero016','type':'hero','title':'A place for ideas to grow','text':'Read. Make. Share. / A considered space for curious people.','route':'articles','label':'Explore stories'},{'id':'faq016','type':'faq','title':'A little clarity','text':'Can I build my own page?|Yes. Compose safe, editable sections.\nDoes checkout need JavaScript?|No. Native forms remain available.'},{'id':'feature016','type':'content','kind':'article','title':'Selected reading','size':3}]}
post16('admin_layout',slot='page1',revision='0',document=json.dumps(page_doc16))
new_pages16=[('commerce-console','/admin/index.php?tab=commerce'),('shipping-studio','/admin/index.php?tab=shipping'),('service-console','/admin/index.php?tab=service'),('media-workbench','/admin/index.php?tab=media_lab'),('translation-editor','/admin/index.php?tab=translations&content_id='+str(cid16)),('checkout','/index.php?r=checkout&kind=content&item_id='+str(cid16)),('checkout-review','/index.php?r=checkout_review'),('guest-desk','/index.php?r=guest_orders'),('composed-page','/index.php?r=landing&slot=page1'),('page-builder','/admin/index.php?tab=builder&slot=page1')]
page.emulate_media(reduced_motion='reduce')
for name16,path16 in new_pages16:
    page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot(path16),wait_until='load')
    for width16 in [320,390,768,1440]:
        page.set_viewport_size({'width':width16,'height':1000 if width16>800 else 844})
        check('016 '+name16+' no horizontal overflow '+str(width16),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
    capture(path=str(OUT/(name16+'-desktop.png')))
    page.set_viewport_size({'width':390,'height':844});capture(path=str(OUT/(name16+'-mobile.png')))
    check('016 '+name16+' native forms include CSRF',page.locator('form[method=post]').evaluate_all('(forms)=>forms.every(f=>!!f.querySelector("[name=csrf]"))'))
page.set_content(snapshot('/admin/index.php?tab=media_lab'),wait_until='load')
check('016 media module loaded without uploading',page.locator('[data-media-workbench]').count()==1)
check('016 workbench contains file input',page.locator('input[type=file]').count()>0)
page.set_content(snapshot('/index.php?r=checkout_review'),wait_until='load')
page.set_viewport_size({'width':390,'height':844})
check('016 mobile checkout aside stays in document flow',page.locator('.commerce-aside').evaluate('(n)=>getComputedStyle(n).position==="static"&&n.getBoundingClientRect().bottom<=document.querySelector(".site-footer").getBoundingClientRect().top'))
check('016 final checkout remains non-async explicit native form',page.locator('input[value=checkout_confirm]').evaluate('(n)=>!n.form.hasAttribute("data-async")&&!!n.form.querySelector("[name=confirm]")'))
post16('locale_select',locale='en-US',scope='admin')
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot('/admin/index.php?tab=media_lab'),wait_until='load')
check('016 English language document matches locale',page.locator('html').get_attribute('lang')=='en-US')
check('016 English translated workbench copy','Large files, controlled delivery' in page.locator('body').inner_text() or 'Media workbench' in page.locator('body').inner_text())
capture(path=str(OUT/'media-workbench-english.png'))
post16('locale_select',locale='zh-CN',scope='admin')
