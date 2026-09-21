# -*- coding: utf-8 -*-
"""بوابة ٢٢-٨ و٢٢-٩ على متصفح حقيقي.

(٨) المكان مُملوء مما يعرفه النظام عند التفعيل، ويبقى قابلاً للتغيير؛ و«إخلاء أو إغلاق» لم تعد زراً ثانياً.
(٩) باب واحد باسم «مركز السلامة وإدارة الطوارئ» بترتيبه، ولا يُخفى شيء («تنتظر التركيب» ظاهرة).

التشغيل: python webkit-22-89.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
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
        page.wait_for_timeout(20000)
    return False


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    dp = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA').new_page()
    dp.on('dialog', lambda d: d.accept())
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, 'tj.munawib', TPW), 'فشل دخول المناوب'

    # ── (٩) الباب الواحد وترتيبه ──
    dp.goto(BASE + '/app', wait_until='domcontentloaded')
    dp.click('#navMore')
    dp.wait_for_timeout(600)
    side = dp.inner_text('#moreNav')
    log('الباب الواحد باسمه', 'مركز السلامة وإدارة الطوارئ' in side, 'مركز السلامة وإدارة الطوارئ' in side)
    log('  والبابان القديمان اختفيا',
        f'بلاغات الشاغلين={"بلاغات الشاغلين" in side}',
        'بلاغات الشاغلين' not in side)

    order = ['مركز السلامة وإدارة الطوارئ', 'ما يجري الآن', 'الحالات الطارئة', 'سجل مركز السلامة',
             'خطط الاستجابة', 'الفريق الأولي', 'تنتظر التركيب']
    idx = [side.find(t) for t in order]
    log('  والترتيب: ما يجري ← البلاغات ← الخطط ← الفريق ← تنتظر التركيب',
        ' ثم '.join(order[:3]) + ' …', all(i >= 0 for i in idx) and idx == sorted(idx))

    for t in ['أنظمة المبنى', 'الأساور', 'الكاميرات']:
        log(f'  ولم يُخفَ «{t}»', t in side, t in side)

    # ── (٨) المكان مُملوء ──
    dp.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    ctl = dp.locator('a[href*="/control"]').first.get_attribute('href')
    ctl = ctl if ctl.startswith('http') else BASE + ctl
    # حالة مفتوحة تُنهى أولاً حتى يظهر نموذج التفعيل
    dp.goto(ctl, wait_until='domcontentloaded')
    if dp.locator('#triggerForm').count() == 0:
        href = dp.locator('a[href*="/incidents/"][href*="/live"]').first.get_attribute('href') or ''
        old = re.search(r'/incidents/(\d+)/live', href)
        if old:
            dp.goto(BASE + f'/app/emergency/incidents/{old.group(1)}/live', wait_until='domcontentloaded')
            op = dp.locator('[data-bs-target="#endModal"]').first
            if op.count():
                op.click()
                dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء قبل بوابة ٢٢-٨')
                with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
                    dp.click('#endModal button.btn-success')
                dp.wait_for_load_state('networkidle')
        dp.goto(ctl, wait_until='domcontentloaded')

    # بلا رمز: مكان حساب المناوب (HZ-00)
    picked = dp.locator('#triggerForm select[name=place_id] option:checked').inner_text().strip()
    log('بلا رابط: المكان من حساب المفعِّل', picked[:30], picked.startswith('HZ-00'))
    log('  ولا يسأل «اختر المكان»', 'اختر المكان' not in dp.inner_text('#triggerForm'),
        'اختر المكان' not in dp.inner_text('#triggerForm'))
    log('  ويقول إنه مُملوء ويمكن تغييره', 'مُملوء مما يعرفه النظام' in dp.inner_text('#triggerForm'),
        'مُملوء مما يعرفه النظام' in dp.inner_text('#triggerForm'))
    n = dp.locator('#triggerForm select[name=place_id] option').count()
    log('  والأماكن كلها باقية للتغيير', f'{n} خياراً', n >= 9)

    # جاء من ملف مكان: الرمز يغلب
    dp.goto(ctl + '?place=HZ-07', wait_until='domcontentloaded')
    picked = dp.locator('#triggerForm select[name=place_id] option:checked').inner_text().strip()
    log('من ملف مكان: الرمز يغلب', picked[:30], picked.startswith('HZ-07'))

    # الإغلاق الأمني باقٍ في الشاشة نفسها
    log('  والإغلاق الأمني في الشاشة نفسها', 'الإغلاق الأمني' in dp.inner_text('body'),
        'الإغلاق الأمني' in dp.inner_text('body'))

    # ── الزر الأحمر: «إخلاء أو إغلاق» لم تعد نية ثانية ──
    mp = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    assert login(mp, 'tj.munawib', TPW), 'فشل دخول المناوب على الجوال'
    mp.goto(BASE + '/app', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('نية «إخلاء أو إغلاق» لم تعد مستقلة', 'data-intent="lockdown" غير موجود',
        'data-intent="lockdown"' not in mp.content())
    log('  ونية «فعّل» تذكر الإخلاء والإغلاق', 'إخلاء' in mp.content(), 'إخلاء' in mp.content())
    mx = mp.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
    log('  لا تمرير أفقي', f'{mx}px', mx <= 1)

    real = [c for c in console if '403' not in c[2]]
    log('أخطاء الطرفية', real if real else 'صفر', len(real) == 0)

print('\nWEBKIT-22-89:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
