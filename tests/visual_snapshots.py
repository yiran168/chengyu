"""Offline DOM visual checks, NOT a live browser networking test.
Use a disposable installed site. Credentials are supplied via the environment.
Requires requests, beautifulsoup4, playwright, and a local Chromium executable.
"""
from pathlib import Path
import base64
import json
import os
import sys
import requests
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1] / 'site'
OUT = Path(os.environ.get('CY_VISUAL_OUT', str(ROOT.parent / 'docs' / 'previews')))
OUT.mkdir(parents=True, exist_ok=True)
BASE = os.environ.get('CY_SNAPSHOT_BASE', 'http://127.0.0.1:8765').rstrip('/')
SESSION = requests.Session()
RESULTS = []

def check(name, success):
    RESULTS.append({'test': name, 'ok': bool(success)})
    if os.environ.get('CY_VISUAL_TRACE'): print(('PASS ' if success else 'FAIL ')+name,file=sys.stderr,flush=True)

def capture(path, full_page=True):
    # File controls/downloads can move focus and scroll. Capture a neutral viewport,
    # not an accidentally focused accessibility link over the page heading.
    page.evaluate('() => { if (document.activeElement) document.activeElement.blur(); const s=document.documentElement.style; const old=s.scrollBehavior;s.scrollBehavior="auto"; window.scrollTo({top:0,left:0,behavior:"instant"});s.scrollBehavior=old; }')
    page.wait_for_timeout(180)
    # Crop compositor-only off-screen decoration, not scrollable document content.
    # DOM overflow is asserted independently in the viewport-width checks.
    size = page.evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})')
    page.screenshot(path=path, full_page=full_page, timeout=15000)
    if full_page and size['scroll'] <= size['width']:
        from PIL import Image
        with Image.open(path) as image:
            if image.width > size['width']:
                image.crop((0,0,size['width'],image.height)).save(path)

