# -*- coding: utf-8 -*-
"""بوابة ٢٦-١٢ (قرار ٦٦ «اسم واحد لكل شاشة») على WebKit iPhone 13: الاسم على الباب هو ما يُقرأ أعلى الشاشة بعد الضغط.

- المناوب (أضعف من يملك السجل والمركز): مربع المركز ← باب «سجل بلاغات الشاغلين» ← الشاشة بالاسم نفسه؛ وزر الرجوع في شاشات الطوارئ باسم المركز.
- مسؤول السلامة: كل رابط في «المزيد» يفتح شاشة عنوانها اسمه؛ «السجل العام للمعهد» كذلك؛ وزر «أحله» بلا الكسرة.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-12.py [BASE]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
SUFFIX = ' — معهد الإدارة العامة'
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


def title(page):
    return page.title().replace(SUFFIX, '').strip()


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── المناوب: من مربع المركز إلى السجل ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'munawib', '1234'), 'فشل دخول المناوب'
    page.goto(BASE + '/app', wait_until='networkidle')
    # من له أماكن كثيرة: المربع يرشّح الرسم، وزر بجانبه يفتح — ضغطتان
    page.click('#places a[data-place="HZ-00"]'); page.wait_for_timeout(400)
    open_label = page.locator('#chartFile').inner_text().strip()
    with page.expect_navigation(wait_until='domcontentloaded'):
        page.click('#chartFile')
    log('munawib: مربع المركز ثم «افتح صفحة المركز» يفتحان «مركز السلامة وإدارة الطوارئ»', [open_label, title(page)], open_label == 'افتح صفحة المركز' and title(page) == 'مركز السلامة وإدارة الطوارئ')
    page.go_back(wait_until='networkidle')
    page.click('#places a[data-place="HZ-01"]'); page.wait_for_timeout(400)
    log('munawib: مربع مكان عادي زره «افتح ملف المكان»', page.locator('#chartFile').inner_text().strip(), page.locator('#chartFile').inner_text().strip() == 'افتح ملف المكان')
    page.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('a[data-door="سجل بلاغات الشاغلين"]')
    h1 = page.locator('h1').first.inner_text().strip()
    log('munawib: باب «سجل بلاغات الشاغلين» يفتح شاشة بالاسم نفسه', [nav.value.status, title(page), h1], nav.value.status == 200 and title(page) == 'سجل بلاغات الشاغلين' and h1.startswith('سجل بلاغات الشاغلين'))
    page.screenshot(path=os.path.join(SHOT, '26-12-munawib-register.png'))
    page.goto(BASE + '/app/emergency/incidents', wait_until='domcontentloaded')
    back = page.locator('main a[href$="/app/emergency"]').first
    log('munawib: سجل الحالات الطارئة باسمه وزر الرجوع باسم المركز', [title(page), back.inner_text().strip()], title(page) == 'سجل الحالات الطارئة' and back.inner_text().strip() == 'مركز السلامة وإدارة الطوارئ')
    txt = page.inner_text('body')
    log('munawib: لا اسم قديم في الشاشة', [w for w in ['سجل مركز السلامة', 'مركز الطوارئ'] if w in txt], not any(w in txt for w in ['سجل مركز السلامة', 'مركز الطوارئ']))
    log('munawib: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة: المزيد والسجل العام وزر «أحله» ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    page.click('#navMore'); page.wait_for_selector('#moreNav.show', state='visible'); page.wait_for_timeout(500)
    links = page.locator('#moreNav a')
    doors = [(links.nth(i).inner_text().strip(), links.nth(i).get_attribute('href')) for i in range(links.count())]
    page.screenshot(path=os.path.join(SHOT, '26-12-salama-more.png'))
    bad = []
    for name, href in doors:
        r = page.goto(href, wait_until='domcontentloaded')
        if r.status != 200 or title(page) != name: bad.append((name, r.status, title(page)))
    log('salama: كل رابط في «المزيد» يفتح شاشة عنوانها اسمه', [n for n, _ in doors] if not bad else bad, len(doors) == 9 and not bad)
    page.goto(BASE + '/app', wait_until='networkidle')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#intents a[data-intent="book"]')
    log('salama: زر «السجل العام للمعهد» يفتح شاشة بالاسم نفسه', [nav.value.status, title(page)], nav.value.status == 200 and title(page) == 'السجل العام للمعهد')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.goto(BASE + '/app', wait_until='networkidle'); page.click('#intents a[data-intent="competency"]')
    log('salama: زر «الكفاءات والمهن» يفتح شاشة بالاسم نفسه', [nav.value.status, title(page)], title(page) == 'الكفاءات والمهن')
    # الزر «أحله» مقروءاً: أول بطاقة أو بند إحالة
    page.goto(BASE + '/app', wait_until='networkidle')
    page.click('#inboxList section[data-module="بلاغات الشاغلين"] > button.grp-h'); page.wait_for_timeout(600)
    b = page.locator('#inboxList [data-batch="incident.refer"]')
    if b.count():
        b.first.locator('.task-actions > button[data-bs-toggle="collapse"]').click(); page.wait_for_timeout(600)
    btn = page.locator('#inboxList [data-task$=":center"] a.btn-g', has_text='أحله').first
    label = btn.inner_text().strip()
    log('salama: الزر مكتوب «أحله» بلا كسرة', [label, [hex(ord(c)) for c in label]], label == 'أحله')
    btn.scroll_into_view_if_needed(); page.wait_for_timeout(300)
    btn.screenshot(path=os.path.join(SHOT, '26-12-ahilh.png'))
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-١٢ خضراء'))
sys.exit(1 if errs else 0)
