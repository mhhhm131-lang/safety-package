# -*- coding: utf-8 -*-
"""بوابة ٢٦-١٤ (قبول المرحلة ٢٦ «باب واحد لكل شيء») على WebKit iPhone 13: جولة الحسابات السبعة تعدّ الأبواب والضغطات.

لكل حساب: روابط «المزيد»، أزرار «أريد أن»، «ما ينتظرك» (أشياء / بطاقات)، مربعات الأماكن؛ وكل باب يفتح (200).
والضغطات تُعدّ بضغطها فعلاً بمعرّفاتها: ملف المكان، صفحة المركز، شاشة داخل المركز، سجل بلاغات الشاغلين، التقارير، إعداد.
المخرج جدول «بعد» يُقارن بجدول ٢٧-٠٩ في backend/docs/ux-map-2026-09-27.md.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-14.py [BASE]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
ACCOUNTS = [('salama', 'مسؤول السلامة'), ('munawib', 'المناوب'), ('marafiq', 'مدير المرافق'), ('shuon', 'مدير الشؤون الإدارية'),
            ('mudir', 'مدير إدارة'), ('fani', 'الفني'), ('emp', 'الموظف')]
# جدول ٢٧-٠٩ (قبل): المزيد، أريد أن، ما ينتظرك، الأماكن — مدير الشؤون لم يُقَس يومها
BEFORE = {'salama': (47, 24, 53, 9), 'munawib': (40, 22, 53, 9), 'marafiq': (32, 17, 4, 9), 'mudir': (32, 19, 0, 1), 'fani': (20, 12, 3, 1), 'emp': (3, 8, 1, 1)}
errs = []
rows = []


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


def path(url):
    return url.replace(BASE, '').split('#')[0].split('?')[0]


def home(page):
    page.goto(BASE + '/app', wait_until='networkidle')


def press(page, selector):
    """ضغطة واحدة بمعرّف؛ تعيد حالة الاستجابة إن انتقلت الصفحة، وإلا None"""
    before = page.url
    try:
        with page.expect_navigation(wait_until='domcontentloaded', timeout=4000) as nav:
            page.click(selector)
        return nav.value.status if nav.value else 200
    except Exception:
        page.wait_for_timeout(300)
        return None if page.url == before else 200


def steps(page, who, what, selectors, want):
    """يعدّ الضغطات من الصفحة الأولى إلى الشاشة المطلوبة بضغطها فعلاً"""
    home(page)
    last = None
    for s in selectors:
        if page.locator(s).count() == 0:
            return None
        if s == '#navMore':
            page.click(s); page.wait_for_selector('#moreNav.show', state='visible'); page.wait_for_timeout(400); continue
        last = press(page, s)
    ok = path(page.url) == want and last == 200
    log(f'{who}: {what} بـ{len(selectors)} ضغطة', f'{last} {path(page.url)}', ok)
    return len(selectors) if ok else None


with sync_playwright() as p:
    browser = p.webkit.launch()
    for user, name in ACCOUNTS:
        ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
        page = ctx.new_page(); page.set_default_timeout(120000)
        console = []
        page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
        page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
        assert login(page, user, '1234'), f'فشل دخول {user}'
        home(page)
        more = page.locator('#moreNav a').count()
        intents = page.locator('#intents a[data-intent]')
        n_int = intents.count()
        items = page.locator('#inboxList [data-task]').count()
        cards = page.locator('#inboxList .card.task').count()
        tiles = page.locator('#places a.pl-tile').count()
        has_more_btn = page.locator('#navMore').count() == 1
        log(f'{name}: الصفحة الأولى', f'المزيد {more} · أريد أن {n_int} · ما ينتظرك {items} في {cards} بطاقة · الأماكن {tiles}', (more > 0) == has_more_btn)

        # كل باب يفتح لهذا الحساب: «المزيد» و«أريد أن» (الروابط الداخلية)
        hrefs = [page.locator('#moreNav a').nth(i).get_attribute('href') for i in range(more)]
        hrefs += [intents.nth(i).get_attribute('href') for i in range(n_int)]
        bad = []
        for h in hrefs:
            url = h if h.startswith('http') else BASE + h
            st = page.request.get(url).status
            if st != 200: bad.append((path(url), st))
        log(f'{name}: كل باب يفتح ({len(hrefs)} باباً)', bad or 'كلها 200', not bad)

        # الضغطات
        home(page)
        multi = page.locator('#places a.pl-tile[data-filter]').count() > 0
        own = page.locator('#places a.pl-tile:not([data-place="HZ-00"])').first
        # ما في الصفحة الأولى يُقرأ الآن — كل قياس بعده يغادر الصفحة
        has_center = page.locator('#places a[data-place="HZ-00"]').count() == 1
        center_filters = page.locator('#places a[data-place="HZ-00"][data-filter]').count() == 1
        has_reports = page.locator('#reportsDoor').count() == 1
        has_bar = page.locator('#bars a[data-k="incidents"][href]').count() == 1
        first_more = page.locator('#moreNav a').first.get_attribute('href') if has_more_btn else None
        c = {}
        if own.count():
            hz = own.get_attribute('data-place'); target = path(own.get_attribute('href'))
            sel = [f'#places a[data-place="{hz}"]'] + (['#chartFile'] if multi else [])
            c['ملف المكان'] = steps(page, name, f'ملف مكان ({hz})', sel, target)
        if has_center:
            sel0 = ['#places a[data-place="HZ-00"]'] + (['#chartFile'] if center_filters else [])
            c['صفحة المركز'] = steps(page, name, 'صفحة المركز', sel0, '/app/emergency')
            c['شاشة في المركز'] = steps(page, name, 'الفريق الأولي من المركز', sel0 + ['a[data-door="الفريق الأولي"]'], '/app/emergency/teams')
            c['سجل بلاغات الشاغلين'] = steps(page, name, 'سجل بلاغات الشاغلين من المركز', sel0 + ['a[data-door="سجل بلاغات الشاغلين"]'], '/app/incidents')
        if has_reports:
            c['التقارير'] = steps(page, name, 'التقارير', ['#reportsDoor'], '/app/reports')
        if first_more:
            c['إعداد'] = steps(page, name, 'أول إعداد في «المزيد»', ['#navMore', f'#moreNav a[href="{first_more}"]'], path(first_more))
        home(page)
        k0 = page.locator('#intents a[data-intent]').first
        if k0.count() and k0.get_attribute('href').startswith(BASE):
            c['أول زر في أريد أن'] = steps(page, name, f'«{k0.inner_text().strip()}»', [f'#intents a[data-intent="{k0.get_attribute("data-intent")}"]'], path(k0.get_attribute('href')))
        log(f'{name}: الضغطات', c, all(v is not None for v in c.values()))
        log(f'{name}: أخطاء المتصفح/الخادم', console or 'صفر', not console)
        rows.append((user, name, more, n_int, items, cards, tiles, c))
        if user in ('salama', 'emp'):
            home(page); page.screenshot(path=os.path.join(SHOT, f'26-14-{user}-home.png'), full_page=True)
        ctx.close()
    browser.close()

print('\nجدول قبل (٢٧-٠٩) ← بعد:')
print('| الحساب | المزيد | أريد أن | ما ينتظرك (أشياء ← بطاقات) | الأماكن | الضغطات |')
print('|---|---|---|---|---|---|')
for user, name, more, n_int, items, cards, tiles, c in rows:
    b = BEFORE.get(user)
    f = lambda i, v: (f'{b[i]} ← {v}' if b else f'— ← {v}')
    pr = ' · '.join(f'{k} {v}' for k, v in c.items())
    print(f'| {name} | {f(0, more)} | {f(1, n_int)} | {(str(b[2]) if b else "—")} ← {items} في {cards} | {f(3, tiles)} | {pr} |')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-١٤ خضراء'))
sys.exit(1 if errs else 0)
