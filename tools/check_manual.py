"""Check offline manual links, images, chapter structure and responsive text layout.
Developer-only dependencies: beautifulsoup4, playwright, Pillow.
"""
from pathlib import Path
import json
import os
import sys
from bs4 import BeautifulSoup
from PIL import Image
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
release = json.loads((root / 'RELEASE.json').read_text())
checks = []
def check(name, ok, detail=''):
    checks.append({'test': name, 'ok': bool(ok), 'detail': detail})

html = (root / 'START_HERE.html').read_text()
soup = BeautifulSoup(html, 'html.parser')
ids = [n['id'] for n in soup.select('[id]')]
check('Offline manual chapter count matches release', len(soup.select('article[id^=chapter-]')) == release['manual_chapters'])
check('All HTML identifiers are unique', len(ids) == len(set(ids)))
missing = []
for n in soup.select('a[href],img[src]'):
    target = n.get('href', n.get('src', ''))
    if target.startswith('#'):
        if target[1:] not in ids: missing.append(target)
    elif not target.startswith(('http:', 'https:', 'mailto:', 'tel:', 'data:')):
        if not (root / target.split('#')[0]).is_file(): missing.append(target)
check('All offline local files and fragment links exist', not missing, str(missing))
images = list((root / 'docs/previews').glob('*.png'))
corrupt = []
for p in images:
    try:
        with Image.open(p) as im: im.verify()
    except Exception as exc: corrupt.append(p.name + ': ' + str(exc))
check('All release preview PNGs are readable', len(images) == release['screenshots'] and not corrupt, str(corrupt))
check('Private installation token is not present in public manuals',
      (root / 'INSTALL_KEY.txt').read_text().strip() not in html
      and (root / 'INSTALL_KEY.txt').read_text().strip() not in (root / 'site/DEPLOY_README.html').read_text())
check('Public upload manual is independently readable',
      not any(not a['href'].startswith(('#', 'https:', 'http:', 'mailto:', 'tel:'))
              for a in BeautifulSoup((root / 'site/DEPLOY_README.html').read_text(), 'html.parser').select('a[href]')))
# No URL navigation is attempted. Image existence is checked separately above.
for n in soup.select('img'): n['src'] = 'data:image/gif;base64,R0lGODlhAQABAAAAACw='
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CY_CHROMIUM', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    page = browser.new_page()
    page.emulate_media(reduced_motion='reduce')
    page.set_content(str(soup), wait_until='domcontentloaded')
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        check('Manual text layout has no horizontal overflow at ' + str(width), page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    check('Reduced-motion preference disables manual smooth scrolling', page.evaluate('getComputedStyle(document.documentElement).scrollBehavior') == 'auto')
    browser.close()
result = {'method': 'Static links and PNG verification; Chromium set_content text layout, not browser URL navigation', 'total': len(checks), 'passed': sum(x['ok'] for x in checks), 'failed': sum(not x['ok'] for x in checks), 'tests': checks}
(root / 'docs/manual-checks.json').write_text(json.dumps(result, indent=2))
print(json.dumps({k:v for k,v in result.items() if k != 'tests'}, indent=2))
sys.exit(1 if result['failed'] else 0)
