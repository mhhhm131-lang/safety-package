# -*- coding: utf-8 -*-
"""بوابة ٢٦-٨ (قرار ٦٧) على متصفح حقيقي (WebKit، iPhone 13): «أريد أن» بالمجموعات العشر نفسها التي في «ما ينتظرك»
وترتيبها وأيقوناتها، المجموعة الفارغة لا تظهر، «أتابع بلاغاتي» يفتح بلاغات صاحب الحساب، ولا زر لما له باب آخر.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-8.py [BASE]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
GROUPS = ['بلاغات الشاغلين', 'بلاغات الفحص', 'جولات الفحص', 'الطوارئ', 'الفريق الأولي', 'التصاريح', 'المخاطر', 'النماذج', 'المقاولون', 'الحسابات']
GONE = ['makani', 'forms', 'permit', 'permits', 'reports', 'settings', 'nominate', 'units', 'my_techs', 'my_coordinator', 'places', 'report',
        'sos', 'trigger', 'center', 'arrived', 'drill', 'teams', 'medical', 'systems']
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


def intents(page):
    rows = page.locator('#intents .intent-row')
    groups = [rows.nth(i).get_attribute('data-group') for i in range(rows.count())]
    icons = [rows.nth(i).get_attribute('data-icon') for i in range(rows.count())]
    keys = [page.locator('#intents [data-intent]').nth(i).get_attribute('data-intent') for i in range(page.locator('#intents [data-intent]').count())]
    per_row = [rows.nth(i).locator('[data-intent]').count() for i in range(rows.count())]
    return groups, icons, keys, per_row


def inbox_icons(page):
    secs = page.locator('#inboxList section')
    return {secs.nth(i).get_attribute('data-module'): secs.nth(i).locator('.grp-ic i').first.get_attribute('class') for i in range(secs.count())}


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── الموظف (أضعف حساب) على الجوال ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    page.goto(BASE + '/app', wait_until='networkidle')
    groups, icons, keys, per_row = intents(page)
    order_ok = groups == [g for g in GROUPS if g in groups]
    log('emp: مجموعات «أريد أن» بترتيب ما ينتظرك', groups, bool(groups) and order_ok and len(set(groups)) == len(groups))
    log('emp: لا مجموعة فارغة', per_row, all(n > 0 for n in per_row))
    log('emp: الأزرار', keys, 'my_reports' in keys and not any(k in keys for k in GONE))
    inb = inbox_icons(page)
    same_icon = all((f'bi {icons[i]}' == inb.get(g) or inb.get(g) is None) for i, g in enumerate(groups))
    log('emp: أيقونة المجموعة نفسها في ما ينتظرك (حيث تظهر)', {g: inb.get(g) for g in groups if inb.get(g)}, same_icon)
    # ضغطة واحدة: «أتابع بلاغاتي» ← شاشة بلاغاته
    page.click('#intents [data-intent="my_reports"]')
    page.wait_for_load_state('networkidle')
    log('emp: «أتابع بلاغاتي» بضغطة', page.url, page.url.endswith('/app/incidents/mine') and page.locator('#myReportsCount').count() == 1)
    n = int(page.locator('#myReportsCount').inner_text() or '0')
    log('emp: عدد بلاغاته المعروضة', n, n == page.locator('#myReportsList [data-incident]').count())
    page.screenshot(path=os.path.join(SHOT, '26-8-emp-mine-iphone.png'), full_page=True)
    page.goto(BASE + '/app', wait_until='networkidle')
    page.screenshot(path=os.path.join(SHOT, '26-8-emp-iphone.png'), full_page=True)
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── الفني على الجوال: أفحص مكاني بضغطة ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'fani', '1234'), 'فشل دخول الفني'
    page.goto(BASE + '/app', wait_until='networkidle')
    groups, icons, keys, per_row = intents(page)
    log('fani: «أفحص مكاني» في جولات الفحص، بلا «نماذج الفحص» ولا «مكاني»', keys, 'inspect' in keys and 'forms' not in keys and 'makani' not in keys and 'جولات الفحص' in groups)
    href = page.locator('#intents [data-intent="inspect"]').get_attribute('href')
    log('fani: الزر يفتح نموذج مكانه', href, bool(href) and href.endswith('/inspection-form.html'))
    log('fani: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة على الحاسب ──
    ctx, page, console = ctx_page(browser, mobile=False)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    groups, icons, keys, per_row = intents(page)
    expected = {'my_reports', 'plans', 'my_medical', 'roles', 'roles_map', 'gate', 'book', 'activate', 'hazards', 'myforms', 'sendform', 'worker', 'project', 'party', 'competency'}
    log('salama: ١٥ زراً بالضبط', sorted(keys), set(keys) == expected)
    log('salama: المجموعات بترتيب ما ينتظرك', groups, groups == [g for g in GROUPS if g in groups])
    # كل زر يفتح (200)
    bad = []
    for k in keys:
        href = page.locator(f'#intents [data-intent="{k}"]').get_attribute('href')
        r = page.request.get(BASE + href if href.startswith('/') else href)
        if r.status != 200: bad.append((k, r.status))
    log('salama: كل زر يفتح', bad or 'كلها 200', not bad)
    # الأبواب الداخلية لما خرج من القائمة
    page.goto(BASE + '/app/risk/active', wait_until='networkidle')
    log('salama: «اعتماد المخاطر» من داخل السجل', page.locator('#approvalQueueLink').count(), page.locator('#approvalQueueLink').count() == 1)
    page.goto(BASE + '/app', wait_until='networkidle')
    # لمن له أماكن كثيرة يرشّح المربع الرسم (٢٥-٢)؛ ملف المكان من رابط المربع نفسه
    file_href = page.locator('#places a[data-place="HZ-06"]').get_attribute('href')
    page.goto(file_href if file_href.startswith('http') else BASE + file_href, wait_until='networkidle')
    tenlink = page.locator('#pfForms a[href$="/app/inspections"]')
    log('salama: «النماذج العشرة» من داخل ملف المكان', tenlink.count(), tenlink.count() == 1)
    page.goto(BASE + '/app', wait_until='networkidle')
    page.screenshot(path=os.path.join(SHOT, '26-8-salama-desktop.png'), full_page=True)
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-٨ خضراء'))
sys.exit(1 if errs else 0)
