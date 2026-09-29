# -*- coding: utf-8 -*-
"""بوابة ٢٦-٤ (قرار ٦٦) على WebKit iPhone 13: صفحة البلاغ العامة للضيف — نوعان (عادي وسري)، تتبع، بلا كتلة «أريد أن»، والزر الأحمر اتصال.

التشغيل: PYTHONIOENCODING=utf-8 python webkit-26-4.py [BASE]
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


with sync_playwright() as p:
    browser = p.webkit.launch()
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(60000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    page.goto(BASE + '/incident', wait_until='networkidle')
    types = [page.locator('.type-card').nth(i).get_attribute('data-type') for i in range(page.locator('.type-card').count())]
    log('الضيف: نوعان فقط', types, types == ['normal', 'secret'])
    log('الضيف: لا كتلة «أريد أن»', page.locator('#intents').count(), page.locator('#intents').count() == 0)
    intents = [page.locator('[data-intent]').nth(i).get_attribute('data-intent') for i in range(page.locator('[data-intent]').count())]
    log('الضيف: لا زر نية غير الزر الأحمر', intents, intents == ['emergency-call'])
    log('الضيف: تتبع بلاغ موجود', page.locator('a[href$="/incident/track"]').count(), page.locator('a[href$="/incident/track"]').count() >= 1)
    h = page.evaluate('document.documentElement.scrollHeight')
    log('الضيف: طول الصفحة (بكسل)', h, h < 1700)
    page.screenshot(path=os.path.join(SHOT, '26-4-guest-iphone.png'), full_page=True)
    page.click('.type-card[data-type="secret"]'); page.wait_for_load_state('networkidle')
    log('الضيف: بطاقة «سري» تفتح النموذج', page.url, page.url.endswith('/incident/secret'))
    log('أخطاء المتصفح/الخادم', console or 'صفر', not console)
    ctx.close(); browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٦-٤ خضراء'))
sys.exit(1 if errs else 0)
