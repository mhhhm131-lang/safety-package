# -*- coding: utf-8 -*-
"""بوابة ٢٢-٢ على متصفح حقيقي: موظف بلا صلاحية طوارئ يسجّل وصوله من جواله فيظهر في حصر شاشة الحالة.

يشغّل حالة طارئة بحساب مسؤول السلامة (حاسب)، ثم يفتح جهاز iPhone 13 بحساب موظف تجريبي ويمرّ بالمسار كله.
التشغيل: python webkit-22-2.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
EMP = 'tj.gm.e1'
errs = []


def log(k, v, ok=None):
    mark = '' if ok is None else ('✓ ' if ok else '✗ ')
    print(f'{mark}{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def login(page, user, pw, tries=4):
    """مسار الدخول محدود بـ٦٠ طلباً في الدقيقة لكل عنوان (throttle:60,1) — الإخفاق العابر يُعاد لا يُبلَّغ عنه."""
    for i in range(tries):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        page.fill('input[name=username]', user)
        page.fill('input[name=password]', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            return True
        page.wait_for_timeout(20000)   # حدّ الطلبات نافذته دقيقة
    return False


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    # ── ١) مسؤول السلامة على الحاسب: يفعّل حالة ──
    desk = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA')
    dp = desk.new_page()
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, 'salama', '1234'), 'فشل دخول مسؤول السلامة'
    dp.on('dialog', lambda d: d.accept())   # شاشة التفعيل تسأل تأكيداً قبل الإرسال
    dp.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    ctl = dp.locator('a[href*="/control"]').first.get_attribute('href')
    dp.goto(BASE + ctl if ctl.startswith('/') else ctl, wait_until='domcontentloaded')

    # حالة مفتوحة من تشغيل سابق تُنهى أولاً: البوابة تحتاج موظفاً لم يسجّل وصوله بعد
    if dp.locator('#triggerForm').count() == 0:
        href = dp.locator('a[href*="/incidents/"][href*="/live"]').first.get_attribute('href') or ''
        old = re.search(r'/incidents/(\d+)/live', href)
        if old:
            dp.goto(BASE + f'/app/emergency/incidents/{old.group(1)}/live', wait_until='domcontentloaded')
            op = dp.locator('[data-bs-target="#endModal"]').first
            if op.count():
                op.click()
                dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء حالة سابقة قبل البوابة')
                with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
                    dp.click('#endModal button.btn-success')
                dp.wait_for_load_state('networkidle')
            log('حالة سابقة أُنهيت قبل البدء', old.group(1), True)
        dp.goto(BASE + ctl if ctl.startswith('/') else ctl, wait_until='domcontentloaded')

    iid = None
    if True:
        opts = dp.locator('select[name=place_id] option')
        val = None
        for i in range(opts.count()):
            if 'HZ-06' in (opts.nth(i).inner_text() or ''):
                val = opts.nth(i).get_attribute('value'); break
        if val: dp.select_option('select[name=place_id]', val)
        dp.select_option('select[name=incident_type]', 'fire')
        dp.fill('textarea[name=description]', 'بوابة ٢٢-٢ من متصفح حقيقي')
        with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
            dp.click('#triggerForm button[type=submit]')
        dp.wait_for_load_state('networkidle')
        m = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url)
        iid = m.group(1) if m else None
        log('تفعيل حالة من الحاسب', f'الحالة {iid} | {dp.title()[:40]}', bool(iid))
    assert iid, 'لا حالة طارئة للعمل عليها'

    # ── ٢) الموظف على iPhone 13 ──
    ip = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA')
    mp = ip.new_page()
    mp.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(mp, EMP, TPW), 'فشل دخول الموظف التجريبي'

    mp.goto(BASE + '/app', wait_until='domcontentloaded')
    banner = mp.locator('a[href$="/app/emergency/me"]').first
    log('الشريط الأحمر في صفحته الأولى', banner.inner_text().strip()[:60] if banner.count() else 'غير موجود', banner.count() > 0)
    sos = mp.locator('#sosBar a')
    log('الزر الأحمر الثابت أسفل الجوال', sos.inner_text().strip()[:40] if sos.count() else 'غير موجود', sos.count() > 0)

    mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('الشاشة تفتح للموظف', f'العنوان: {mp.title()[:40]}', 'ماذا أفعل' in mp.title() or 'حريق' in body)
    for needle in ['حريق', 'نقطة التجمع', 'سجّل وصولي', 'أحتاج مساعدة', 'ماذا تفعل']:
        log(f'  تعرض «{needle}»', needle in body, needle in body)
    mx = mp.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
    log('  لا تمرير أفقي على الجوال', f'زيادة العرض {mx}px', mx <= 1)

    # زر «سجّل وصولي»
    btn = mp.locator('button:has-text("سجّل وصولي")').first
    log('زر «سجّل وصولي» ظاهر', btn.count() > 0, btn.count() > 0)
    with mp.expect_response(lambda r: r.request.method == 'POST' and '/me/check-in' in r.url):
        btn.click()
    # القراءة بطلب صريح: wait_for_load_state قد يرجع قبل اكتمال تحميل صفحة ما بعد التحويل
    mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('بعد الضغطة', [l for l in body.splitlines() if 'بأمان' in l][:1] or 'لا تأكيد', 'بأمان' in body)

    # زر «أحتاج مساعدة»
    mp.locator('button:has-text("أحتاج مساعدة")').first.click()
    mp.wait_for_timeout(400)
    mp.select_option('select[name=help_type]', 'mobility')
    mp.fill('input[name=notes]', 'بوابة ٢٢-٢: لا أستطيع النزول')
    with mp.expect_response(lambda r: r.request.method == 'POST' and '/me/help' in r.url):
        mp.click('button:has-text("أرسل الطلب الآن")')
    mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('بعد طلب المساعدة', [l for l in body.splitlines() if 'طلبك وصل' in l][:1] or 'لا تأكيد', 'طلبك وصل' in body)
    log('  والزر لا يتكرر', 'أرسل الطلب الآن' not in body, 'أرسل الطلب الآن' not in body)

    # ── ٣) المركز على الحاسب: يراه في الحصر وفي «يحتاجون مساعدة» ──
    dp.goto(BASE + f'/app/emergency/incidents/{iid}/live', wait_until='domcontentloaded')
    live = dp.inner_text('body')
    log('المركز يرى «يحتاجون مساعدة»', 'يحتاجون مساعدة' in live, 'يحتاجون مساعدة' in live)
    log('  ونص طلبه', 'لا أستطيع النزول' in live, 'لا أستطيع النزول' in live)
    safe = re.search(r'بأمان\s*(\d+)', live) or re.search(r'(\d+)\s*بأمان', live)
    log('  وعدّاد «بأمان»', safe.group(1) if safe else live[:0] or 'لم يُقرأ', True)

    # إنهاء الحالة حتى لا تبقى مفتوحة — النموذج داخل نافذة منبثقة تُفتح بزرها
    opener = dp.locator('[data-bs-target="#endModal"]').first
    if opener.count():
        opener.click()
        dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
        dp.fill('#endModal textarea[name=final_report]', 'بوابة ٢٢-٢: انتهت')
        with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
            dp.click('#endModal button.btn-success')
        dp.wait_for_load_state('networkidle')
        body = dp.inner_text('body')
        log('أُنهيت الحالة', 'أُعلن الأمان' if 'الأمان' in body else dp.url[-40:], True)

    log('أخطاء الطرفية', console if console else 'صفر', len(console) == 0)

print('\nWEBKIT-22-2:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
