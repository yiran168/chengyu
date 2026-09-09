"""Actual Playwright URL navigation, forms, animation and accessibility checks."""
from pathlib import Path
import json,os
from playwright.sync_api import sync_playwright,expect,TimeoutError as BrowserTimeout
BASE=os.environ['CY_SNAPSHOT_BASE'];ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('CY_VISUAL_OUT',str(ROOT/'docs/previews')));OUT.mkdir(exist_ok=True,parents=True)
IDS=json.loads(Path(os.environ['CY_TEST_IDS']).read_text());results=[];errors=[]
def check(name,ok,detail=''):
    results.append({'test':name,'ok':bool(ok),**({'detail':str(detail)[:400]} if not ok else {})})
def shot(name):
    if os.environ.get('CY_LIVE_CAPTURE')=='0':return
    # Exercise lazy images and real scroll reveals before an export-sized capture.
    height=page.evaluate('document.documentElement.scrollHeight')
    for y in range(0,min(height,20000),600):
        page.evaluate('(y)=>scrollTo({top:y,behavior:"instant"})',y);page.wait_for_timeout(90)
    page.evaluate('() => {document.activeElement?.blur();scrollTo({top:0,behavior:"instant"})}')
    page.wait_for_timeout(650)
    # Capture failure is recorded, but must not prevent the remaining form tests.
    full=height<7000 and page.locator('.admin-body').count()==0
    try:
        page.screenshot(path=str(OUT/name),full_page=full,animations='allow',timeout=10000)
        check('screenshot '+name,True)
    except BrowserTimeout as exc:
        check('screenshot '+name,False,str(exc))
def go(path):
    response=page.goto(BASE+path,wait_until='load',timeout=20000);check('live GET '+path,response.status==200)
def no_overflow(label):
    for width in [320,390,768,1440]:
        page.set_viewport_size({'width':width,'height':900});check(label+' no overflow '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))

def native_click(target):
    try: target.click()
    except BrowserTimeout:
        diagnostic=page.evaluate('''async () => {
          const frames=await Promise.race([new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve(true)))),new Promise(resolve=>setTimeout(()=>resolve(false),1200))]);
          return {frames,hidden:document.hidden,focus:document.hasFocus(),width:innerWidth,height:innerHeight,animations:document.getAnimations().map(a=>({state:a.playState,target:a.effect?.target?.className})).slice(0,12)};
        }''')
        check('click diagnostic',False,json.dumps(diagnostic));raise

def submit_native(target):
    try:
        with page.expect_navigation(wait_until='load',timeout=20000):native_click(target)
    except BrowserTimeout:
        diagnostic=page.evaluate('''() => ({events:window.__formEvents,invalid:[...document.querySelectorAll('form input,form select')].filter(n=>!n.checkValidity()).map(n=>({name:n.name,value:n.type==='password'?'redacted':n.value,message:n.validationMessage})),active:document.activeElement?.outerHTML.slice(0,160)})''')
        check('native form diagnostic',False,json.dumps(diagnostic));raise
