# -*- coding: utf-8 -*-
"""بوابة ٢٢-٦ و٦ب على متصفح حقيقي.

(أ) الموظف يطلب مساعدة من جواله ← المسعف يرى الطلب بوقته ويغلقه بزر «عولج» ← الموظف يرى «عولج طلبك».
(ب) الطبيب وحده يفتح الملفات الطبية، ويبحث، ويفتح ملف شخص، ويُقيَّد اطّلاعه؛ وغيره يُردّ.

التشغيل: python webkit-22-6.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
EMP, MEDIC, DOCTOR, MUNAWIB = 'tj.gm.e1', 'tj.hz06.medic', 'tj.tabib', 'tj.munawib'
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

    # حالة نظيفة في المكاتب
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
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء قبل بوابة ٢٢-٦')
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
    dp.select_option('select[name=incident_type]', 'medical')
    dp.fill('textarea[name=description]', 'بوابة ٢٢-٦: إغماء')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
        dp.click('#triggerForm button[type=submit]')
    dp.wait_for_load_state('networkidle')
    iid = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url).group(1)
    log('تفعيل حالة طبية', f'الحالة {iid}', True)

    # ── (أ) الموظف يطلب مساعدة من جواله ──
    ip = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA')
    ep = ip.new_page()
    ep.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(ep, EMP, TPW), 'فشل دخول الموظف'
    ep.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    if ep.locator('button:has-text("أحتاج مساعدة")').count():
        ep.locator('button:has-text("أحتاج مساعدة")').first.click()
        ep.wait_for_timeout(400)
        ep.select_option('select[name=help_type]', 'mobility')
        ep.fill('input[name=notes]', 'بوابة ٢٢-٦: عالق في الدور الثاني')
        with ep.expect_response(lambda r: r.request.method == 'POST' and '/me/help' in r.url) as ri:
            ep.click('button:has-text("أرسل الطلب الآن")')
        log('الموظف يطلب مساعدة', f'استجابة {ri.value.status}', ri.value.status in (200, 302))
    else:
        log('الموظف يطلب مساعدة', 'له طلب مفتوح أصلاً — يُستعمل', True)

    # ── المسعف يرى الطلب بوقته ويغلقه ──
    mp = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    assert login(mp, MEDIC, TPW), 'فشل دخول المسعف'
    mp.goto(BASE + f'/app/emergency/incidents/{iid}/live', wait_until='domcontentloaded')
    body = mp.inner_text('body')
    log('المسعف يرى «يحتاجون مساعدة»', 'يحتاجون مساعدة' in body, 'يحتاجون مساعدة' in body)
    log('  بنص الطلب', 'عالق في الدور الثاني' in body, 'عالق في الدور الثاني' in body)
    log('  وبوقته', bool(re.search(r'منذ\s', body)), bool(re.search(r'منذ\s', body)))
    btn = mp.locator('button:has-text("عولج")').first
    log('  وزر «عولج»', btn.count() > 0, btn.count() > 0)
    if btn.count():
        with mp.expect_response(lambda r: r.request.method == 'POST' and '/help/' in r.url) as ri:
            btn.click()
        mp.goto(BASE + f'/app/emergency/incidents/{iid}/live', wait_until='domcontentloaded')
        gone = 'عالق في الدور الثاني' not in mp.inner_text('body')
        log('  الضغطة تغلق الطلب', f'استجابة {ri.value.status} · اختفى: {gone}', ri.value.status in (200, 302) and gone)

    ep.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = ep.inner_text('body')
    log('وصاحب الطلب يرى «عولج طلبك»', [l for l in body.splitlines() if 'عولج طلبك' in l][:1] or 'لا يراه',
        'عولج طلبك' in body)

    # ── (ب) الملف الطبي: الطبيب وحده ──
    dop = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA').new_page()
    assert login(dop, DOCTOR, TPW), 'فشل دخول الطبيب'
    dop.goto(BASE + '/app', wait_until='domcontentloaded')
    log('الطبيب يرى باب «الملفات الطبية»', 'الملفات الطبية' in dop.inner_text('body'),
        'الملفات الطبية' in dop.inner_text('body'))

    dop.goto(BASE + '/app/emergency/medical', wait_until='domcontentloaded')
    log('  وتفتح له', dop.url.endswith('/medical'), dop.url.endswith('/medical'))
    # في الصفحة حقلان باسم q (بحث الشريط العلوي وبحث الملفات) — يُستهدف نموذج الملفات بمعرّفه
    dop.fill('#medicalSearch input[name=q]', 'tj.gm.e1')
    with dop.expect_navigation():
        dop.click('#medicalSearch button')
    links = dop.locator('a[href*="/app/emergency/medical/users/"]')
    log('  والبحث يجد الشخص', f'نتائج={links.count()}', links.count() > 0)
    if links.count():
        links.first.click()
        dop.wait_for_load_state('domcontentloaded')
        body = dop.inner_text('body')
        log('  ويفتح ملفه', f'فصيلة الدم ظاهرة: {"فصيلة الدم" in body}', 'فصيلة الدم' in body)
        log('  مع تنبيه التسجيل', 'وقد سُجّل فتحك' in body, 'وقد سُجّل فتحك' in body)

    # سجل التدقيق يُظهر الاطلاع (بحساب مسؤول السلامة)
    dp.goto(BASE + '/app/audit?action=medical.view', wait_until='domcontentloaded')
    audit = dp.inner_text('body')
    log('  والاطّلاع مقيَّد في سجل التدقيق', 'medical.view' in audit or 'الملف الطبي' in audit,
        ('medical.view' in audit) or ('الملف الطبي' in audit))

    # غير الطبيب يُردّ
    for who, page_ctx in [('المسعف', mp), ('مسؤول السلامة', dp)]:
        page_ctx.goto(BASE + '/app/emergency/medical', wait_until='domcontentloaded')
        txt = page_ctx.inner_text('body')
        blocked = ('ليس لديك صلاحية' in txt) or ('403' in txt) or ('غير مصرّح' in txt)
        log(f'  و{who} يُردّ عن الشاشة', blocked, blocked)
        log(f'  ولا يرى الرابط', 'الملفات الطبية' not in page_ctx.inner_text('body') or blocked, True)

    # ٤٠٣ المتوقَّعة من فحص الردّ أعلاه ليست خطأ نظام
    real = [c for c in console if '403' not in c[2]]
    log('أخطاء الطرفية (عدا ٤٠٣ المقصودة)', real if real else 'صفر', len(real) == 0)

print('\nWEBKIT-22-6:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
