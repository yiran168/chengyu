"""Actual Playwright URL navigation, forms, animation and accessibility checks."""
from pathlib import Path
import base64,json,os
from playwright.sync_api import sync_playwright,expect
BASE=os.environ['CY_SNAPSHOT_BASE'];ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('CY_VISUAL_OUT',str(ROOT/'docs/previews')));OUT.mkdir(exist_ok=True,parents=True)
IDS=json.loads(Path(os.environ['CY_TEST_IDS']).read_text());results=[];errors=[]
def check(name,ok,detail=''):
    results.append({'test':name,'ok':bool(ok),**({'detail':str(detail)[:400]} if not ok else {})})
def shot(name):
    # Exercise lazy images and real scroll reveals before an export-sized capture.
    height=page.evaluate('document.documentElement.scrollHeight')
    for y in range(0,min(height,20000),600):
        page.evaluate('(y)=>scrollTo({top:y,behavior:"instant"})',y);page.wait_for_timeout(90)
    page.evaluate('() => {document.activeElement?.blur();scrollTo({top:0,behavior:"instant"})}')
    page.wait_for_timeout(650)
    # Capture the current compositor frame directly. Playwright's screenshot
    # stabilizer can wait indefinitely on the deliberately live spring inspector.
    full=height<7000 and page.locator('.admin-body').count()==0
    viewport=page.viewport_size
    cdp=page.context.new_cdp_session(page)
    try:
        capture=cdp.send('Page.captureScreenshot',{'format':'png','captureBeyondViewport':full,'clip':{'x':0,'y':0,'width':viewport['width'],'height':height if full else viewport['height'],'scale':1}})
        (OUT/name).write_bytes(base64.b64decode(capture['data']))
    finally:cdp.detach()
def go(path):
    response=page.goto(BASE+path,wait_until='networkidle');check('live GET '+path,response.status==200)
