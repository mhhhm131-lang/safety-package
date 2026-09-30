# -*- coding: utf-8 -*-
"""بوابة ٢٦-٩ (قرار ٦٦) على WebKit iPhone 13: «المزيد» للإعدادات فقط.

- من لا إعداد له (الموظف، مدير الإدارة، المكتب الاستشاري) لا زر «المزيد» عنده.
- ما خرج من القائمة له باب يفتح: السجلات في «أريد أن»، ومن لا مكان له يأخذ سجل التصاريح ومخاطر الإدارات هناك.
- مسؤول السلامة: ضغطة «المزيد» تفتح تسعة روابط، كل رابط يفتح؛ المناوب ثلاثة.
- حساب المقاول (إن وُجد محلياً بطرفه): بوابته تحمل «أريد أن».

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-9.py [BASE] [حساب_المقاول]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
CONTRACTOR = sys.argv[2] if len(sys.argv) > 2 else None
SHOT = os.environ.get('SHOT_DIR', '.')
errs = []

NINE = ['/app/settings', '/app/users', '/app/org', '/app/places', '/app/audit', '/app/mail', '/app/closeout', '/app/incidents/settings', '/app/emergency/settings']


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
    return url.replace(BASE, '').split('?')[0]


def intents(page):
    b = page.locator('#intents a[data-intent]')
    return {b.nth(i).get_attribute('data-intent'): b.nth(i).get_attribute('href') for i in range(b.count())}


def press_intent(page, who, key, want_path):
    """ضغطة على زر «أريد أن» بمعرّفه، والاستجابة بحالتها"""
    page.goto(BASE + '/app', wait_until='networkidle')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click(f'#intents a[data-intent="{key}"]')
    st = nav.value.status if nav.value else None
    log(f'{who}: «{key}» بضغطة من الصفحة الأولى', f'{st} {path(page.url)}', st == 200 and path(page.url) == want_path)


def no_more(page, who):
    page.goto(BASE + '/app', wait_until='networkidle')
    log(f'{who}: لا زر «المزيد» ولا قائمة', [page.locator('#navMore').count(), page.locator('#moreNav').count()], page.locator('#navMore').count() == 0 and page.locator('#moreNav').count() == 0)
    log(f'{who}: الجرس في الشريط', page.locator('nav.topbar a.bell').get_attribute('href'), page.locator('nav.topbar a.bell').is_visible())


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── الموظف (أضعف حساب) ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'emp', '1234'), 'فشل دخول الموظف'
    no_more(page, 'emp')
    it = intents(page)
    log('emp: أزراره في «أريد أن»', sorted(it), 'myforms' in it and 'projects_log' not in it)
    press_intent(page, 'emp', 'myforms', '/app/forms/mine')
    page.goto(BASE + '/app', wait_until='networkidle')
    page.screenshot(path=os.path.join(SHOT, '26-9-emp-top.png'))
    log('emp: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()

    # ── مدير الإدارة: يقرأ السجلات ولا ينشئ ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'mudir', '1234'), 'فشل دخول مدير الإدارة'
    no_more(page, 'mudir')
    it = intents(page)
    log('mudir: السجلات الأربعة في «أريد أن» بلا أزرار الإنشاء', sorted(it), all(k in it for k in ['projects_log', 'parties_log', 'workers_log', 'forms_log']) and not any(k in it for k in ['project', 'party', 'worker', 'sendform', 'permits_log', 'risks_log']))
    for key, want in [('projects_log', '/app/projects'), ('parties_log', '/app/external-parties'), ('workers_log', '/app/workers'), ('forms_log', '/app/forms')]:
        press_intent(page, 'mudir', key, want)
    # سجل التصاريح من عمود الرسم
    page.goto(BASE + '/app', wait_until='networkidle')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#bars a[data-k="permits"]')
    log('mudir: سجل التصاريح من عمود «تصاريح فعالة»', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == '/app/permits')
    page.goto(BASE + '/app', wait_until='networkidle')
    page.locator('#intents').scroll_into_view_if_needed()
    page.screenshot(path=os.path.join(SHOT, '26-9-mudir-intents.png'))
    log('mudir: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── المكتب الاستشاري: بلا مكان — لا مربعات ولا رسم، فسجل التصاريح ومخاطر الإدارات زرّان ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'maktab', '1234'), 'فشل دخول المكتب الاستشاري'
    no_more(page, 'maktab')
    it = intents(page)
    log('maktab: بلا مربعات أماكن', page.locator('#places .pl-tile').count(), page.locator('#places .pl-tile').count() == 0)
    log('maktab: سجل التصاريح ومخاطر الإدارات في «أريد أن»', sorted(it), 'permits_log' in it and 'risks_log' in it)
    press_intent(page, 'maktab', 'permits_log', '/app/permits')
    press_intent(page, 'maktab', 'risks_log', '/app/risk/active')
    log('maktab: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── المناوب: ثلاثة إعدادات ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'munawib', '1234'), 'فشل دخول المناوب'
    page.goto(BASE + '/app', wait_until='networkidle')
    links = page.locator('#moreNav a')
    got = [path(links.nth(i).get_attribute('href')) for i in range(links.count())]
    log('munawib: «المزيد» = المستخدمون، الهيكل، مهل التصعيد', got, got == ['/app/users', '/app/org', '/app/emergency/settings'])
    log('munawib: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── مسؤول السلامة: ضغطة تفتح تسعة روابط، وكل رابط يفتح ──
    ctx, page, console = ctx_page(browser)
    assert login(page, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    page.goto(BASE + '/app', wait_until='networkidle')
    page.click('#navMore'); page.wait_for_selector('#moreNav.show', state='visible'); page.wait_for_timeout(500)
    links = page.locator('#moreNav a')
    got = [path(links.nth(i).get_attribute('href')) for i in range(links.count())]
    log('salama: «المزيد» تسعة روابط بترتيبها', got, got == NINE)
    names = [links.nth(i).inner_text().strip() for i in range(links.count())]
    log('salama: أسماؤها', names, len(names) == 9)
    h = page.evaluate("document.querySelector('#moreNav .offcanvas-body').scrollHeight")
    log('salama: القائمة كلها في شاشة واحدة بلا تمرير', f'{h}px من 844', h <= 844 - 60)
    page.screenshot(path=os.path.join(SHOT, '26-9-salama-more.png'))
    # الضغطة الثانية: الرابط الأول من داخل القائمة المفتوحة
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.click('#moreNav a[href$="/app/settings"]')
    log('salama: «الإعدادات» بضغطتين (المزيد ← الرابط)', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == '/app/settings')
    for u in NINE[1:]:
        r = page.goto(BASE + u, wait_until='domcontentloaded')
        log(f'salama: {u} يفتح', r.status, r.status == 200)
    it_page = page.goto(BASE + '/app', wait_until='networkidle')
    it = intents(page)
    log('salama: السجلات الأربعة في «أريد أن» مع أزرار الإنشاء', sorted(it), all(k in it for k in ['projects_log', 'parties_log', 'workers_log', 'forms_log', 'project', 'party', 'worker', 'sendform']) and 'permits_log' not in it and 'risks_log' not in it)
    log('salama: عدد أزرار «أريد أن»', len(it), len(it) == 19)
    # التقارير من الرقم الكبير، والإشعارات من الجرس
    log('salama: التقارير من «فجوة الاستجابة»', page.locator('#tiles a[data-tile="gap"]').get_attribute('href'), path(page.locator('#tiles a[data-tile="gap"]').get_attribute('href')) == '/app/reports')
    page.locator('#intents').scroll_into_view_if_needed()
    page.screenshot(path=os.path.join(SHOT, '26-9-salama-intents.png'))
    log('salama: أخطاء', console or 'صفر', not console)
    ctx.close()

    # ── المقاول بطرفه: بوابته تحمل «أريد أن» ──
    if CONTRACTOR:
        ctx, page, console = ctx_page(browser)
        assert login(page, CONTRACTOR, '1234'), 'فشل دخول المقاول'
        page.goto(BASE + '/app', wait_until='networkidle')
        log('contractor: يفتح على بوابته', path(page.url), path(page.url) == '/app/contractor')
        log('contractor: لا زر «المزيد»', page.locator('#navMore').count(), page.locator('#navMore').count() == 0)
        it = intents(page)
        log('contractor: «أريد أن» في البوابة بلا تكرار لأزرارها', sorted(it), all(k in it for k in ['myforms', 'projects_log', 'parties_log', 'competency', 'gate']) and not any(k in it for k in ['portal', 'workers_log', 'worker', 'permits_log']))
        for key, want in [('myforms', '/app/forms/mine'), ('projects_log', '/app/projects'), ('gate', '/app/permits/gate')]:
            page.goto(BASE + '/app/contractor', wait_until='networkidle')
            with page.expect_navigation(wait_until='domcontentloaded') as nav:
                page.click(f'#intents a[data-intent="{key}"]')
            log(f'contractor: «{key}» بضغطة من البوابة', f'{nav.value.status} {path(page.url)}', nav.value.status == 200 and path(page.url) == want)
        page.goto(BASE + '/app/contractor', wait_until='networkidle')
        page.locator('#intents').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, '26-9-contractor.png'))
        log('contractor: أخطاء', console or 'صفر', not console)
        ctx.close()
    else:
        log('contractor: لم يُجرَّب — لا حساب مقاول بطرفه مُرِّر للسكربت', '—')

    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-٩ خضراء'))
sys.exit(1 if errs else 0)
