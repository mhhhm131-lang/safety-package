# -*- coding: utf-8 -*-
"""بوابة ٢٥-٢ على متصفح حقيقي (WebKit): الرسم «حال الآن» يتغيّر بضغطة المكان بلا طلب ثانٍ، وكل عمود يفتح قائمته.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-25-2.py [BASE]
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
        r = page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            print(f'  دخول {user}: الصفحة {r.status if r else "?"} بلا نموذج', flush=True)
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user)
        page.fill('input[name=password]', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            return True
        page.wait_for_timeout(20000)
    return False


def bars(page):
    return page.locator('#bars .bar').evaluate_all('els => Object.fromEntries(els.map(e => [e.dataset.k, [Number(e.dataset.n), e.getAttribute("href")]]))')


def ctx_page(browser, mobile):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844} if mobile else {'width': 1440, 'height': 900},
                              device_scale_factor=2 if mobile else 1, is_mobile=mobile, has_touch=mobile, locale='ar-SA')
    page = ctx.new_page()
    page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── مسؤول السلامة على الحاسب: المبنى كله ← مكان ← عمود ← رجوع ──
    ctx, page, console = ctx_page(browser, False)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    b0 = bars(page)
    log('salama: ستة أعمدة للمبنى كله', {k: v[0] for k, v in b0.items()}, len(b0) == 6)
    log('salama: عنوان الرسم', page.locator('#chartScope').inner_text(), page.locator('#chartScope').inner_text() == 'المبنى كله')
    log('salama: زرا «افتح ملف المكان» و«المبنى كله» مخفيان قبل الضغط', True, page.locator('#chartFile').is_hidden() and page.locator('#chartAll').is_hidden())
    page.screenshot(path=os.path.join(SHOT, '25-2-salama-all.png'), full_page=False)

    # ضغطة المكان (المكاتب الإدارية) — بلا طلب صفحة جديدة
    reqs = []
    page.on('request', lambda r: reqs.append(r.url) if r.resource_type == 'document' else None)
    tile = page.locator('#places .pl-tile[data-place="HZ-06"]')
    name = tile.locator('.nm').inner_text()
    tile.click()
    page.wait_for_timeout(500)
    b1 = bars(page)
    log('salama: ضغطة المكان تغيّر الرسم للمكان وحده', f'{name}: ' + str({k: v[0] for k, v in b1.items()}), page.locator('#chartScope').inner_text() == name and not reqs and '/app' in page.url and '#' not in page.url.split('/app')[-1])
    log('salama: أرقام المكان ≤ أرقام المبنى', True, all(b1[k][0] <= b0[k][0] for k in b0))
    log('salama: روابط الأعمدة صارت بالمكان', b1['incidents'][1], 'place=HZ-06' in (b1['incidents'][1] or '') and '/file#pfReports' in (b1['reports'][1] or ''))
    fh = page.locator('#chartFile').get_attribute('href')
    log('salama: زر «افتح ملف المكان» ظاهر ويشير إلى الملف', fh, page.locator('#chartFile').is_visible() and fh.endswith('/file'))
    log('salama: المربع المضغوط مُعلَّم', tile.get_attribute('class'), 'on' in tile.get_attribute('class').split())
    page.screenshot(path=os.path.join(SHOT, '25-2-salama-place.png'), full_page=False)

    # «المبنى كله» يعيد الأرقام
    page.locator('#chartAll').click()
    page.wait_for_timeout(300)
    b2 = bars(page)
    log('salama: «المبنى كله» يعيد الرسم', {k: v[0] for k, v in b2.items()}, {k: v[0] for k, v in b2.items()} == {k: v[0] for k, v in b0.items()} and page.locator('#chartAll').is_hidden())

    # ضغطة عمود ← قائمته (بلاغات الشاغلين المفتوحة)
    page.locator('#bars .bar[data-k="incidents"]').click()
    page.wait_for_load_state('networkidle')
    log('salama: عمود بلاغات الشاغلين يفتح قائمته', page.url.replace(BASE, ''), '/app/incidents' in page.url and 'status=open' in page.url)
    page.go_back(); page.wait_for_load_state('networkidle')
    page.locator('#bars .bar[data-k="reports"]').click()
    page.wait_for_load_state('networkidle')
    log('salama: عمود بلاغات الفحص يفتح قائمة المبنى', page.url.replace(BASE, ''), '/app/places/units?k=open' in page.url and page.locator('#kList').count() > 0)
    page.goto(BASE + '/app', wait_until='networkidle')
    page.locator('#bars .bar[data-k="emergency"]').click()
    page.wait_for_load_state('networkidle')
    log('salama: عمود الحالات الطارئة يفتح قائمته', page.url.replace(BASE, ''), '/app/emergency/incidents' in page.url)
    page.goto(BASE + '/app', wait_until='networkidle')
    page.locator('#bars .bar[data-k="permits"]').click()
    page.wait_for_load_state('networkidle')
    log('salama: عمود التصاريح يفتح قائمته', page.url.replace(BASE, ''), '/app/permits' in page.url and 'status=active' in page.url)
    log('salama: لا رسم شهري في الصفحة الأولى', True, page.goto(BASE + '/app', wait_until='networkidle') and page.locator('#trend').count() == 0)
    log('salama: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── الموظف على الجوال: رسم مكانه، ومربعه يفتح الملف بضغطة واحدة ──
    ctx, page, console = ctx_page(browser, True)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    page.goto(BASE + '/app', wait_until='networkidle')
    be = bars(page)
    log('emp: الرسم لمكانه', f'{page.locator("#chartScope").inner_text()}: ' + str({k: v[0] for k, v in be.items()}), len(be) == 6 and page.locator('#chartScope').inner_text() == 'المكاتب الإدارية')
    log('emp: الرسم ظاهر على الجوال تحت الأماكن', True, page.locator('#chart').is_visible() and page.locator('#places').bounding_box()['y'] < page.locator('#chart').bounding_box()['y'])
    log('emp: لا أزرار ترشيح (مكان واحد)', True, page.locator('#chartAll').count() == 0)
    page.screenshot(path=os.path.join(SHOT, '25-2-emp-iphone.png'), full_page=True)
    page.locator('#places .pl-tile').first.click()
    page.wait_for_load_state('networkidle')
    log('emp: مربعه يفتح ملف المكان بضغطة واحدة', page.url.replace(BASE, ''), '/file' in page.url)
    page.goto(BASE + '/app', wait_until='networkidle')
    page.locator('#bars .bar[data-k="reports"]').click()
    page.wait_for_load_state('networkidle')
    log('emp: عمود بلاغات الفحص يفتح ملف مكانه', page.url.replace(BASE, ''), '/file' in page.url)
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()
    browser.close()

print('\n' + ('✗ أخطاء: ' + '، '.join(errs) if errs else '✓ بوابة ٢٥-٢ خضراء'))
sys.exit(1 if errs else 0)
