"""Actual PHP pages in offline Chromium. Native forms are tested separately by HTTP."""
ids18=json.loads(Path(os.environ['CY_TEST_IDS']).read_text())['parcels018']
post16('locale_select',locale='zh-CN',scope='admin')
post16('locale_select',locale='zh-CN',scope='site')
page.emulate_media(reduced_motion='reduce')
paths18=[
    ('recovery-vault','/admin/index.php?tab=backups'),
    ('search-connector','/admin/index.php?tab=search_index'),
    ('parcels-console','/admin/index.php?tab=logistics&order_id='+str(ids18['order'])+'&parcel='+str(ids18['parcels'][0])),
    ('parcel-journey','/index.php?r=tracking&id='+str(ids18['order'])+'&parcel='+str(ids18['parcels'][1])),
    ('safety-controls','/admin/index.php?tab=settings&group=security'),
]
for name18,path18 in paths18:
    page.set_content(snapshot(path18),wait_until='load')
    for width18 in [320,390,768,1440]:
        page.set_viewport_size({'width':width18,'height':1000 if width18>800 else 844})
        check('018 '+name18+' no horizontal overflow '+str(width18),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
    check('018 '+name18+' all native forms have action and CSRF',page.locator('form[method=post]').evaluate_all('(fs)=>fs.every(f=>!!f.querySelector("[name=action]")&&!!f.querySelector("[name=csrf]"))'))
    check('018 '+name18+' has main heading',page.locator('h1').count()>0)
    capture(path=str(OUT/(name18+'-desktop.png')))
    page.set_viewport_size({'width':390,'height':844});capture(path=str(OUT/(name18+'-mobile.png')))
page.set_content(snapshot('/admin/index.php?tab=backups'),wait_until='load')
page.set_viewport_size({'width':1440,'height':1000})
check('018 namespaced backup grid has two desktop columns',len(page.locator('.recovery-vault-grid').evaluate('(n)=>getComputedStyle(n).gridTemplateColumns').split())==2)
page.set_viewport_size({'width':390,'height':844})
check('018 namespaced backup grid stacks on phones',len(page.locator('.recovery-vault-grid').evaluate('(n)=>getComputedStyle(n).gridTemplateColumns').split())==1)
check('018 backup confirm matches server minimum',page.locator('[name=backup_password]').get_attribute('minlength')=='16' and page.locator('[name=backup_confirm]').get_attribute('minlength')=='16')
check('018 sensitive backup actions require password',page.locator('form').filter(has=page.locator('[name=action][value=backup_download]')).locator('[name=current_password]').count()==1)
check('018 sensitive forms do not opt into AJAX',page.locator('form').filter(has=page.locator('[name=action][value=backup_create]')).get_attribute('data-async') is None)
page.locator('.vault-copy summary').first.focus();page.keyboard.press('Enter')
check('018 recovery actions expand with keyboard',page.locator('.vault-copy details').first.get_attribute('open') is not None)
page.set_content(snapshot(paths18[2][1]),wait_until='load')
check('018 both parcel cards and add control rendered',page.locator('.parcel-card').count()==3)
check('018 one selected parcel has semantic current state',page.locator('.parcel-card[aria-current=page]').count()==1)
page.emulate_media(reduced_motion='no-preference');page.evaluate('document.documentElement.dataset.motion="1"')
page.locator('.parcel-card').first.hover();page.wait_for_timeout(450)
check('018 parcel hover uses restrained movement',page.locator('.parcel-card').first.evaluate('(n)=>getComputedStyle(n).transform')!='none')
page.emulate_media(reduced_motion='reduce')
check('018 reduced motion cancels parcel transforms',page.locator('.parcel-card').first.evaluate('(n)=>getComputedStyle(n).transform')=='none')
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot(paths18[0][1]),wait_until='load');page.evaluate('document.documentElement.dataset.mode="dark"')
capture(path=str(OUT/'recovery-vault-dark.png'))
post16('locale_select',locale='en-US',scope='admin')
page.set_content(snapshot(paths18[0][1]),wait_until='load')
check('018 recovery vault English copy is complete','Keep a way back.' in page.locator('h1').inner_text())
capture(path=str(OUT/'recovery-vault-english.png'))
page.set_content(snapshot(paths18[1][1]),wait_until='load')
check('018 search connector has manual and progressive controls',page.locator('[data-remote-run]').count()==1 and page.locator('[data-remote-stop]').count()==1)
capture(path=str(OUT/'search-connector-english.png'))
post16('locale_select',locale='zh-CN',scope='admin')
