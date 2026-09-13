"""Generate standalone text manuals from the maintained offline chapters.
Usage: python tools/build_standalone.py /absolute/output-manual.html
"""
from pathlib import Path
import json
import sys
import runpy
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[1]
namespace = runpy.run_path(str(ROOT / 'tools/build_manual.py'))
parts = namespace['manual_parts']
source = (ROOT / 'START_HERE.html').read_text(encoding='utf-8')
version = json.loads((ROOT / 'RELEASE.json').read_text(encoding='utf-8'))['version']
paths = {str(Path(rel)): 'chapter-' + str(i) for i, (rel, title) in enumerate(parts, 1)}

def render(allowed=None):
    soup = BeautifulSoup(source, 'html.parser')
    for element in soup.select('#previews, .eyebrow a'):
        element.decompose()
    if allowed is not None:
        for element in soup.select('article[id^=chapter-]'):
            if int(element['id'].split('-')[1]) not in allowed:
                element.decompose()
    ids = {n['id'] for n in soup.select('[id]')}
    for link in list(soup.select('a[href]')):
        href = link['href']
        target = paths.get(str(Path(href.split('#')[0])))
        if target in ids:
            link['href'] = '#' + target
        elif href.startswith('#'):
            if href[1:] not in ids:
                if link.find_parent('nav') or link.find_parent(class_='quicklinks'):
                    link.decompose()
                else:
                    link.unwrap()
        elif not href.startswith(('http:', 'https:', 'mailto:', 'tel:')):
            link.unwrap()
    for image in soup.select('img'):
        image.decompose()
    soup.select('.badges span')[-1].string = str(len(soup.select('article[id^=chapter-]'))) + ' chapters / screenshots in complete ZIP'
    soup.find('footer').string = 'Chengyu ' + version + ' | Standalone instructions. Screenshots, test evidence and source are in the complete package.'
    return str(soup)

(ROOT / 'site/DEPLOY_README.html').write_text(render({1,2,3,8,11,14,27,30,31,33,34,36}),encoding='utf-8')
output = Path(sys.argv[1]).resolve() if len(sys.argv)>1 else ROOT.parent / ('chengyu-' + version + '-manual.html')
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(render(),encoding='utf-8')
print('Standalone manual:', output, output.stat().st_size)
print('Upload manual:', (ROOT / 'site/DEPLOY_README.html').stat().st_size)