def snapshot(path):
    if os.environ.get("CY_VISUAL_TRACE"): print("FETCH "+path,file=sys.stderr,flush=True)
    response = SESSION.get(BASE + path, timeout=15)
    response.raise_for_status()
    soup = BeautifulSoup(response.text, 'html.parser')
    for node in soup.select('link[rel=stylesheet]'):
        style = soup.new_tag('style')
        name = node['href'].split('?')[0].split('/')[-1]
        style.string = (ROOT / 'assets' / name).read_text()
        node.replace_with(style)
    for node in soup.select('script[src]'):
        name = node['src'].split('?')[0].split('/')[-1]
        del node['src']
        node.string = (ROOT / 'assets' / name).read_text()
        if node.has_attr('defer'):
            del node['defer']
            node.extract()
            soup.body.append(node)
    for node in soup.select('img[src]'):
        asset = ROOT / 'assets' / node['src'].split('?')[0].split('/')[-1]
        if asset.is_file() and asset.suffix == '.svg':
            node['src'] = 'data:image/svg+xml;base64,' + base64.b64encode(asset.read_bytes()).decode()
    return str(soup)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CY_CHROMIUM', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1440, 'height': 1000}, device_scale_factor=1, reduced_motion='reduce')
    page.set_default_timeout(5000)
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.set_content(snapshot('/'), wait_until='load')
    capture(path=str(OUT / 'home-desktop.png'), full_page=True)
    for width in [320, 390, 768, 1440]:
        page.set_viewport_size({'width': width, 'height': 844})
        check('home no horizontal overflow at ' + str(width), page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    page.set_viewport_size({'width': 390, 'height': 844})
    capture(path=str(OUT / 'home-mobile.png'), full_page=True)
    page.locator('[data-dialog="appearance-dialog"]').click()
    check('appearance opens with actual JavaScript', page.locator('#appearance-dialog').evaluate('(d) => d.open'))
    for theme in ['tide', 'dusk', 'paper', 'graphite', 'citrus']:
        page.locator('[data-appearance="theme"][data-value="' + theme + '"]').click()
        check('theme choice ' + theme, page.locator('html').get_attribute('data-theme') == theme)
    for glass in ['none', 'frost', 'prism', 'liquid']:
        page.locator('[data-appearance="glass"][data-value="' + glass + '"]').click()
        check('glass choice ' + glass, page.locator('html').get_attribute('data-glass') == glass)
    page.locator('[data-appearance="theme"][data-value="dusk"]').click()
    page.locator('[data-appearance="mode"][data-value="dark"]').click()
    check('dark choice', page.locator('html').get_attribute('data-mode') == 'dark')
    page.locator('#appearance-dialog [data-close-dialog]').click()
    check('appearance closes', not page.locator('#appearance-dialog').evaluate('(d) => d.open'))
    page.set_viewport_size({'width': 1440, 'height': 1000})
    capture(path=str(OUT / 'home-dusk-dark.png'), full_page=True)
    check('OS reduced-motion suppresses orb animation', page.locator('.orb').evaluate('(n) => getComputedStyle(n).animationName') == 'none')
    page.keyboard.press('Control+k')
    check('keyboard shortcut opens search', page.locator('#search-dialog').evaluate('(d) => d.open'))
    page.keyboard.press('Escape')
    check('Escape dismisses search', not page.locator('#search-dialog').evaluate('(d) => d.open'))

    form = BeautifulSoup(SESSION.get(BASE + '/admin/', timeout=15).text, 'html.parser').select_one('input[name=action][value=login]').find_parent('form')
    if form is None:
        raise RuntimeError('A disposable installed site and login form are required')
    fields = {node['name']: node.get('value', '') for node in form.select('input[name]')}
    fields.update(identifier=os.environ['CY_ADMIN_USER'], password=os.environ['CY_ADMIN_PASSWORD'])
    response = SESSION.post(BASE + form['action'], data=fields, timeout=15)
    response.raise_for_status()
    page.set_content(snapshot('/admin/index.php?tab=dashboard'), wait_until='load')
    check('admin dashboard loaded', page.locator('.admin-sidebar').count() == 1)
    capture(path=str(OUT / 'admin-desktop.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    check('admin mobile no horizontal overflow', page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    page.locator('button[data-admin-menu]').click()
    check('admin mobile navigation opens', page.locator('body').evaluate('(n) => n.classList.contains("sidebar-open")'))
    page.keyboard.press('Escape')
    check('admin mobile navigation closes', not page.locator('body').evaluate('(n) => n.classList.contains("sidebar-open")'))
    capture(path=str(OUT / 'admin-mobile.png'), full_page=True)
    page.set_viewport_size({'width': 1440, 'height': 1000})
    page.set_content(snapshot('/admin/index.php?tab=settings&group=appearance'), wait_until='load')
    capture(path=str(OUT / 'settings-desktop.png'), full_page=True)
    page.locator('[data-preview="paper"]').click()
    check('admin theme preview changes form value', page.locator('select[name="theme"]').input_value() == 'paper')
    check('admin theme preview changes visual theme', page.locator('html').get_attribute('data-theme') == 'paper')
    exec(compile((ROOT.parent/'tests/visual_extensions.py').read_text(),str(ROOT.parent/'tests/visual_extensions.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_motion_community.py').read_text(),str(ROOT.parent/'tests/visual_motion_community.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_012.py').read_text(),str(ROOT.parent/'tests/visual_012.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_013.py').read_text(),str(ROOT.parent/'tests/visual_013.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_014.py').read_text(),str(ROOT.parent/'tests/visual_014.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_015.py').read_text(),str(ROOT.parent/'tests/visual_015.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_016.py').read_text(),str(ROOT.parent/'tests/visual_016.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_017.py').read_text(),str(ROOT.parent/'tests/visual_017.py'),'exec'))
    exec(compile((ROOT.parent/'tests/visual_018.py').read_text(),str(ROOT.parent/'tests/visual_018.py'),'exec'))
    check('no JavaScript runtime errors in snapshots', not errors)
    browser.close()

report = {'mode': 'Local HTTP-rendered HTML; CSS/JS/SVG inlined; Chromium set_content, no browser URL navigation', 'total': len(RESULTS), 'passed': sum(x['ok'] for x in RESULTS), 'failed': sum(not x['ok'] for x in RESULTS), 'tests': RESULTS, 'javascript_errors': errors}
print(json.dumps(report, indent=2))
raise SystemExit(1 if report['failed'] else 0)
