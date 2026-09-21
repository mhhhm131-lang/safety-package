# -*- coding: utf-8 -*-
"""بوابة ٢٢-٥ على متصفح حقيقي: المركز يرسل رسالة للجميع بقالب، فتصل الموظف ويردّ فيزيد العدّاد،
ثم «تابِع من لم يردّ» تصل غير الرادّين وحدهم.

التشغيل: python webkit-22-5.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
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
        page.wait_for_timeout(20000)
    return False


def counts(text):
    """أزواج (ردّوا، من) من شارات الرسائل."""
    return [(int(a), int(b)) for a, b in re.findall(r'ردّوا\s+(\d+)\s+من\s+(\d+)', text)]


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    desk = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA')
    dp = desk.new_page()
    dp.on('dialog', lambda d: d.accept())
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, 'salama', '1234'), 'فشل دخول مسؤول السلامة'

    # حالة نظيفة
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
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء قبل بوابة ٢٢-٥')
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
    dp.fill('textarea[name=description]', 'بوابة ٢٢-٥')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
        dp.click('#triggerForm button[type=submit]')
    dp.wait_for_load_state('networkidle')
    iid = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url).group(1)
    live = f'/app/emergency/incidents/{iid}/live'
    log('تفعيل حالة', f'الحالة {iid}', True)

    body = dp.inner_text('body')
    log('بطاقة «أرسل رسالة للجميع» في شاشة الحالة', 'أرسل رسالة للجميع' in body, 'أرسل رسالة للجميع' in body)
    log('  والقوالب الجاهزة ظاهرة', f'أمر إخلاء={"أمر إخلاء" in body} · انتهاء الخطر={"انتهاء الخطر" in body}',
        ('أمر إخلاء' in body) and ('انتهاء الخطر' in body))

    with dp.expect_response(lambda r: r.request.method == 'POST' and '/message' in r.url):
        dp.click('button:has-text("أمر إخلاء")')
    dp.goto(BASE + live, wait_until='domcontentloaded')
    body = dp.inner_text('body')
    c = counts(body)
    log('بعد الإرسال بقالب', [l for l in body.splitlines() if 'أُرسلت إلى' in l][:1] or (c[:1] or 'لا شيء'), bool(c))
    log('  والنص مُعبَّأ لا قالباً خاماً', 'لا {{ }} في النص: ' + str('{{' not in body), '{{' not in body)
    total = c[0][1] if c else 0
    log('  وعدّاد الردود يبدأ من صفر', f'{c[0][0] if c else "?"} من {total}', bool(c) and c[0][0] == 0)

    # الموظف يردّ من جواله
    ip = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA')
    mp = ip.new_page()
    mp.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(mp, EMP, TPW), 'فشل دخول الموظف'
    mp.goto(BASE + '/app', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('الموظف يرى السؤال في بطاقاته', 'رسالة من المركز' in body or 'أنا بخير' in body,
        ('رسالة من المركز' in body) or ('أنا بخير' in body))
    # الردّ على رسالتنا نحن لا على أي بطاقة معلّقة أخرى: النموذج الذي يحمل رقم الرسالة
    msg_id = None
    dp.goto(BASE + live, wait_until='domcontentloaded')
    fu_form = dp.locator('form[action*="/messages/"][action*="/follow-up"]').first
    if fu_form.count():
        m = re.search(r'/messages/(\d+)/follow-up', fu_form.get_attribute('action') or '')
        msg_id = m.group(1) if m else None
    log('رقم رسالتنا', msg_id or 'لم يُقرأ', bool(msg_id))

    mp.goto(BASE + '/app', wait_until='domcontentloaded')
    btn = mp.locator(f'form[action*="/messages/{msg_id}/quick/safe"] button') if msg_id else mp.locator('none')
    log('بطاقة رسالتنا عند الموظف', f'أزرار={btn.count()}', btn.count() > 0)
    if btn.count():
        with mp.expect_response(lambda r: r.request.method == 'POST' and f'/messages/{msg_id}/quick/safe' in r.url) as ri:
            btn.first.click()
        st = ri.value.status
        mp.goto(BASE + '/app', wait_until='domcontentloaded')
        gone = mp.locator(f'form[action*="/messages/{msg_id}/quick/safe"]').count() == 0
        log('  وردّ بضغطة', f'استجابة {st} · البطاقة اختفت: {gone}', st in (200, 302) and gone)

    dp.goto(BASE + live, wait_until='domcontentloaded')
    body = dp.inner_text('body')
    c = counts(body)
    log('العدّاد تحرّك عند المركز', f'{c[0][0] if c else "?"} من {c[0][1] if c else "?"}', bool(c) and c[0][0] >= 1)

    # المتابعة — على رسالتنا بعينها
    fu = dp.locator(f'form[action*="/messages/{msg_id}/follow-up"] button').first if msg_id \
        else dp.locator('button:has-text("تابِع من لم يردّ")').first
    log('زر «تابِع من لم يردّ» ظاهر', fu.count() > 0, fu.count() > 0)
    if fu.count():
        with dp.expect_response(lambda r: r.request.method == 'POST' and '/follow-up' in r.url):
            fu.click()
        dp.goto(BASE + live, wait_until='domcontentloaded')
        body = dp.inner_text('body')
        c2 = counts(body)
        msg = [l for l in body.splitlines() if 'أُعيدت إلى' in l][:1]
        log('  المتابعة أُرسلت لغير الرادّين وحدهم', msg or f'{len(c2)} رسالة',
            bool(msg) or (len(c2) >= 2 and c2[0][1] < total))

    log('أخطاء الطرفية', console if console else 'صفر', len(console) == 0)

print('\nWEBKIT-22-5:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
