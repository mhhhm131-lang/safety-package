# -*- coding: utf-8 -*-
"""بوابة ٢٦-١١ (قرار ٦٦) على WebKit: البطاقات المتطابقة في «ما ينتظرك» بطاقة واحدة بعددها تفتح بنودها.

- iPhone 13، مسؤول السلامة (القاعدة المحلية الممتلئة): عدد البطاقات ينخفض والأشياء كما هي؛ دفعة «لا فني للمكان» تُفتح بضغطة وتُغلق بضغطة؛
  بند منها يفتح بلاغه؛ وبند «اكتبه» (فعل مباشر) يُنفَّذ فتنقص الدفعة واحداً.
- iPhone 13، الفني (أضعف حساب له بطاقات): بطاقاته بعناوينها لا تُدمج.
- الحاسب: لقطة للدفعة مفتوحة.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-11.py [BASE]
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
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def path(url):
    return url.replace(BASE, '').split('?')[0]


def open_group(page, module):
    sec = f'#inboxList section[data-module="{module}"]'
    page.click(sec + ' > button.grp-h')
    page.wait_for_selector(sec + ' > .collapse.show', state='visible'); page.wait_for_timeout(400)
    return sec


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── مسؤول السلامة على الجوال ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    items = page.locator('#inboxList [data-task]').count()
    cards = page.locator('#inboxList .card.task').count()
    batches = page.locator('#inboxList [data-batch]')
    shown = int(page.locator('#inboxCount').inner_text().strip())
    log('salama: الأشياء كما هي والعدّاد يعدّها', [items, shown], items == shown and items > 0)
    log('salama: عدد البطاقات انخفض', f'{items} شيئاً في {cards} بطاقة', cards < items)
    kinds = [(batches.nth(i).get_attribute('data-batch'), batches.nth(i).get_attribute('data-n')) for i in range(batches.count())]
    log('salama: الدفعات', kinds, batches.count() >= 1 and all(int(n) >= 2 for _, n in kinds))
    inside = sum(int(n) for _, n in kinds)
    log('salama: الحساب يطابق (بطاقات = أشياء − ما في الدفعات + الدفعات)', [cards, items - inside + len(kinds)], cards == items - inside + len(kinds))

    sec = open_group(page, 'بلاغات الشاغلين')
    b = page.locator(sec + ' [data-batch="incident.refer"]').first
    n = int(b.get_attribute('data-n'))
    title = b.locator('.fw-bold').first.inner_text().strip()
    log('salama: جملة الدفعة بالعدد والمكان والحال', title, 'لا فني للمكان' in title and (str(n) in title or 'بلاغان' in title))
    rows = b.locator('.batch-row')
    log('salama: البنود مطوية قبل الضغط', [rows.count(), rows.first.is_visible()], rows.count() == n and not rows.first.is_visible())
    page.screenshot(path=os.path.join(SHOT, '26-11-salama-group.png'))
    btn = b.locator('.task-actions > button[data-bs-toggle="collapse"]')
    btn.click(); page.wait_for_timeout(600)
    vis = sum(1 for i in range(rows.count()) if rows.nth(i).is_visible())
    log('salama: ضغطة تفتح البنود كلها', f'{vis} من {n}', vis == n)
    first = rows.first.locator('.fw-bold').inner_text().strip()
    log('salama: البند برقمه وعنوانه', first, first.startswith('ش-') and ' — ' in first)
    page.screenshot(path=os.path.join(SHOT, '26-11-salama-open.png'))
    btn.click(); page.wait_for_timeout(600)
    log('salama: ضغطة ثانية تغلقها', rows.first.is_visible(), not rows.first.is_visible())
    btn.click(); page.wait_for_timeout(600)
    target = rows.first.locator('a.btn-g').get_attribute('data-target')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        rows.first.locator('a.btn-g').click()
    log('salama: زر البند «أحِله» يفتح بلاغه', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == path(target))

    # بند بفعل مباشر: «اكتبه» ينقص دفعة «لا تقرير بعد الانتهاء» واحداً
    page.goto(BASE + '/app', wait_until='networkidle')
    sec = open_group(page, 'الطوارئ')
    b = page.locator(sec + ' [data-batch="emergency.aar"]')
    if b.count():
        n0 = int(b.get_attribute('data-n'))
        b.locator('.task-actions > button[data-bs-toggle="collapse"]').click(); page.wait_for_timeout(600)
        key = b.locator('.batch-row').first.get_attribute('data-task')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            b.locator('.batch-row').first.locator('form button.btn-g').click()
        st = nav.value.status
        page.goto(BASE + '/app', wait_until='networkidle')
        gone = page.locator(f'#inboxList [data-task="{key}"]').count() == 0
        b2 = page.locator('#inboxList [data-batch="emergency.aar"]')
        n1 = int(b2.get_attribute('data-n')) if b2.count() else 1
        log('salama: «اكتبه» على بند يُنفَّذ، والبند يخرج والدفعة تنقص واحداً', [st, key, n0, n1, gone], st == 200 and gone and n1 == n0 - 1)
    else:
        log('salama: لا دفعة «لا تقرير» على هذه القاعدة — لم يُجرَّب الفعل المباشر', '—')
    log('salama: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── الفني: بطاقاته بعناوينها لا تُدمج ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'fani', '1234'), 'فشل دخول الفني'
    page.goto(BASE + '/app', wait_until='networkidle')
    items = page.locator('#inboxList [data-task]').count()
    cards = page.locator('#inboxList .card.task').count()
    log('fani: بطاقاته كما هي بلا دفعات', [items, cards, page.locator('#inboxList [data-batch]').count()], items == cards and page.locator('#inboxList [data-batch]').count() == 0)
    log('fani: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── الحاسب: لقطة ──
    ctx, page, console = ctx_page(browser, phone=False)
    assert login(page, 'munawib', '1234'), 'فشل دخول المناوب'
    page.goto(BASE + '/app', wait_until='networkidle')
    sec = open_group(page, 'بلاغات الشاغلين')
    b = page.locator(sec + ' [data-batch="incident.refer"]').first
    b.locator('.task-actions > button[data-bs-toggle="collapse"]').click(); page.wait_for_timeout(600)
    log('munawib (حاسب): الدفعة تُفتح', b.locator('.batch-row').first.is_visible(), b.locator('.batch-row').first.is_visible())
    b.scroll_into_view_if_needed(); page.wait_for_timeout(300)
    page.screenshot(path=os.path.join(SHOT, '26-11-munawib-desktop.png'))
    log('munawib: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-١١ خضراء'))
sys.exit(1 if errs else 0)
