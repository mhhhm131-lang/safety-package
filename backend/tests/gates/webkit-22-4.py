# -*- coding: utf-8 -*-
"""بوابة ٢٢-٤ على متصفح حقيقي: المستجيب يردّ بزر.

(أ) المسعف من جواله: «استلمتُ» ثم «أنا في الطريق» على تنبيه ذعر، و«وصلتُ إلى الموقع» في حالة مفتوحة.
(ب) المناوب من حاسبه: «نوديَ» يغلق بطاقة النداء الهاتفي.

التشغيل: python webkit-22-4.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
# المسعف لا بد أن يكون من فريق المكان الذي تقع فيه الحالة (HZ-06)، وإلا فلا «وصلتُ» له — وهو الصواب
EMP, MEDIC, MUNAWIB = 'tj.gm.e1', 'tj.hz06.medic', 'tj.munawib'
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


def press(page, selector, url_part, back_to):
    with page.expect_response(lambda r: r.request.method == 'POST' and url_part in r.url):
        page.click(selector)
    page.goto(BASE + back_to, wait_until='domcontentloaded')
    return page.inner_text('body')


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    # ── تهيئة: المناوب يُنهي أي حالة سابقة ثم يفعّل واحدة في المكاتب ──
    desk = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA')
    dp = desk.new_page()
    dp.on('dialog', lambda d: d.accept())
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, 'salama', '1234'), 'فشل دخول مسؤول السلامة'

    dp.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    ctl = dp.locator('a[href*="/control"]').first.get_attribute('href')
    ctl = ctl if ctl.startswith('http') else BASE + ctl
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
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء قبل بوابة ٢٢-٤')
                with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
                    dp.click('#endModal button.btn-success')
                dp.wait_for_load_state('networkidle')
        dp.goto(ctl, wait_until='domcontentloaded')

    opts = dp.locator('select[name=place_id] option')
    val = None
    for i in range(opts.count()):
        if 'HZ-06' in (opts.nth(i).inner_text() or ''):
            val = opts.nth(i).get_attribute('value'); break
    if val: dp.select_option('select[name=place_id]', val)
    dp.select_option('select[name=incident_type]', 'fire')
    dp.fill('textarea[name=description]', 'بوابة ٢٢-٤')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
        dp.click('#triggerForm button[type=submit]')
    dp.wait_for_load_state('networkidle')
    iid = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url).group(1)
    log('تفعيل حالة في المكاتب', f'الحالة {iid}', True)

    # ── (ب) المناوب: «نوديَ» ──
    mun = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA')
    np = mun.new_page()
    np.on('console', lambda m: console.append(('مناوب', m.type, m.text)) if m.type == 'error' else None)
    assert login(np, MUNAWIB, TPW), 'فشل دخول المناوب'
    np.goto(BASE + '/app', wait_until='domcontentloaded')
    body = np.inner_text('body')
    card = [l for l in body.splitlines() if 'نادِ هاتفياً' in l]
    log('بطاقة النداء الهاتفي تظهر للمناوب', card[:1] or 'غير موجودة', bool(card))
    btn = np.locator('button:has-text("نوديَ"), a:has-text("نوديَ")').first
    log('  وفيها زر «نوديَ»', btn.count() > 0, btn.count() > 0)
    if btn.count():
        before = len([l for l in body.splitlines() if 'نادِ هاتفياً' in l])
        body = press(np, 'button:has-text("نوديَ"), a:has-text("نوديَ")', '/calls/', '/app')
        after = len([l for l in body.splitlines() if 'نادِ هاتفياً' in l])
        log('  الضغطة تغلق نداءً واحداً', f'قبل {before} بعد {after}', after == before - 1)

    # ── (أ) المسعف على iPhone 13 ──
    ip = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA')
    mp = ip.new_page()
    mp.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(mp, MEDIC, TPW), 'فشل دخول المسعف'

    mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    has_arrived = 'وصلتُ إلى الموقع' in body
    already = 'وصولك إلى الموقع مسجَّل' in body
    log('«وصلتُ إلى الموقع» على شاشة المسعف',
        'الزر ظاهر' if has_arrived else ('مسجَّل مسبقاً' if already else 'غير ظاهر — المسعف ليس من فريق هذا المكان'),
        has_arrived or already)
    if has_arrived:
        body = press(mp, 'button:has-text("وصلتُ إلى الموقع")', '/me/arrived', '/app/emergency/me')
        log('  بعد الضغطة', [l for l in body.splitlines() if 'مسجَّل' in l][:1] or 'لا تأكيد',
            'وصولك إلى الموقع مسجَّل' in body)
        dp.goto(BASE + f'/app/emergency/incidents/{iid}/live', wait_until='domcontentloaded')
        live = dp.inner_text('body')
        log('  والمركز يرى وصوله في الخط الزمني', 'وصل إلى الموقع' in live, 'وصل إلى الموقع' in live)

    # استغاثة من موظف ثم ردّ المسعف عليها
    ep = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    assert login(ep, EMP, TPW), 'فشل دخول الموظف'
    ep.goto(BASE + '/app/emergency/sos', wait_until='domcontentloaded')
    if ep.locator('button:has-text("طوارئ طبية")').count():
        with ep.expect_response(lambda r: r.request.method == 'POST' and r.url.rstrip('/').endswith('/app/emergency/sos')):
            ep.click('button:has-text("طوارئ طبية")')
        log('الموظف يطلق استغاثة', 'أُرسلت', True)
    else:
        log('الموظف يطلق استغاثة', 'له استغاثة مفتوحة أصلاً — تُستعمل', True)

    np.goto(BASE + '/app/emergency/panic', wait_until='domcontentloaded')
    href = np.locator('a[href*="/app/emergency/panic/"]').first.get_attribute('href') or ''
    aid = re.search(r'/panic/(\d+)', href)
    aid = aid.group(1) if aid else None
    log('التنبيه في لوحة المركز', f'رقمه {aid}', bool(aid))

    if aid:
        mp.goto(BASE + f'/app/emergency/panic/{aid}', wait_until='domcontentloaded')
        body = mp.inner_text('body')
        log('المسعف يرى زرّي الردّ', f'استلمتُ={"استلمتُ" in body} · في الطريق={"أنا في الطريق" in body}',
            ('استلمتُ' in body) and ('أنا في الطريق' in body))
        body = press(mp, 'button:has-text("استلمتُ")', '/respond', f'/app/emergency/panic/{aid}')
        log('  بعد «استلمتُ»', [l for l in body.splitlines() if 'الاستلام' in l][:1] or 'لا تغيّر',
            'تم الاستلام' in body)   # شارة حالة التنبيه تتغيّر إلى «تم الاستلام»
        body = press(mp, 'button:has-text("أنا في الطريق")', '/respond', f'/app/emergency/panic/{aid}')
        log('  وبعد «أنا في الطريق»', [l for l in body.splitlines() if 'الطريق' in l][:1] or 'لا تأكيد',
            'في الطريق' in body)
        mx = mp.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
        log('  لا تمرير أفقي', f'{mx}px', mx <= 1)

    log('أخطاء الطرفية', console if console else 'صفر', len(console) == 0)

print('\nWEBKIT-22-4:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