def login():
    go('/admin/');page.locator('[name=identifier]').fill(os.environ['CY_ADMIN_USER']);page.locator('[name=password]').fill(os.environ['CY_ADMIN_PASSWORD']);page.locator('form').filter(has=page.locator('input[name=action][value=login]')).locator('button[type=submit]').click();expect(page.locator('.admin-sidebar')).to_be_visible();page.wait_for_load_state('networkidle');check('live AJAX login reaches dashboard',page.locator('.admin-sidebar').count()==1)
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CY_CHROMIUM','/usr/bin/chromium'),headless=True,args=['--no-sandbox']+(['--disable-gpu','--disable-features=CalculateNativeWinOcclusion'] if os.name=='nt' else []))
        context=browser.new_context(viewport={'width':1440,'height':1000},reduced_motion='no-preference')
        page=context.new_page();page.set_default_timeout(8000);page.on('pageerror',lambda e:errors.append(str(e)))
        page.add_init_script("window.__formEvents=[];addEventListener('submit',e=>{window.__formEvents.push({action:e.target.querySelector('[name=action]')?.value,button:e.submitter?.name});},true);")
        go('/');check('engine loaded through CSP-compliant external scripts',page.evaluate('!!window.CYMotion && !!window.CYUI'))
        page.evaluate("() => {const b=document.createElement('button');b.type='button';b.className='btn';b.id='ripple-fixture';b.textContent='Ripple interaction';b.addEventListener('click',()=>b.dataset.clicked='1');document.querySelector('main').prepend(b);}")
        ripple=page.locator('#ripple-fixture');ripple.hover();before=ripple.bounding_box();page.mouse.down();during=ripple.bounding_box();page.mouse.up()
        check('ripple never expands the button hit area',during['width']<before['width']*1.1 and during['height']<before['height']*1.1)
        check('pointer press and release still trigger the button',ripple.get_attribute('data-clicked')=='1');ripple.evaluate('(n)=>n.remove()')
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
        go('/admin/index.php?tab=visuals&slot=icon:compass')
        check('visual studio renders all icon choices',page.locator('.symbol-grid .visual-tile').count()==79)
        page.locator('[data-visual-search]').fill('anime-')
        check('visual icon search filters without losing choices',page.locator('.symbol-grid .visual-tile:visible').count()==6)
        page.locator('[data-visual-search]').fill('')
        page.locator('[name=asset_key]').select_option('icon-community')
        check('built-in choice updates visual preview', 'icon-community.webp' in page.locator('[data-visual-preview] img').get_attribute('src'))
        submit_native(page.locator('.visual-form button[type=submit]').first)
        check('visual native save persists binding',page.locator('[name=asset_key]').input_value()=='icon-community')
        page.locator('.visual-form input[type=file]').set_input_files(str(ROOT/'site/assets/art/icon-shop.webp'))
        expect(page.locator('.visual-form [name=media_id]')).not_to_have_value('0')
        check('upload widget populates its named field and clears alternate source',page.locator('[name=asset_key]').input_value()=='' and int(page.locator('[name=media_id]').input_value())>0)
        check('uploaded image updates actual preview', 'media.php?id=' in page.locator('[data-visual-preview] img').get_attribute('src'))
        submit_native(page.locator('.visual-form button[type=submit]').first)
        submit_native(page.locator('[name=operation][value=reset]'))
        check('restore keeps new revision and removes custom source',page.locator('[name=media_id]').input_value()=='0' and page.locator('[name=asset_key]').input_value()=='')
        no_overflow('visual studio');shot('visual-studio-desktop.png')
        go('/index.php?r=archives');no_overflow('archive');shot('archive-desktop.png')
        page.locator('[name=period]').fill('2024-02')
        with page.expect_navigation(wait_until='load',timeout=20000):page.locator('.archive-filter button').click()
        check('archive native form preserves calendar filter',page.locator('[name=period]').input_value()=='2024-02')
        go('/admin/index.php?tab=motion');check('motion inspector has shared configurable fields',page.locator('.motion-studio-form input[type=range]').count()>=8)
        no_overflow('motion inspector');shot('motion-studio-desktop.png')
        page.locator('[data-motion-preset=expressive]').click();check('expressive preset sets form and live engine',page.locator('[name=motion_curve]').input_value()=='elastic' and page.evaluate('CYMotion.config().motion_magnetic===true'))
        page.locator('[data-motion-replay]').first.click();page.wait_for_timeout(160);check('replay creates real Web Animations',page.evaluate('document.getAnimations().length>0'))
        page.locator('[name=motion_curve]').select_option('custom');page.locator('.motion-section').last.locator('summary').click()
        page.locator('[name=curve_x1]').evaluate('(n)=>{n.value=\"12\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_y1]').evaluate('(n)=>{n.value=\"155\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_x2]').evaluate('(n)=>{n.value=\"64\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}');page.locator('[name=curve_y2]').evaluate('(n)=>{n.value=\"100\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}')
        check('curve graph matches shared custom parameters',page.locator('[data-curve-code]').inner_text()=='cubic-bezier(0.12, 1.55, 0.64, 1)')
        page.locator('[name=motion_duration]').evaluate('(n)=>{n.value=\"580\";n.dispatchEvent(new Event(\"input\",{bubbles:true}))}')
        with page.expect_response(lambda r:r.url.endswith('/action.php') and r.request.method=='POST',timeout=20000) as saving:
            page.locator('form.motion-studio-form button[type=submit]').click()
        saved=saving.value.json();check('motion POST accepted',saving.value.status==200 and saved.get('ok'),saved)
        go('/admin/index.php?tab=motion')
        check('motion form saved through real authenticated POST',page.locator('[name=motion_curve]').input_value()=='custom' and page.locator('[name=motion_duration]').input_value()=='580')
        go('/');check('saved admin motion reaches public page',page.evaluate('CYMotion.config().motion_duration===580 && CYMotion.config().curve_y1===155'))
        go('/admin/index.php?tab=motion');page.set_viewport_size({'width':390,'height':844});shot('motion-studio-mobile.png')
        page.emulate_media(reduced_motion='reduce');expect(page.locator('html')).to_have_attribute('data-motion','0');check('system preference overrides decorative motion',page.locator('html').get_attribute('data-motion')=='0')
        page.locator('[data-motion-preset=expressive]').click();check('preset cannot override system reduced motion',not page.evaluate('CYMotion.enabled()'))
        check('motion studio remains usable under reduced motion',page.locator('[name=motion_curve]').is_enabled())
        page.emulate_media(reduced_motion='no-preference');page.wait_for_function('CYMotion.enabled()');check('live media change restores configured motion',page.evaluate('CYMotion.enabled()'))
        page.locator('[name=motion]').uncheck();check('master off clears decorative animations',not page.evaluate('CYMotion.enabled()') and page.locator('.motion-orb').evaluate('(n)=>getComputedStyle(n).animationName')=='none')
        page.locator('[name=motion]').check();page.locator('[data-motion-preset=balanced]').click()
        # Community UI -> server mutation -> rendered result.
        page.set_viewport_size({'width':1440,'height':1000});go('/index.php?r=article&id='+str(IDS['poll']))
        check('article TOC generated from actual visible headings',page.locator('.reading-outline a').count()>=2)
        check('private result counts absent before vote',page.locator('.poll-results').count()==0)
        no_overflow('poll');shot('poll-desktop.png')
        page.locator('.poll-choice').first.click();expect(page.locator('.poll-choice input').first).to_be_checked()
        with page.expect_response(lambda r:r.url.endswith('/action.php') and r.request.method=='POST',timeout=20000) as voting:
            page.locator('.poll-form button[type=submit]').click()
        vote_result=voting.value.json();check('browser vote POST accepted',voting.value.status==200 and vote_result.get('ok'),vote_result)
        expect(page.locator('.poll-results')).to_be_visible(timeout=20000)
        check('real browser vote persists and reveals own results',page.locator('.poll-results').count()==1 and page.locator('.poll-form').count()==0)
        shot('poll-results.png')
        go('/index.php?r=article&id='+str(IDS['question']));check('accepted answer is visible and anchored',page.locator('#comment-'+str(IDS['answer'])).count()==1);shot('question-desktop.png')
        page.set_viewport_size({'width':390,'height':844});no_overflow('question');page.set_viewport_size({'width':390,'height':844});shot('question-mobile.png')
        page.set_viewport_size({'width':1440,'height':1000});go('/admin/index.php?tab=threads');shot('threads-admin.png')
        go('/admin/index.php?tab=edit_content&kind=thread');check('non-poll editor hides and disables poll fields',not page.locator('[data-poll-fields]').is_visible() and page.locator('[name=poll_question]').is_disabled())
        page.locator('[name=thread_mode]').select_option('poll');check('poll editor enables functional poll fields',page.locator('[data-poll-fields]').is_visible() and page.locator('[name=poll_question]').is_enabled())
        # No-JavaScript session still sees content and has ordinary login forms.
        nojs=browser.new_context(java_script_enabled=False,viewport={'width':390,'height':844});static=nojs.new_page();static.goto(BASE,wait_until='load',timeout=20000)
        check('no-JavaScript content cards remain visible',static.locator('.content-card').first.evaluate('(n)=>getComputedStyle(n).opacity')=='1')
        static.goto(BASE+'/index.php?r=login',wait_until='load',timeout=20000);check('no-JavaScript sign-in has real action',static.locator('form[action$="/action.php"]').count()>0);nojs.close()
        check('no live browser JavaScript errors',not errors,errors)
        browser.close()
except Exception as exc:
    check('live browser suite completed',False,repr(exc))
report={'mode':'Actual Chromium navigation to local PHP URLs, real forms/CSP/cookies; not an external production host','capture_enabled':os.environ.get('CY_LIVE_CAPTURE')!='0','total':len(results),'passed':sum(r['ok'] for r in results),'failed':sum(not r['ok'] for r in results),'tests':results,'javascript_errors':errors}
print(json.dumps(report,indent=2));raise SystemExit(1 if report['failed'] else 0)
