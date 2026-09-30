# -*- coding: utf-8 -*-
"""بوابة ٢٦-١٣ (قرار ٦٦ «الزر يفي بوعده») على WebKit iPhone 13 بحساب الموظف (أضعف حساب): زرّا التوعية يفتحان مكانه.

- «خطة مكاني» ← ملف مكانه، والخطتان أول الأزرار ومُبرزتان، وضغطة على الخطة تفتح وثيقتها.
- «أعرف أخطار مكاني» ← صفحة الأخطار بمكانه: أخطاره إن سُجّلت، وإلا يُقال ذلك ويُعرض الكتاب كله.
- مسؤول السلامة (لا مكان واحد له): الفهرس والكتاب كله.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-13.py [BASE]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
errs = []


def log(k, v, ok=None):
    mark = '' if ok is None else ('✓ ' if ok else '✗ ')
    print(f'{mark}{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def login(page, user, pw, tries=4):
    for i in range(tries):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user); page.fill('input[name=password]', pw)
        page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
        if '/login' not in page.url: return True
        page.wait_for_timeout(20000)
    return False


def ctx_page(browser):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def path(url):
    return url.replace(BASE, '')


with sync_playwright() as p:
    browser = p.webkit.launch()

    ctx, page, console = ctx_page(browser)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    page.goto(BASE + '/app', wait_until='networkidle')
    tile = page.locator('#places a.pl-tile').first
    hz = tile.get_attribute('data-place'); file_url = tile.get_attribute('href')
    log('emp: مكانه', [hz, path(file_url)], bool(hz))

    # «خطة مكاني»: ضغطة ← ملف مكانه والخطتان مُبرزتان في أول الشاشة
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#intents a[data-intent="plans"]')
    page.wait_for_timeout(500)
    log('emp: «خطة مكاني» بضغطة تفتح ملف مكانه', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == path(file_url) + '#plans')
    sa = page.locator('#pfPlanSafety'); ra = page.locator('#pfPlanResponse')
    hl = page.evaluate("getComputedStyle(document.getElementById('pfPlanSafety')).outlineStyle")
    box = ra.bounding_box()
    log('emp: الخطتان ظاهرتان في الشاشة الأولى ومُبرزتان', [sa.is_visible(), ra.is_visible(), hl, round(box['y'] + box['height'])], sa.is_visible() and ra.is_visible() and hl == 'solid' and box['y'] + box['height'] < 844)
    page.screenshot(path=os.path.join(SHOT, '26-13-emp-plans.png'))
    doc = ra.get_attribute('href')
    r = page.request.get(BASE + doc)
    log('emp: «خطة الاستجابة» تفتح وثيقة مكانه', [doc, r.status], r.status == 200 and '/response-plan.html' in doc)

    # «أعرف أخطار مكاني»: ضغطة ← صفحة الأخطار بمكانه
    page.goto(BASE + '/app', wait_until='networkidle')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#intents a[data-intent="hazards"]')
    log('emp: «أعرف أخطار مكاني» بضغطة تفتح الأخطار بمكانه', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == f'/hazards?place={hz}')
    mine = page.locator('#placeHazards'); none = page.locator('#placeHazardsNone')
    shown = page.locator('details[data-risk]').count()
    if mine.count():
        n = int(mine.get_attribute('data-n'))
        log('emp: تُعرض أخطار مكانه وحدها بعددها', [mine.inner_text().strip()[:70], n, shown], shown == n and n > 0)
        with page.expect_navigation(wait_until='domcontentloaded'):
            mine.locator('a').click()
        allshown = page.locator('details[data-risk]').count()
        log('emp: «كتاب المعهد كله» بضغطة', [allshown, path(page.url)], allshown > n and 'all=1' in page.url)
    else:
        log('emp: مكان بلا أخطار مسجّلة — يُقال ذلك ويُعرض الكتاب كله', [none.inner_text().strip()[:70] if none.count() else '—', shown], none.count() == 1 and shown > 0)
    sel = page.locator('select[name=place]').input_value()
    log('emp: المكان محدد في الصفحة', sel, sel == hz)
    page.screenshot(path=os.path.join(SHOT, '26-13-emp-hazards.png'))
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── من سُجّلت أخطار لمكانه: حساب مدير الإدارة ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'mudir', '1234'), 'فشل دخول مدير الإدارة'
    page.goto(BASE + '/app', wait_until='networkidle')
    href = page.locator('#intents a[data-intent="hazards"]').get_attribute('href')
    page.goto(href, wait_until='domcontentloaded')
    mine = page.locator('#placeHazards'); none = page.locator('#placeHazardsNone')
    shown = page.locator('details[data-risk]').count()
    log('mudir: صفحة الأخطار بمكانه', [path(page.url), (mine.get_attribute('data-n') if mine.count() else 'لا أخطار مسجّلة'), shown], (mine.count() == 1 and shown == int(mine.get_attribute('data-n'))) or (none.count() == 1 and shown > 0))
    log('mudir: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة: لا مكان واحد له ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    pl = page.locator('#intents a[data-intent="plans"]').get_attribute('href')
    hzd = page.locator('#intents a[data-intent="hazards"]').get_attribute('href')
    log('salama: «خطة مكاني» فهرس الوثائق، و«أعرف أخطار مكاني» الكتاب كله', [pl, path(hzd)], pl == '/index.html' and path(hzd) == '/hazards')
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-١٣ خضراء'))
sys.exit(1 if errs else 0)
