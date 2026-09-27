# -*- coding: utf-8 -*-
"""بوابة ٢٥-٣ على متصفح حقيقي (WebKit، iPhone 13): الترتيب ما ينتظرك ← الأماكن ← الرسم ← أريد أن…، بلا تكرار،
وباب واحد لمركز السلامة وإدارة الطوارئ، وملف المكاتب الإدارية بإداراته كلها.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-25-3.py [BASE]
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
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user)
        page.fill('input[name=password]', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            return True
        page.wait_for_timeout(20000)
    return False


def ctx_page(browser, mobile=True):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844} if mobile else {'width': 1440, 'height': 900},
                              device_scale_factor=2 if mobile else 1, is_mobile=mobile, has_touch=mobile, locale='ar-SA')
    page = ctx.new_page()
    page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def y(page, sel):
    el = page.locator(sel).first
    return el.bounding_box()['y'] if el.count() else None


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── الموظف على الجوال ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    page.goto(BASE + '/app', wait_until='networkidle')
    inbox = y(page, '#inboxList') if page.locator('#inboxList').count() else y(page, '#inboxEmpty')
    ys = [inbox, y(page, '#places'), y(page, '#chart'), y(page, '#intents')]
    log('emp: الترتيب ما ينتظرك ← الأماكن ← الرسم ← أريد أن', [round(v) for v in ys], None not in ys and ys == sorted(ys))
    log('emp: لا سطر «مكاني» مكرر', page.locator('#makaniLine').count() == 0, page.locator('#makaniLine').count() == 0)
    tel = page.locator('#placesCard a[href^="tel:"]')
    log('emp: هاتف المركز في بطاقة الأماكن', tel.count(), tel.count() == 1)
    sos_in_intents = page.locator('#intents [data-intent="sos"]').count()
    log('emp: «أستغيث الآن» شريط ثابت واحد لا زر ثانٍ', f'في أريد أن: {sos_in_intents} · الشريط: {page.locator("#sosBar").count()}', sos_in_intents == 0 and page.locator('#sosBar').is_visible())
    log('emp: لا نية «الأماكن»', page.locator('#intents [data-intent="places"]').count() == 0, page.locator('#intents [data-intent="places"]').count() == 0)
    page.screenshot(path=os.path.join(SHOT, '25-3-emp-iphone.png'), full_page=True)
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة: الباب الواحد وملف المكاتب ──
    ctx, page, console = ctx_page(browser, False)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    doors = page.locator('#intents [data-intent="incidents"], #intents [data-intent="emergency"]').count()
    center = page.locator('#intents [data-intent="center"]')
    log('salama: باب واحد «مركز السلامة وإدارة الطوارئ» في أريد أن', f'{center.count()} · القديمان: {doors}', center.count() == 1 and doors == 0 and 'مركز السلامة وإدارة الطوارئ' in center.inner_text())
    center.click(); page.wait_for_load_state('networkidle')
    log('salama: الباب يفتح اللوحة باسمه', page.locator('h1').first.inner_text(), 'مركز السلامة وإدارة الطوارئ' in page.locator('h1').first.inner_text())
    link = page.locator('a.card', has_text='سجل بلاغات الشاغلين').first   # بطاقة اللوحة لا رابط القائمة الجانبية المخفي
    log('salama: ومنه سجل بلاغات الشاغلين', link.count() > 0 and 'سجل بلاغات الشاغلين' in page.content(), link.count() > 0 and 'سجل بلاغات الشاغلين' in page.content())
    link.click(); page.wait_for_load_state('networkidle')
    log('salama: السجل يفتح', page.url.replace(BASE, ''), '/app/incidents' in page.url)
    page.goto(BASE + '/app/places/7/file', wait_until='networkidle')
    chips = page.locator('#pfUnits [data-unit-chip]').count()
    badge = page.locator('h2.sec-h:has-text("الوحدات") .badge').first.inner_text()
    log('salama: ملف المكاتب الإدارية يعرض الإدارات كلها', f'{chips} إدارة · العدّاد {badge}', chips >= 30 and badge == str(chips))
    page.screenshot(path=os.path.join(SHOT, '25-3-salama-offices.png'), full_page=False)
    log('salama: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── الفني: الباب نفسه يفتح له السجل ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'fani', '1234'), 'فشل دخول الفني'
    page.goto(BASE + '/app', wait_until='networkidle')
    c = page.locator('#intents [data-intent="center"]').first
    href = c.get_attribute('href') if c.count() else None
    log('fani: الباب الواحد يفتح له سجل البلاغات', href, href is not None and href.endswith('/app/incidents'))
    log('fani: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()
    browser.close()

print('\n' + ('✗ أخطاء: ' + '، '.join(errs) if errs else '✓ بوابة ٢٥-٣ خضراء'))
sys.exit(1 if errs else 0)
