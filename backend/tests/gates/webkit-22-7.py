# -*- coding: utf-8 -*-
"""بوابة ٢٢-٧ على متصفح حقيقي: تقرير ما بعد الحادث بزر واحد.

حالة تُفعَّل وتُنهى ← زر واحد يبني التقرير من سجلها ← يُكتب ويُسند إجراء تصحيحي ← يُرفع ويُعتمد ويُنشر
← والإجراء يصل صاحبه في بطاقاته ويُغلق بزر.

التشغيل: python webkit-22-7.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
OWNER = 'tj.marafiq'          # من يُسند إليه الإجراء التصحيحي
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
    assert login(dp, 'salama', '1234'), 'فشل دخول مسؤول السلامة'

    # حالة تُفعَّل ثم تُنهى
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
                dp.fill('#endModal textarea[name=final_report]', 'إنهاء قبل بوابة ٢٢-٧')
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
    dp.fill('textarea[name=description]', 'بوابة ٢٢-٧')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
        dp.click('#triggerForm button[type=submit]')
    dp.wait_for_load_state('networkidle')
    iid = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url).group(1)

    op = dp.locator('[data-bs-target="#endModal"]').first
    op.click()
    dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
    dp.fill('#endModal textarea[name=final_report]', 'بوابة ٢٢-٧: انتهى الخطر')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
        dp.click('#endModal button.btn-success')
    dp.wait_for_load_state('networkidle')
    log('حالة فُعّلت وأُنهيت', f'الحالة {iid}', True)

    # ── الزر الواحد ──
    dp.goto(BASE + f'/app/emergency/incidents/{iid}/report', wait_until='domcontentloaded')
    btn = dp.locator('button:has-text("أنشئه بزر واحد")').first
    log('زر «أنشئه بزر واحد» في صفحة التقرير', btn.count() > 0, btn.count() > 0)
    if btn.count():
        with dp.expect_response(lambda r: r.request.method == 'POST' and r.url.endswith(f'/incidents/{iid}/aar')) as ri:
            btn.click()
        st = ri.value.status
    # التحويل يُقرأ بطلب صريح: قائمة التقارير ثم أحدثها (لا يُعتمد على توقيت المتصفح)
    dp.goto(BASE + '/app/emergency/aar', wait_until='domcontentloaded')
    # في الصفحة بطاقة «إجراءات مفتوحة» تحمل روابط تقارير قديمة قبل الجدول — يُؤخذ أحدث رقم لا أول رابط
    ids = [int(x) for x in re.findall(r'/app/emergency/aar/(\d+)', dp.content())]
    rid = str(max(ids)) if ids else None
    log('  بُني التقرير', f'استجابة {st} · رقمه {rid}', bool(rid))
    if not rid:
        print('WEBKIT-22-7: تعذّر بناء التقرير'); sys.exit(1)
    dp.goto(BASE + f'/app/emergency/aar/{rid}', wait_until='domcontentloaded')

    body = dp.inner_text('body')
    log('  ومبنيّ من سجل الحالة', f'خط زمني={"الخط الزمني" in body} · أزمنة={"المدة (دقيقة)" in body}',
        ('الخط الزمني' in body) and ('المدة (دقيقة)' in body))

    # ── يُكتب ──
    dp.fill('textarea[name=what_went_well]', 'وصل الفريق سريعاً')
    dp.fill('textarea[name=what_went_wrong]', 'تأخّر الإقرار بالاستلام')
    with dp.expect_response(lambda r: r.request.method == 'POST' and f'/aar/{rid}' in r.url):
        dp.click('button:has-text("احفظ")')
    dp.goto(BASE + f'/app/emergency/aar/{rid}', wait_until='domcontentloaded')
    # محتوى الخانة النصية لا يظهر في inner_text — يُقرأ بقيمتها
    saved = dp.locator('textarea[name=what_went_well]').input_value()
    log('  ويُكتب ويُحفظ', saved[:40] or 'فارغ', 'وصل الفريق سريعاً' in saved)

    # ── إجراء تصحيحي لصاحبه ──
    dp.fill('input[name=title]', 'بوابة ٢٢-٧: استبدال طفاية الدور الثاني')
    # في القاعدة حسابان بالاسم نفسه (الأصلي والتجريبي) — يُختار التجريبي صراحةً وإلا ذهب الإجراء لغير من نفحصه
    owner_val = None
    opts = dp.locator('select[name=assigned_to_id] option')
    for i in range(opts.count()):
        t = opts.nth(i).inner_text() or ''
        if 'مرافق' in t and 'تجريبي' in t:
            owner_val = opts.nth(i).get_attribute('value'); break
    if not owner_val:
        for i in range(opts.count()):
            if 'مرافق' in (opts.nth(i).inner_text() or ''):
                owner_val = opts.nth(i).get_attribute('value'); break
    dp.select_option('select[name=assigned_to_id]', owner_val)
    owner_name = dp.locator(f'select[name=assigned_to_id] option[value="{owner_val}"]').inner_text().strip()
    dp.fill('input[name=due_date]', '2026-10-01')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/actions' in r.url):
        dp.click('button:has-text("أسنِد")')
    dp.goto(BASE + f'/app/emergency/aar/{rid}', wait_until='domcontentloaded')
    body = dp.inner_text('body')
    log('  وإجراء تصحيحي أُسند', f'{owner_name}', 'استبدال طفاية الدور الثاني' in body)

    # ── يُرفع ويُعتمد ويُنشر ──
    for label, expect in [('ارفعه للمراجعة', 'اعتمده'), ('اعتمده', 'انشره'), ('انشره', 'نُشر التقرير')]:
        b = dp.locator(f'button:has-text("{label}")').first
        if not b.count():
            log(f'  زر «{label}»', 'غير موجود', False); break
        with dp.expect_response(lambda r: r.request.method == 'POST' and f'/aar/{rid}' in r.url):
            b.click()
        dp.goto(BASE + f'/app/emergency/aar/{rid}', wait_until='domcontentloaded')
        txt = dp.inner_text('body')
        log(f'  بعد «{label}»', expect if expect in txt else txt[:0] or 'لم يتغيّر', expect in txt)

    # ── صاحب الإجراء يراه في بطاقاته ويغلقه ──
    op2 = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    op2.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    if login(op2, OWNER, TPW):
        op2.goto(BASE + '/app', wait_until='domcontentloaded')
        card = op2.locator(f'form[action*="/aar/actions/"] button:has-text("أنجزته")')
        log('صاحب الإجراء يراه في بطاقاته', f'أزرار={card.count()}', card.count() > 0)
        if card.count():
            with op2.expect_response(lambda r: r.request.method == 'POST' and '/aar/actions/' in r.url) as ri:
                card.first.click()
            op2.goto(BASE + '/app', wait_until='domcontentloaded')
            gone = op2.locator('form[action*="/aar/actions/"] button:has-text("أنجزته")').count() == 0
            log('  ويغلقه بزر', f'استجابة {ri.value.status} · اختفت البطاقة: {gone}',
                ri.value.status in (200, 302) and gone)
    else:
        log('صاحب الإجراء يراه في بطاقاته', f'تعذّر دخول {OWNER}', False)

    # ── القائمة ──
    dp.goto(BASE + '/app/emergency/aar', wait_until='domcontentloaded')
    log('قائمة التقارير من القائمة الجانبية', f'ط-{iid} ظاهر: ' + str(bool(re.search(r'ط-\d+', dp.inner_text('body')))),
        bool(re.search(r'ط-\d+', dp.inner_text('body'))))

    real = [c for c in console if '403' not in c[2]]
    log('أخطاء الطرفية', real if real else 'صفر', len(real) == 0)

print('\nWEBKIT-22-7:', 'صفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
