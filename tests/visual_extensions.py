"""Browser DOM interactions for Studio and additional responsive pages."""
page.set_content(snapshot('/admin/index.php?tab=builder&slot=articles'),wait_until='load')
check('layout editor initializes actual blocks',page.locator('.studio-block').count()==2)
check('layout title is preserved',page.locator('h1').inner_text()=='\u5e03\u5c40\u5de5\u4f5c\u53f0')
check('unfocused skip link does not obscure content',page.locator('.skip-link').evaluate('(n)=>n.getBoundingClientRect().bottom <= 0'))
capture(path=str(OUT/'layout-studio-desktop.png'),full_page=True)
initial=page.locator('[data-layout-document]').input_value()
page.locator('[data-block-add="cta"]').click()
check('layout add block updates model',len(json.loads(page.locator('[data-layout-document]').input_value())['blocks'])==3)
block=page.locator('.studio-block').last
block.locator('input[type=text]').first.fill('Browser QA title')
check('layout text edit synchronizes JSON',json.loads(page.locator('[data-layout-document]').input_value())['blocks'][-1]['title']=='Browser QA title')
block.locator('.studio-control').nth(1).click()
check('layout keyboard-accessible move reorders data',json.loads(page.locator('[data-layout-document]').input_value())['blocks'][1]['title']=='Browser QA title')
page.locator('.studio-block').nth(1).locator('.studio-control').nth(3).click()
check('layout duplicate generates unique identifier',len({x['id'] for x in json.loads(page.locator('[data-layout-document]').input_value())['blocks']})==4)
page.locator('.studio-block').last.locator('.studio-control').last.click()
check('layout remove removes one block',page.locator('.studio-block').count()==3)
page.locator('[data-layout-template]').select_option('community');page.locator('[data-layout-template-add]').click()
check('layout template appends configured blocks',page.locator('.studio-block').count()==5)
page.locator('[data-layout-import]').set_input_files({'name':'invalid.json','mimeType':'application/json','buffer':b'{"format":"arbitrary-code"}'})
page.wait_for_timeout(150)
check('invalid layout import preserves model',page.locator('.studio-block').count()==5 and page.locator('[data-studio-error]').is_visible())
page.locator('[data-layout-import]').set_input_files({'name':'layout.json','mimeType':'application/json','buffer':initial.encode()})
page.wait_for_timeout(150)
check('valid layout import replaces model',page.locator('.studio-block').count()==2)
with page.expect_download() as download_info:page.locator('[data-layout-export]').click()
download=download_info.value
export=json.loads(Path(download.path()).read_text())
check('layout export is data only',export['format']=='chengyu-layout' and set(export)=={'format','version','blocks'})
for width in [320,390,768,1440]:
    page.set_viewport_size({'width':width,'height':960});check('studio no horizontal overflow at '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
page.set_viewport_size({'width':390,'height':844});capture(path=str(OUT/'layout-studio-mobile.png'),full_page=True)
for route,filename in [('/index.php?r=collections','collections'),('/index.php?r=tasks','tasks'),('/admin/index.php?tab=variants','variants'),('/index.php?r=articles','composed-articles')]:
    page.set_viewport_size({'width':1440,'height':1000});page.set_content(snapshot(route),wait_until='load');capture(path=str(OUT/(filename+'-desktop.png')),full_page=True)
    for width in [320,390,768,1440]:
        page.set_viewport_size({'width':width,'height':844});check(filename+' no horizontal overflow at '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
# Each theme has a real independently styled render, not a mock image.
page.set_viewport_size({'width':1440,'height':1000})
for theme in ['paper','graphite','citrus']:
    page.set_content(snapshot('/'),wait_until='load');page.evaluate('(theme)=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.mode="light"}',theme);capture(path=str(OUT/('home-'+theme+'.png')),full_page=True)
