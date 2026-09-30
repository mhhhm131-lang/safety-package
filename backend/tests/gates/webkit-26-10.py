# -*- coding: utf-8 -*-
"""بوابة ٢٦-١٠ (قرار ٦٦ «أرقام واحدة») على WebKit: الأرقام الأربعة الكبيرة حُذفت، والرسم وحده، وزر «التقارير» تحته لمن يملكها.

- iPhone 13: الإدارة العليا (تملك التقارير) — لا مربعات كبيرة، الشاشة تبدأ بالأماكن، الزر تحت أعمدة الرسم ويفتح التقارير وفيها «فجوة الاستجابة».
- iPhone 13: الموظف والمناوب (لا يملكان التقارير) — لا مربعات ولا زر، والرسم في مكانه.
- الحاسب: مسؤول السلامة — الأماكن والرسم أول الصفحة والزر تحت الرسم.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-10.py [BASE]
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


def ctx_page(browser, phone=True):
    if phone:
        ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    else:
        ctx = browser.new_context(viewport={'width': 1280, 'height': 800}, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def path(url):
    return url.replace(BASE, '').split('?')[0]


def no_tiles(page, who):
    n = page.locator('#tiles').count() + page.locator('[data-tile]').count()
    log(f'{who}: لا مربعات كبيرة', n, n == 0)
    bars = page.locator('#bars a.bar')
    vals = [bars.nth(i).get_attribute('data-k') + '=' + bars.nth(i).get_attribute('data-n') for i in range(bars.count())]
    log(f'{who}: الرسم بأعمدته الستة', vals, bars.count() == 6)


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── الإدارة العليا على الجوال: تملك التقارير ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'idara', '1234'), 'فشل دخول الإدارة العليا'
    page.goto(BASE + '/app', wait_until='networkidle')
    no_tiles(page, 'idara')
    top = page.locator('#placesCard').bounding_box()['y']
    log('idara: الأماكن أول الشاشة (قبل منتصفها)', f'{round(top)}px من 844', top < 422)
    door = page.locator('#reportsDoor')
    log('idara: زر «التقارير» واحد', [door.count(), door.inner_text().strip() if door.count() else ''], door.count() == 1 and door.inner_text().strip() == 'التقارير')
    inside = page.locator('#chart #reportsDoor').count() == 1
    by = page.locator('#bars').bounding_box(); dy = door.bounding_box()
    log('idara: الزر داخل بطاقة الرسم وتحت الأعمدة', [inside, round(by['y'] + by['height']), round(dy['y'])], inside and dy['y'] >= by['y'] + by['height'])
    log('idara: ارتفاع الزر يصلح للإصبع (≥ 44)', round(dy['height']), dy['height'] >= 44)
    page.screenshot(path=os.path.join(SHOT, '26-10-idara-top.png'))
    door.scroll_into_view_if_needed(); page.wait_for_timeout(300)
    page.screenshot(path=os.path.join(SHOT, '26-10-idara-chart.png'))
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#reportsDoor')
    body = page.inner_text('main')
    log('idara: ضغطة الزر تفتح التقارير وفيها «فجوة الاستجابة»', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == '/app/reports' and 'فجوة الاستجابة' in body)
    log('idara: «بلا إقرار» في التقارير', page.locator('[data-kpi="emergency_unack"]').inner_text().strip(), page.locator('[data-kpi="emergency_unack"]').count() == 1)
    log('idara: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── الموظف والمناوب على الجوال: لا يملكان التقارير ──
    for who in ['emp', 'munawib']:
        ctx, page, console = ctx_page(browser)
        assert login(page, who, '1234'), f'فشل دخول {who}'
        page.goto(BASE + '/app', wait_until='networkidle')
        no_tiles(page, who)
        log(f'{who}: لا زر تقارير', page.locator('#reportsDoor').count(), page.locator('#reportsDoor').count() == 0)
        top = page.locator('#placesCard').bounding_box()['y']
        log(f'{who}: الأماكن أول الشاشة', f'{round(top)}px', top < 422)
        if who == 'munawib':
            page.screenshot(path=os.path.join(SHOT, '26-10-munawib-top.png'))
        log(f'{who}: أخطاء', console or 'صفر', not console)
        ctx.close()

    # ── مسؤول السلامة على الحاسب ──
    ctx, page, console = ctx_page(browser, phone=False)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    no_tiles(page, 'salama (حاسب)')
    cnt = page.locator('#inboxCount')
    cards = page.locator('#inboxList [data-task]').count()
    shown = int(cnt.inner_text().strip()) if cnt.count() else 0
    log('salama: رقم «ما ينتظرك» في عنوان قسمه = عدد بطاقاته', [shown, cards], shown == cards and (shown > 0 or page.locator('#inboxEmpty').count() == 1))
    inside = page.locator('#chart #reportsDoor').count() == 1
    by = page.locator('#bars').bounding_box(); dy = page.locator('#reportsDoor').bounding_box()
    log('salama: زر «التقارير» داخل بطاقة الرسم وتحت الأعمدة', [inside, round(by['y'] + by['height']), round(dy['y'])], inside and dy['y'] >= by['y'] + by['height'])
    pc = page.locator('#placesCard').bounding_box(); ch = page.locator('#chart').bounding_box()
    log('salama: بطاقتا الأماكن والرسم متساويتا الارتفاع جنباً إلى جنب', [round(pc['height']), round(ch['height'])], abs(pc['height'] - ch['height']) < 2 and abs(pc['y'] - ch['y']) < 2)
    page.screenshot(path=os.path.join(SHOT, '26-10-salama-desktop.png'))
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#reportsDoor')
    log('salama: الزر يفتح التقارير', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == '/app/reports')
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-١٠ خضراء'))
sys.exit(1 if errs else 0)