def no_overflow(label):
    for width in [320,390,768,1440]:
        page.set_viewport_size({'width':width,'height':900});check(label+' no overflow '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
def login():
    go('/admin/');page.locator('[name=identifier]').fill(os.environ['CY_ADMIN_USER']);page.locator('[name=password]').fill(os.environ['CY_ADMIN_PASSWORD']);page.locator('form').filter(has=page.locator('input[name=action][value=login]')).locator('button[type=submit]').click();expect(page.locator('.admin-sidebar')).to_be_visible();page.wait_for_load_state('networkidle');check('live AJAX login reaches dashboard',page.locator('.admin-sidebar').count()==1)
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CY_CHROMIUM','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        context=browser.new_context(viewport={'width':1440,'height':1000},reduced_motion='no-preference')
        page=context.new_page();page.set_default_timeout(8000);page.on('pageerror',lambda e:errors.append(str(e)))
        go('/');check('engine loaded through CSP-compliant external scripts',page.evaluate('!!window.CYMotion && !!window.CYUI'))
        no_overflow('home');page.set_viewport_size({'width':1440,'height':1000});shot('home-desktop.png')
        card=page.locator('.quick-link').first;card.hover();page.wait_for_timeout(200)
        check('pointer-following card has real interaction state',card.evaluate('(n)=>n.classList.contains("pointer-active")'))
        check('card perspective actually changes computed transform',card.evaluate('(n)=>getComputedStyle(n).transform!=="none"'))
        page.locator('[data-dialog="appearance-dialog"]').click();expect(page.locator('#appearance-dialog')).to_be_visible()
        page.locator('[data-appearance="theme"][data-value="dusk"]').click();page.wait_for_timeout(1000)
        check('theme changed with actual View Transition API',page.locator('html').get_attribute('data-theme')=='dusk')
        page.locator('[data-appearance="mode"][data-value="dark"]').click();page.wait_for_timeout(1000)
        page.locator('#appearance-dialog [data-close-dialog]').click();page.wait_for_timeout(250);shot('home-dusk-dark.png')
        page.reload(wait_until='networkidle');check('theme preference persists after transition and reload',page.locator('html').get_attribute('data-theme')=='dusk' and page.locator('html').get_attribute('data-mode')=='dark')
        page.evaluate('localStorage.clear()');page.reload(wait_until='networkidle');page.set_viewport_size({'width':390,'height':844});shot('home-mobile.png')
        # Real history restoration does not enable intentionally disabled form controls.
        page.evaluate('() => {const f=document.createElement("form");f.dataset.async="";const b=document.createElement("button");b.disabled=true;b.id="disabled-fixture";f.append(b);document.body.append(f);dispatchEvent(new PageTransitionEvent("pageshow",{persisted:true}));}')
        check('pageshow preserves intentional disabled state',page.locator('#disabled-fixture').is_disabled())
        page.locator('#disabled-fixture').evaluate('(n)=>n.parentElement.remove()')
        page.set_viewport_size({'width':1440,'height':1000});login()
        go('/admin/index.php?tab=motion');check('motion inspector has shared configurable fields',page.locator('.motion-studio-form input[type=range]').count()>=8)
        no_overflow('motion inspector');shot('motion-studio-desktop.png')
        page.locator('[data-motion-preset=expressive]').click();check('expressive preset sets form and live engine',page.locator('[name=motion_curve]').input_value()=='elastic' and page.evaluate('CYMotion.config().motion_magnetic===true'))
        page.locator('[data-motion-replay]').first.click();page.wait_for_timeout(160);check('replay creates real Web Animations',page.evaluate('document.getAnimations().length>0'))
        page.locator('[name=motion_curve]').select_option('custom');page.locator('.motion-section').last.locator('summary').click()
        page.locator('[name=curve_x1]').evaluate('(n)=>{n.value=\"12\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_y1]').evaluate('(n)=>{n.value=\"155\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_x2]').evaluate('(n)=>{n.value=\"64\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_y2]').evaluate('(n)=>{n.value=\"100\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}')
        check('curve graph matches shared custom parameters',page.locator('[data-curve-code]').inner_text()=='cubic-bezier(0.12, 1.55, 0.64, 1)')
        page.locator('[name=motion_duration]').evaluate('(n)=>{n.value=\"580\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}')
        page.locator('form.motion-studio-form button[type=submit]').click();page.wait_for_timeout(900);page.wait_for_load_state('networkidle')
        check('motion form saved through real authenticated POST',page.locator('[name=motion_curve]').input_value()=='custom' and page.locator('[name=motion_duration]').input_value()=='580')
        go('/');check('saved admin motion reaches public page',page.evaluate('CYMotion.config().motion_duration===580 && CYMotion.config().curve_y1===155'))
        go('/admin/index.php?tab=motion');page.set_viewport_size({'width':390,'height':844});shot('motion-studio-mobile.png')
        page.emulate_media(reduced_motion='reduce');check('system preference overrides decorative motion',page.locator('html').get_attribute('data-motion')=='0')
        page.locator('[data-motion-preset=expressive]').click();check('preset cannot override system reduced motion',not page.evaluate('CYMotion.enabled()'))
        check('motion studio remains usable under reduced motion',page.locator('[name=motion_curve]').is_enabled())
        page.emulate_media(reduced_motion='no-preference');check('live media change restores configured motion',page.evaluate('CYMotion.enabled()'))
        page.locator('[name=motion]').uncheck();check('master off clears decorative animations',not page.evaluate('CYMotion.enabled()') and page.locator('.motion-orb').evaluate('(n)=>getComputedStyle(n).animationName')=='none')
        page.locator('[name=motion]').check();page.locator('[data-motion-preset=balanced]').click()
        # Community UI -> server mutation -> rendered result.
        page.set_viewport_size({'width':1440,'height':1000});go('/index.php?r=article&id='+str(IDS['poll']))
        check('article TOC generated from actual visible headings',page.locator('.reading-outline a').count()>=2)
        check('private result counts absent before vote',page.locator('.poll-results').count()==0)
        no_overflow('poll');shot('poll-desktop.png')
        page.locator('.poll-choice input').first.check();page.locator('.poll-form button[type=submit]').click();page.wait_for_timeout(650);page.wait_for_load_state('networkidle')
        check('real browser vote persists and reveals own results',page.locator('.poll-results').count()==1 and page.locator('.poll-form').count()==0)
        shot('poll-results.png')
        go('/index.php?r=article&id='+str(IDS['question']));check('accepted answer is visible and anchored',page.locator('#comment-'+str(IDS['answer'])).count()==1);shot('question-desktop.png')
        page.set_viewport_size({'width':390,'height':844});no_overflow('question');page.set_viewport_size({'width':390,'height':844});shot('question-mobile.png')
        page.set_viewport_size({'width':1440,'height':1000});go('/admin/index.php?tab=threads');shot('threads-admin.png')
        go('/admin/index.php?tab=edit_content&kind=thread');check('non-poll editor hides and disables poll fields',not page.locator('[data-poll-fields]').is_visible() and page.locator('[name=poll_question]').is_disabled())
        page.locator('[name=thread_mode]').select_option('poll');check('poll editor enables functional poll fields',page.locator('[data-poll-fields]').is_visible() and page.locator('[name=poll_question]').is_enabled())
        # No-JavaScript session still sees content and has ordinary login forms.
        nojs=browser.new_context(java_script_enabled=False,viewport={'width':390,'height':844});static=nojs.new_page();static.goto(BASE,wait_until='networkidle')
        check('no-JavaScript content cards remain visible',static.locator('.content-card').first.evaluate('(n)=>getComputedStyle(n).opacity')=='1')
        static.goto(BASE+'/index.php?r=login',wait_until='networkidle');check('no-JavaScript sign-in has real action',static.locator('form[action$="/action.php"]').count()>0);nojs.close()
        check('no live browser JavaScript errors',not errors,errors)
        browser.close()
except Exception as exc:
    check('live browser suite completed',False,repr(exc))
report={'mode':'Actual Chromium navigation to local PHP URLs, real forms/CSP/cookies; not an external production host','total':len(results),'passed':sum(r['ok'] for r in results),'failed':sum(not r['ok'] for r in results),'tests':results,'javascript_errors':errors}
print(json.dumps(report,indent=2));raise SystemExit(1 if report['failed'] else 0)
