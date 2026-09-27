# -*- coding: utf-8 -*-
"""بوابة ٢٥-٣-ب (WebKit، iPhone 13): «ما ينتظرك» منسدلة — عدّادات، مجموعات تُفتح وتُغلق، والمتصفح يتذكر."""
import sys, os
from playwright.sync_api import sync_playwright
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
errs = []
def log(k, v, ok=None):
    print(f'{"" if ok is None else ("✓ " if ok else "✗ ")}{k} | {v}', flush=True)
    if ok is False: errs.append(k)
with sync_playwright() as p:
    b = p.webkit.launch()
    ctx = b.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    pg = ctx.new_page(); pg.set_default_timeout(120000); console = []
    pg.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    pg.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    pg.goto(BASE + '/login'); pg.fill('input[name=username]', 'munawib'); pg.fill('input[name=password]', '1234'); pg.click('button[type=submit]'); pg.wait_for_load_state('networkidle')
    pg.evaluate("try{localStorage.removeItem('ipa-inbox-open')}catch(e){}")
    pg.goto(BASE + '/app', wait_until='networkidle')
    chips = pg.locator('#inboxSummary .chip').evaluate_all('els=>els.map(e=>e.dataset.group+":"+e.dataset.n+"/"+e.dataset.od)')
    log('المناوب: سطر العدّادات', chips, len(chips) >= 2)
    secs = pg.locator('#inboxList section')
    opened = pg.locator('#inboxList .collapse.show').count()
    log('المناوب: المجموعات مطويّة (أكثر من ٣ مهام)', f'{secs.count()} مجموعات · مفتوح {opened}', secs.count() >= 2 and opened == 0)
    total = int(pg.locator('#inboxCount').inner_text())
    cards = pg.locator('#inboxList .task').count()
    log('المناوب: البطاقات كلها في الصفحة (لا يُحذف شيء)', f'{cards} من {total}', cards == total)
    y_places = pg.locator('#places').bounding_box()['y']
    log('المناوب: الأماكن قريبة بعد الطيّ', f'y={round(y_places)}', y_places < 1400)
    pg.screenshot(path=os.path.join(SHOT, '25-3b-munawib-folded.png'), full_page=False)
    # ضغطة العنوان تفتح المجموعة
    first = secs.first
    first.locator('button.grp-h').click(); pg.wait_for_timeout(600)
    log('المناوب: ضغطة العنوان تفتح المجموعة', first.locator('.collapse').get_attribute('class'), 'show' in first.locator('.collapse').get_attribute('class'))
    log('المناوب: بطاقاتها ظاهرة وأزرارها تُضغط', first.locator('.task .btn').first.is_visible(), first.locator('.task .btn').first.is_visible())
    pg.screenshot(path=os.path.join(SHOT, '25-3b-munawib-open.png'), full_page=False)
    # يتذكر بعد التحديث
    pg.reload(wait_until='networkidle'); pg.wait_for_timeout(300)
    log('المناوب: يتذكر المفتوح بعد التحديث', pg.locator('#inboxList section').first.locator('.collapse').get_attribute('class'), 'show' in pg.locator('#inboxList section').first.locator('.collapse').get_attribute('class'))
    # عدّاد مجموعة ثانية يفتحها
    chip2 = pg.locator('#inboxSummary .chip').nth(1); target = chip2.get_attribute('href')
    chip2.click(); pg.wait_for_timeout(700)
    log('المناوب: عدّاد المجموعة يفتحها', target, 'show' in pg.locator(target).get_attribute('class'))
    log('المناوب: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close(); b.close()
print('\n' + ('✗ أخطاء: ' + '، '.join(errs) if errs else '✓ بوابة ٢٥-٣-ب خضراء'))
sys.exit(1 if errs else 0)
