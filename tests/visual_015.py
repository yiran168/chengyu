"""Real rendered HTML, offline JS; no provider or browser network success is claimed."""
ids15=json.loads(Path(os.environ['CY_TEST_IDS']).read_text())
page.emulate_media(reduced_motion='reduce');page.evaluate('try{localStorage.clear()}catch(e){}')
new_pages15=[('integrations','/admin/index.php?tab=integrations'),('configuration','/admin/index.php?tab=configuration&preview='+str(ids15['settings_snapshot'])),('badges-admin','/admin/index.php?tab=badges'),('badges','/index.php?r=badges'),('connections-security','/index.php?r=security'),('lifetime-membership','/index.php?r=membership')]
for name,route in new_pages15:
    page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot(route),wait_until='load')
    for width in [320,390,768,1440]:
        page.set_viewport_size({'width':width,'height':1000 if width>800 else 844})
        check('015 '+name+' no horizontal overflow '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
    capture(path=str(OUT/(name+'-desktop.png')))
    page.set_viewport_size({'width':390,'height':844});capture(path=str(OUT/(name+'-mobile.png')))
    check('015 '+name+' no fixture secrets in DOM', 'preview-only-not-a-credential' not in page.content())
# Native grouped navigation: search reveals hidden groups and restores actual user choices.
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot('/admin/index.php?tab=integrations'),wait_until='load')
check('015 active group opens on first render',page.locator('details[open] a[aria-current=page]').count()==1)
initial15=page.locator('[data-nav-section]').evaluate_all('(groups)=>groups.map(g=>g.open)')
search15=page.locator('[data-admin-search]');page.keyboard.press('Alt+/');check('015 keyboard shortcut focuses backend search',search15.evaluate('(n)=>n===document.activeElement'))
search15.fill('\u5fbd\u7ae0');check('015 backend search opens matching section',page.locator('[data-nav-section]:not([hidden])').count()==1 and page.locator('[data-nav-section]:not([hidden])').evaluate('(g)=>g.open'))
check('015 backend search only exposes matching targets',page.locator('.admin-nav a:not([hidden])').count()==1)
page.keyboard.press('Escape');check('015 search Escape clears without losing focus',search15.input_value()=='' and search15.evaluate('(n)=>n===document.activeElement'))
check('015 clearing search restores prior group states',initial15==page.locator('[data-nav-section]').evaluate_all('(groups)=>groups.map(g=>g.open)'))
search15.fill('missing-control-xyz');check('015 no-match status is announced',page.locator('[data-admin-results]').is_visible() and page.locator('[data-nav-section]:not([hidden])').count()==0)
search15.fill('');summary15=page.locator('[data-nav-section] summary').nth(1);summary15.focus();page.keyboard.press('Enter');check('015 native section keyboard activation works',summary15.evaluate('(n)=>n.parentElement.open'))
page.set_viewport_size({'width':390,'height':844});page.keyboard.press('Alt+/');check('015 mobile shortcut opens menu before focusing search',page.locator('body').evaluate('(n)=>n.classList.contains("sidebar-open")') and search15.evaluate('(n)=>n===document.activeElement'))
page.keyboard.press('Escape');check('015 empty mobile search Escape closes menu',not page.locator('body').evaluate('(n)=>n.classList.contains("sidebar-open")'))
# Small finite emblems respect the shared curve, zero duration and motion preferences.
# Start a fresh browsing realm: set_content retains old document/media listeners.
page.close();page=browser.new_page(viewport={'width':1440,'height':1000},device_scale_factor=1,reduced_motion='reduce');page.set_default_timeout(5000);page.on('pageerror',lambda error:errors.append(str(error)))
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot('/index.php?r=badges'),wait_until='load');page.emulate_media(reduced_motion='no-preference');page.wait_for_timeout(100)
page.evaluate('document.documentElement.dataset.economy="0";CYMotion.configure({motion:true,motion_adaptive:false,motion_duration:420,motion_sheen:true})')
card15=page.locator('.recognition-card').first
card15.dispatch_event('pointerenter',{'pointerType':'mouse'});check('015 emblem hover starts finite shared animation',page.evaluate('CYConnections.active()===1'))
page.wait_for_timeout(700);check('015 emblem animation releases finished handles',page.evaluate('CYConnections.active()===0'))
page.evaluate('CYMotion.configure({motion_duration:0})');card15.dispatch_event('pointerenter',{'pointerType':'mouse'});check('015 zero duration skips emblem animation',page.evaluate('CYConnections.active()===0'))
page.evaluate('CYMotion.configure({motion_duration:420})');page.emulate_media(reduced_motion='reduce');card15.dispatch_event('pointerenter',{'pointerType':'mouse'});check('015 reduced motion skips emblem animation',page.evaluate('CYConnections.active()===0'))
page.emulate_media(reduced_motion='no-preference');page.evaluate('document.documentElement.dataset.economy="1"');card15.dispatch_event('pointerenter',{'pointerType':'mouse'});check('015 economy mode skips emblem animation',page.evaluate('CYConnections.active()===0'))
page.evaluate('document.documentElement.dataset.economy="0"');card15.dispatch_event('pointerenter',{'pointerType':'mouse'});page.evaluate('window.dispatchEvent(new Event("pagehide"))');check('015 pagehide cancels finite animation',page.evaluate('CYConnections.active()===0'))
page.emulate_media(reduced_motion='reduce')
# Native security-sensitive forms cannot accidentally send downloads through async UI.
page.set_content(snapshot('/admin/index.php?tab=configuration&preview='+str(ids15['settings_snapshot'])),wait_until='load')
check('015 snapshot export uses normal browser download form',page.locator('input[value=admin_snapshot_export]').first.evaluate('(n)=>!n.form.hasAttribute("data-async")'))
check('015 snapshot import uses multipart form',page.locator('input[value=admin_snapshot_import]').evaluate('(n)=>n.form.enctype==="multipart/form-data"'))
check('015 restore form includes revision and password',page.locator('input[value=admin_snapshot_restore]').evaluate('(n)=>!!n.form.querySelector("[name=revision]")&&!!n.form.querySelector("[name=current_password]")'))
# Public login screenshot is fetched with a separate anonymous cookie jar.
session15=SESSION;SESSION=requests.Session()
page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot('/index.php?r=login'),wait_until='load');capture(path=str(OUT/'connected-login-desktop.png'))
page.set_viewport_size({'width':390,'height':844});check('015 connected login no overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth'));capture(path=str(OUT/'connected-login-mobile.png'))
check('015 three external provider forms rendered',page.locator('input[value=oauth_begin]').count()==3)
check('015 external login has no CSRF-free navigation shortcut',page.locator('input[value=oauth_begin]').evaluate_all('(nodes)=>nodes.every(n=>n.form.method==="post"&&!!n.form.querySelector("[name=csrf]"))'))
page.set_content(snapshot('/index.php?r=register'),wait_until='load');check('015 registration shares local password and terms fields',page.locator('input[name=password]').count()==1 and page.locator('input[name=agree]').count()==1)
SESSION=session15
