"""Focused real-browser regression for content menus, FAQ editing and keyboard access."""
from pathlib import Path
import json,os
from playwright.sync_api import sync_playwright,expect

base=os.environ['CY_SNAPSHOT_BASE'];out=Path(os.environ['CY_VISUAL_OUT']);out.mkdir(parents=True,exist_ok=True)
results=[];errors=[]
def check(name,ok):
    results.append({'test':name,'ok':bool(ok)})
def shot(name):
    page.wait_for_timeout(350);page.screenshot(path=str(out/name),full_page=False)
def go(path):
    response=page.goto(base+path,wait_until='load');check('GET '+path,response.status==200)
def submit(button):
    with page.expect_navigation(wait_until='load'):button.click()
def overflow(label):check(label,page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
def preserves(expected,actual):
    if isinstance(expected,dict):return isinstance(actual,dict) and all(k in actual and preserves(v,actual[k]) for k,v in expected.items())
    if isinstance(expected,list):return isinstance(actual,list) and len(expected)==len(actual) and all(preserves(a,b) for a,b in zip(expected,actual))
    return expected==actual
try:
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=os.environ['CY_CHROMIUM'],headless=True,args=['--disable-gpu','--disable-features=CalculateNativeWinOcclusion'])
        context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();page.set_default_timeout(15000);page.on('pageerror',lambda e:errors.append(str(e)))
        go('/admin/');page.locator('[name=identifier]').fill(os.environ['CY_ADMIN_USER']);page.locator('[name=password]').fill(os.environ['CY_ADMIN_PASSWORD']);page.locator('form').filter(has=page.locator('[name=action][value=login]')).locator('button[type=submit]').click();expect(page.locator('.admin-sidebar')).to_be_visible()
        go('/admin/index.php?tab=navigation');form=page.locator('.navigation-editor')
        form.locator('[name=label]').fill('阅读手记');form.locator('[name=route]').select_option('articles');form.locator('[name=icon]').select_option('anime-reading')
        option=form.locator('[name=category_id] option').evaluate_all('(nodes)=>nodes.find(n=>n.value!=="0" && n.textContent.startsWith("文章 /"))?.value')
        check('category selector has actual article categories',bool(option));form.locator('[name=category_id]').select_option(option)
        form.locator('[name=preview_count]').select_option('4');form.locator('[name=preview_sort]').select_option('latest');form.locator('[name=sort_order]').fill('0');submit(form.locator('button[type=submit]'))
        record=page.locator('.navigation-record').filter(has=page.get_by_text('阅读手记',exact=True));expect(record).to_have_count(1)
        submit(record.locator('a'));nav_id=page.locator('.navigation-editor [name=id]').input_value();check('backend persists source category',page.locator('[name=category_id]').input_value()==option)
        shot('024-menu-editor-desktop.png');page.set_viewport_size({'width':390,'height':844});overflow('menu editor mobile has no overflow');shot('024-menu-editor-mobile.png');page.set_viewport_size({'width':1440,'height':1000})
        go('/');branch=page.locator('.desktop-nav [data-nav-id="'+nav_id+'"]');summary=branch.locator(':scope > summary');expect(summary).to_be_visible();summary.click();expect(branch).to_have_attribute('open','')
        check('menu contains actual article previews',branch.locator('[data-preview-id]').count()>0);shot('024-menu-desktop.png')
        for width in [1100,1280,1440]:
            page.set_viewport_size({'width':width,'height':1000});page.wait_for_timeout(120);overflow('open menu no overflow '+str(width))
            if summary.is_visible():
                bounds=branch.locator(':scope > .site-nav-panel').bounding_box();check('open menu stays inside viewport '+str(width),bounds is not None and bounds['x']>=0 and bounds['x']+bounds['width']<=width)
        page.set_viewport_size({'width':1440,'height':1000});summary.focus();page.keyboard.press('Escape');expect(branch).not_to_have_attribute('open','');check('Escape returns focus to summary',summary.evaluate('(n)=>n===document.activeElement'))
        page.keyboard.press('Enter');expect(branch).to_have_attribute('open','');page.locator('.brand').first.click();expect(page.locator('.desktop-nav details[open]')).to_have_count(0)
        page.set_viewport_size({'width':390,'height':844});page.locator('[data-dialog="menu-dialog"]').click();mobile=page.locator('#menu-dialog [data-nav-id="'+nav_id+'"]');mobile.locator(':scope > summary').click();check('mobile preview is visible',mobile.locator('[data-preview-id]').first.is_visible());overflow('mobile menu no overflow');shot('024-menu-mobile.png');page.locator('#menu-dialog [data-close-dialog]').click()
        page.set_viewport_size({'width':1440,'height':1000});go('/admin/index.php?tab=builder&slot=page10');page.locator('[data-block-add=faq]').click()
        page.wait_for_timeout(500);shot('024-builder-before-edit.png')
        block=page.locator('[data-block-id]').last;block.locator('.studio-fields > .field input[type=text]').first.fill('常见问题')
        block.get_by_role('button',name='添加问题',exact=True).click();entry=block.locator('.studio-entry').last;entry.locator('input').fill('怎么下载已购买的资源？');entry.locator('textarea').fill('登录后打开 **我的订单**。\n\n进入已付款的订单，即可查看下载入口。')
        block.get_by_role('button',name='添加问题',exact=True).click();entry=block.locator('.studio-entry').last;entry.locator('input').fill('找不到下载入口怎么办？');entry.locator('textarea').fill('请在帮助中心提交工单，并附上订单编号。')
        entry.get_by_role('button',name='上移',exact=True).click();check('FAQ can reorder real entries',block.locator('.studio-entry input').first.input_value()=='找不到下载入口怎么办？')
        add_button=block.get_by_role('button',name='添加问题',exact=True)
        check('entry add button fits its complete label',add_button.evaluate('(n)=>n.scrollWidth<=n.clientWidth && n.scrollHeight<=n.clientHeight'))
        shot('024-faq-editor.png')
        page.locator('[data-block-add=text]').click();note=page.locator('[data-block-id]').last
        note.locator('.studio-fields > .field input[type=text]').first.fill('下载说明')
        note.locator('.studio-fields textarea').first.fill('后台保存的草稿应在发布后才出现在页面。')
        note.get_by_role('button',name='复制',exact=True).click();expect(page.locator('[data-block-id]')).to_have_count(3)
        duplicate=page.locator('[data-block-id]').last
        duplicate.locator('.studio-fields > .field input[type=text]').first.fill('售后说明')
        duplicate.get_by_role('button',name='上移',exact=True).last.click()
        model=json.loads(page.locator('[data-layout-document]').input_value())
        check('duplicate receives a unique block ID',len(set(b['id'] for b in model['blocks']))==3)
        check('block reorder updates document',model['blocks'][1]['title']=='售后说明')
        submit(page.locator('form.studio-form button[name=operation][value=draft]'))
        go('/index.php?r=landing&slot=page10');check('saved draft is not public',page.locator('.composed-faq details').count()==0 and '售后说明' not in page.locator('main').inner_text())
        go('/admin/index.php?tab=builder&slot=page10');expect(page.locator('[data-block-id]')).to_have_count(3)
        restored=json.loads(page.locator('[data-layout-document]').input_value())
        check('draft restores block order and every submitted field',preserves(model['blocks'],restored['blocks']))
        page.locator('[data-block-id]').nth(1).get_by_role('button',name='移除',exact=True).click()
        expect(page.locator('[data-block-id]')).to_have_count(2)
        submit(page.locator('form.studio-form button[name=operation][value=publish]'))
        go('/index.php?r=landing&slot=page10');faq=page.locator('.composed-faq details');expect(faq).to_have_count(2);faq.first.locator('summary').click();check('FAQ answer expands',faq.first.locator('.prose').is_visible());check('FAQ reordered publication retained',faq.first.locator('summary').inner_text()=='找不到下载入口怎么办？');check('published ordinary block renders and removed duplicate stays absent','后台保存的草稿应在发布后才出现在页面。' in page.locator('main').inner_text() and '售后说明' not in page.locator('main').inner_text());shot('024-faq-desktop.png')
        for width in [320,390,768,1440]:page.set_viewport_size({'width':width,'height':900});overflow('FAQ no overflow '+str(width))
        nojs=browser.new_context(java_script_enabled=False,viewport={'width':390,'height':844});static=nojs.new_page();static.goto(base+'/');native=static.locator('.fallback-navigation [data-nav-id="'+nav_id+'"]');native.locator(':scope > summary').click();check('no-JavaScript content menu remains operable',native.locator('[data-preview-id]').first.is_visible())
        static.goto(base+'/index.php?r=landing&slot=page10');static.locator('.composed-faq summary').first.click();check('no-JavaScript FAQ remains operable',static.locator('.composed-faq .prose').first.is_visible());static.screenshot(path=str(out/'024-faq-nojs-mobile.png'));nojs.close()
        page.set_viewport_size({'width':1440,'height':1000});page.emulate_media(reduced_motion='reduce');go('/');branch=page.locator('.desktop-nav [data-nav-id="'+nav_id+'"]');branch.locator(':scope > summary').click()
        check('reduced-motion menu has no entrance animation',branch.locator(':scope > .site-nav-panel').evaluate('(n)=>getComputedStyle(n).animationName')=='none')
        check('no uncaught browser errors',not errors);browser.close()
except Exception as exc:results.append({'test':'browser suite completes','ok':False,'error':repr(exc)})
report={'total':len(results),'passed':sum(x['ok'] for x in results),'failed':sum(not x['ok'] for x in results),'tests':results,'browser_errors':errors,'scope':'Real localhost PHP 8.2 / native SQLite, new 0.24 menu and FAQ paths only'}
(out/'browser024.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');print(json.dumps(report,ensure_ascii=False));raise SystemExit(bool(report['failed']))
