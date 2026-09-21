# -*- coding: utf-8 -*-
"""بوابة ٢٢-٣ على متصفح حقيقي: استغاثة من جوال موظف بلا صلاحية طوارئ تصل لوحة المركز وبطاقات المناوب.

التشغيل: python webkit-22-3.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
EMP = 'tj.gm.e1'
errs = []


def log(k, v, ok=None):
    print(('' if ok is None else ('✓ ' if ok else '✗ ')) + f'{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def login(page, user, pw, tries=4):
    for _ in range(tries):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        page.fill('input[name=username]', user)
        page.fill('input[name=password]', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            return True
        page.wait_for_timeout(20000)   # throttle:60,1 على مسار الدخول
    return False


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    # ── الموظف على iPhone 13 ──
    ip = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA')
    mp = ip.new_page()
    mp.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(mp, EMP, TPW), 'فشل دخول الموظف التجريبي'

    mp.goto(BASE + '/app', wait_until='domcontentloaded')
    bar = mp.locator('#sosBar a')
    txt = bar.inner_text().strip() if bar.count() else ''
    log('الزر الأحمر الثابت للموظف', txt or 'غير موجود', 'أستغيث' in txt)
    log('  ويشير إلى الفعل لا إلى لوحة المركز',
        bar.get_attribute('href') if bar.count() else '—',
        bool(bar.count()) and '/app/emergency/sos' in (bar.get_attribute('href') or ''))

    bar.click()
    mp.wait_for_load_state('domcontentloaded')
    body = mp.inner_text('body')
    log('شاشة الاستغاثة تفتح بضغطة', mp.url.split('8089')[-1], '/app/emergency/sos' in mp.url)
    for needle in ['طوارئ طبية', 'حريق أو دخان', 'تهديد أمني', 'أحتاج نجدة الآن']:
        log(f'  زر «{needle}»', needle in body, needle in body)
    mx = mp.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
    log('  لا تمرير أفقي', f'{mx}px', mx <= 1)

    btn = mp.locator('button:has-text("طوارئ طبية")').first
    with mp.expect_response(lambda r: r.request.method == 'POST' and r.url.rstrip('/').endswith('/app/emergency/sos')):
        btn.click()
    mp.goto(BASE + '/app/emergency/sos', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('بعد الضغطة الواحدة', [l for l in body.splitlines() if 'وصلت' in l or 'استغاثتك' in l][:1] or 'لا تأكيد',
        'استغاثتك وصلت المركز' in body)
    log('  والأزرار لا تتكرر', 'طوارئ طبية' not in body, 'طوارئ طبية' not in body)

    # ── المناوب على الحاسب ──
    desk = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA')
    dp = desk.new_page()
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, 'tj.munawib', TPW), 'فشل دخول المناوب'

    dp.goto(BASE + '/app', wait_until='domcontentloaded')
    home = dp.inner_text('body')
    log('المناوب يرى التنبيه في «ما ينتظرك»', 'تنبيه ذعر' in home, 'تنبيه ذعر' in home)

    dp.goto(BASE + '/app/emergency/panic', wait_until='domcontentloaded')
    dash = dp.inner_text('body')
    log('ولوحة الذعر تعرضه', [l for l in dash.splitlines() if 'طبي' in l][:1] or dash[:60], 'طبي' in dash)

    log('أخطاء الطرفية', console if console else 'صفر', len(console) == 0)

print('\nWEBKIT-22-3:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
