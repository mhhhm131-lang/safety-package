# -*- coding: utf-8 -*-
"""بوابة ٢٥-١ على متصفح حقيقي (WebKit، iPhone 13 ثم حاسب): الأماكن في الصفحة الأولى لكل حساب في نطاقه.

يقيس الضغطات: من الصفحة الأولى إلى ملف المكان بضغطة واحدة (كانت ثلاثاً: المزيد ← الأماكن وملفاتها ← المكان).
التشغيل: python webkit-25-1.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, os
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
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
            print(f'  دخول {user}: الصفحة {r.status if r else "?"} بلا نموذج — {page.url} — {page.content()[:200]!r}', flush=True)
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user)
        page.fill('input[name=password]', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            return True
        page.wait_for_timeout(20000)
    return False


def tiles(page):
    return page.locator('#places a.pl-tile').evaluate_all('els => els.map(e => [e.dataset.place, e.dataset.cls, e.getAttribute("href")])')


def tour(browser, name, user, pw, mobile, expect_all=False):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844} if mobile else {'width': 1440, 'height': 900},
                              device_scale_factor=2 if mobile else 1, is_mobile=mobile, has_touch=mobile, locale='ar-SA')
    page = ctx.new_page()
    page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    if not login(page, user, pw):
        log(f'{name}: دخول', 'فشل', False); ctx.close(); return
    page.goto(BASE + '/app', wait_until='networkidle')
    t = tiles(page)
    log(f'{name}: مربعات الأماكن في الصفحة الأولى', f'{len(t)} — {[x[0] for x in t]}', len(t) >= 1 and (not expect_all or len(t) == 9))
    vis = page.locator('#places').first.is_visible() if t else False
    log(f'{name}: الشبكة ظاهرة بلا فتح «التفاصيل»', vis, vis)
    body = page.content()
    log(f'{name}: لا رابط «الأماكن وملفاتها» في القائمة', 'الأماكن وملفاتها' not in body, 'الأماكن وملفاتها' not in body)
    log(f'{name}: لا شبكة ميتة', 'class="place lvl' not in body, 'class="place lvl' not in body)
    page.screenshot(path=os.path.join(SHOT, f'25-1-{name}-home.png'), full_page=True)
    if t:
        # ضغطة واحدة: المربع الأول ← ملف المكان
        page.locator('#places a.pl-tile').first.click()
        page.wait_for_load_state('networkidle')
        ok = '/file' in page.url and page.locator('h1.page-h').count() > 0
        log(f'{name}: ضغطة واحدة تفتح ملف المكان', f'{page.url.replace(BASE, "")} — {page.locator("h1.page-h").first.inner_text() if ok else "?"}', ok)
        # رابط «الأماكن» في رأس ملف المكان يعود إلى الصفحة الأولى عند الأماكن
        crumb = page.locator('a[href$="/app#places"]').first
        log(f'{name}: رابط الرجوع «الأماكن» يشير إلى الصفحة الأولى', crumb.count() > 0, crumb.count() > 0)
    # المسار القديم لا يُكسر
    r = page.goto(BASE + '/app/places/units', wait_until='networkidle')
    log(f'{name}: المسار القديم يحوّل إلى الصفحة الأولى', page.url.replace(BASE, ''), page.url.replace(BASE, '').startswith('/app') and '/places/units' not in page.url)
    log(f'{name}: أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close()


with sync_playwright() as p:
    browser = p.webkit.launch()
    tour(browser, 'emp-iphone', 'emp', '1234', True)
    tour(browser, 'tj.gm.e1-iphone', 'tj.gm.e1', TPW, True)
    tour(browser, 'fani-iphone', 'fani', '1234', True)
    tour(browser, 'mudir-iphone', 'mudir', '1234', True)
    tour(browser, 'salama-desktop', 'salama', '1234', False, expect_all=True)
    browser.close()

print('\n' + ('✗ أخطاء: ' + '، '.join(errs) if errs else '✓ بوابة ٢٥-١ خضراء'))
sys.exit(1 if errs else 0)
