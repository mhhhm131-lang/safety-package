# -*- coding: utf-8 -*-
"""بوابة ٢٦-٦-ب (قرار ٦٨) على WebKit iPhone 13: ملف المكان شاشة واحدة — سطر الوحدات ← سبعة أزرار بحالها ← الأقسام مطوية؛
الزر يفتح قسمه في مكانه ويغلقه؛ المرشّح يحصر البلاغات ويعيد العدّ؛ رابط بمرساة داخل قسم يفتحه.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-6b.py [BASE]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
SCREEN = 844
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
    ctx = browser.new_context(viewport={'width': 390, 'height': SCREEN}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def buttons(page):
    b = page.locator('#pfGrid .pf-btn')
    return [(b.nth(i).get_attribute('id'), b.nth(i).get_attribute('data-state')) for i in range(b.count())]


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── الموظف (أضعف حساب): مكانه المكاتب — ضغطة واحدة من المربع ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    page.goto(BASE + '/app', wait_until='networkidle')
    page.click('#places a[data-place="HZ-06"]'); page.wait_for_load_state('networkidle')
    log('emp: المربع يفتح الملف بضغطة', page.url, page.url.endswith('/file'))
    h = page.evaluate('document.documentElement.scrollHeight')
    log('emp: الملف قبل الفتح ≤ شاشة ونصف', f'{h}px', h <= SCREEN * 1.5)
    bs = buttons(page)
    log('emp: سبعة أزرار بترتيبها وحالها', bs, [i for i, _ in bs] == ['pfPlanSafety', 'pfPlanResponse', 'pfForms', 'pfRisks', 'pfNow', 'pfTeamH', 'pfPermit'] and all(s for _, s in bs))
    hidden = [page.locator('#' + s).is_hidden() for s in ['pfSecForms', 'pfSecRisks', 'pfSecNow', 'pfSecTeam']]
    log('emp: الأقسام الأربعة مطوية', hidden, all(hidden))
    dims = [i for i, s in bs if s == 'dim']
    log('emp: الباهت باسم صاحبه (المخاطر والتصريح)', dims, dims == ['pfRisks', 'pfPermit'])
    page.screenshot(path=os.path.join(SHOT, '26-6b-emp-closed.png'), full_page=True)
    # ضغطة تفتح، وضغطة تغلق
    page.click('#pfForms'); page.wait_for_timeout(400)
    log('emp: ضغطة تفتح نماذج الفحص', page.locator('#pfSecForms').is_visible(), page.locator('#pfSecForms').is_visible() and page.locator('#pfForms').get_attribute('aria-expanded') == 'true')
    page.screenshot(path=os.path.join(SHOT, '26-6b-emp-open.png'), full_page=True)
    page.click('#pfForms'); page.wait_for_timeout(300)
    log('emp: ضغطة ثانية تغلقه', page.locator('#pfSecForms').is_hidden(), page.locator('#pfSecForms').is_hidden())
    # المكاتب: سطر الوحدات قائمة اختيار بالإدارات كلها
    sel = page.locator('#pfUnitSelect')
    n_opts = sel.locator('option').count() if sel.count() else 0
    log('emp: المكاتب — قائمة اختيار بالإدارات (الكل + الإدارات)', n_opts, sel.count() == 1 and n_opts > 12)
    if sel.count():
        first = sel.locator('option').nth(1).get_attribute('value')
        sel.select_option(first); page.wait_for_timeout(300)
        now_txt = page.locator('#pfNow .s').inner_text()
        log('emp: اختيار إدارة يعيد عدّ «المفتوح الآن» لها', now_txt, first in now_txt)
        sel.select_option(''); page.wait_for_timeout(200)
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة: القبو — رقاقات الوحدات تحصر البلاغات، والمرساة تفتح قسمها ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    href = page.locator('#places a[data-place="HZ-01"]').get_attribute('href')
    page.goto(href if href.startswith('http') else BASE + href, wait_until='networkidle')
    bs = buttons(page)
    log('salama: القبو — سبعة أزرار وكل زر بحاله', bs, len(bs) == 7 and all(s for _, s in bs))
    chips = page.locator('#pfUnits .pf-chip')
    log('salama: رقاقات الوحدات (الكل أولاً)', [chips.nth(i).inner_text() for i in range(chips.count())], chips.count() >= 1 and chips.nth(0).inner_text().strip() == 'الكل')
    page.click('#pfNow'); page.wait_for_timeout(300)
    total = page.locator('#pfReports [data-unit], #pfIncidents [data-unit]').count()
    if chips.count() > 1:
        chips.nth(1).click(); page.wait_for_timeout(300)
        unit = chips.nth(1).get_attribute('data-unit')
        visible = page.locator('#pfReports [data-unit]:not([hidden]), #pfIncidents [data-unit]:not([hidden])').count()
        mism = page.locator(f'#pfReports [data-unit]:not([hidden]):not([data-unit="{unit}"]), #pfIncidents [data-unit]:not([hidden]):not([data-unit="{unit}"])').count()
        log(f'salama: اختيار «{unit}» يحصر البلاغات', f'{visible} من {total} · خارج الوحدة {mism}', mism == 0 and unit in page.locator('#pfNow .s').inner_text())
        chips.nth(0).click(); page.wait_for_timeout(300)
        back = page.locator('#pfReports [data-unit]:not([hidden]), #pfIncidents [data-unit]:not([hidden])').count()
        log('salama: «الكل» يعيد الجميع', back, back == total)
    page.goto(page.url.split('#')[0] + '#pfReports', wait_until='networkidle'); page.wait_for_timeout(500)
    page.reload(wait_until='networkidle'); page.wait_for_timeout(500)
    log('salama: الرابط بمرساة #pfReports يفتح قسم «المفتوح الآن» وحده', [page.locator('#pfSecNow').is_visible(), page.locator('#pfSecForms').is_hidden()], page.locator('#pfSecNow').is_visible() and page.locator('#pfSecForms').is_hidden())
    page.screenshot(path=os.path.join(SHOT, '26-6b-salama-hz01.png'), full_page=True)
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-٦-ب خضراء'))
sys.exit(1 if errs else 0)
