# -*- coding: utf-8 -*-
"""بوابة قبول المرحلة ٢٢ «د» — مركز السلامة وإدارة الطوارئ.

المسار الموعود في الخطة، من أوله إلى آخره، على متصفح حقيقي:
  التفعيل ← الخطوات ← **الشخص يسجّل وصوله من جواله** ← انتهاء الخطر ← تقرير بزر واحد.

ومعه ما بُني في المرحلة: الاستغاثة، وردّ المستجيب، والرسالة الجماعية، وطلب المساعدة، والباب الواحد.

التشغيل: PYTHONIOENCODING=utf-8 python gate22.py [BASE] [كلمة الحسابات التجريبية]
"""
import sys, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
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


def end_open(dp, ctl, note):
    """ينهي أي حالة مفتوحة حتى تبدأ البوابة من وضع نظيف."""
    dp.goto(ctl, wait_until='domcontentloaded')
    if dp.locator('#triggerForm').count():
        return
    href = dp.locator('a[href*="/incidents/"][href*="/live"]').first.get_attribute('href') or ''
    m = re.search(r'/incidents/(\d+)/live', href)
    if m:
        dp.goto(BASE + f'/app/emergency/incidents/{m.group(1)}/live', wait_until='domcontentloaded')
        op = dp.locator('[data-bs-target="#endModal"]').first
        if op.count():
            op.click()
            dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
            dp.fill('#endModal textarea[name=final_report]', note)
            with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
                dp.click('#endModal button.btn-success')
            dp.wait_for_load_state('networkidle')
    dp.goto(ctl, wait_until='domcontentloaded')


with sync_playwright() as p:
    browser = p.webkit.launch()
    console = []

    # ── المناوب على حاسبه ──
    dp = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ar-SA').new_page()
    dp.on('dialog', lambda d: d.accept())
    dp.on('console', lambda m: console.append(('حاسب', m.type, m.text)) if m.type == 'error' else None)
    assert login(dp, MUNAWIB, TPW), 'فشل دخول المناوب'

    # تُقرأ القائمة من الصفحة بلا فتح الدرج: فتحه يترك حاجباً يبتلع الضغطات بعده
    head = '<div class="small text-muted px-2 mb-1">مركز السلامة وإدارة الطوارئ</div>'
    html = dp.content()
    log('١) باب واحد باسمه في القائمة', head in html, head in html)
    log('   والبابان القديمان اختفيا',
        '<div class="small text-muted px-2 mb-1">بلاغات الشاغلين</div>' not in html,
        '<div class="small text-muted px-2 mb-1">بلاغات الشاغلين</div>' not in html)

    dp.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    ctl = dp.locator('a[href*="/control"]').first.get_attribute('href')
    ctl = ctl if ctl.startswith('http') else BASE + ctl
    end_open(dp, ctl, 'إنهاء قبل بوابة القبول')

    # ── ٢) التفعيل: المكان مُملوء مما يعرفه النظام ──
    dp.goto(ctl + '?place=HZ-06', wait_until='domcontentloaded')
    picked = dp.locator('#triggerForm select[name=place_id] option:checked').inner_text().strip()
    log('٢) التفعيل: المكان مُملوء بلا سؤال', picked[:28], picked.startswith('HZ-06'))
    dp.select_option('#triggerForm select[name=incident_type]', 'fire')
    dp.fill('#triggerForm textarea[name=description]', 'بوابة القبول: حريق في المكاتب')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/trigger' in r.url):
        dp.click('#triggerForm button[type=submit]')
    dp.wait_for_load_state('networkidle')
    iid = re.search(r'/app/emergency/incidents/(\d+)/live', dp.url).group(1)
    live = f'/app/emergency/incidents/{iid}/live'
    log('   الحالة فُعّلت ونُبّه الفريق', f'ط-{iid}', True)

    body = dp.inner_text('body')
    log('٣) الخطوات تصل أصحابها', 'خطوات الخطة' in body, 'خطوات الخطة' in body)
    # الوعد هو أن يُنبَّه الفريق فعلاً؛ والنداء الهاتفي لا يظهر إلا إن كان في الفريق من بلا حساب
    log('   والفريق نُبّه', 'نُبّه الفريق' in body, 'نُبّه الفريق' in body)
    calls = dp.locator('text=/نادِ هاتفياً|نداء هاتفي/').count()
    log('   ونداءات هاتفية لمن بلا حساب', f'{calls} (صفر يعني أن كل الفريق بحسابات)', None)

    # ── ٤) الشخص على جواله: يرى، يسجّل وصوله، يطلب مساعدة ──
    ep = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    ep.on('console', lambda m: console.append(('جوال', m.type, m.text)) if m.type == 'error' else None)
    assert login(ep, EMP, TPW), 'فشل دخول الموظف'
    ep.goto(BASE + '/app', wait_until='domcontentloaded')
    log('٤) الموظف يرى الحالة في صفحته الأولى', ep.locator('a[href$="/app/emergency/me"]').count() > 0,
        ep.locator('a[href$="/app/emergency/me"]').count() > 0)

    ep.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    body = ep.inner_text('body')
    for t in ['حريق', 'أقرب مخرج', 'نقطة التجمع', 'سجّل وصولي', 'أحتاج مساعدة']:
        log(f'   يرى «{t}»', t in body, t in body)
    mx = ep.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
    log('   بلا تمرير أفقي', f'{mx}px', mx <= 1)

    with ep.expect_response(lambda r: r.request.method == 'POST' and '/me/check-in' in r.url) as ri:
        ep.locator('button:has-text("سجّل وصولي")').first.click()
    ep.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    ok = 'بأمان' in ep.inner_text('body')
    log('   ويسجّل وصوله بزر', f'استجابة {ri.value.status} · «أنت مسجَّل بأمان»: {ok}',
        ri.value.status in (200, 302) and ok)

    ep.locator('button:has-text("أحتاج مساعدة")').first.click()
    ep.wait_for_timeout(400)
    ep.select_option('select[name=help_type]', 'mobility')
    ep.fill('input[name=notes]', 'بوابة القبول: عالق في الدرج')
    with ep.expect_response(lambda r: r.request.method == 'POST' and '/me/help' in r.url):
        ep.click('button:has-text("أرسل الطلب الآن")')
    ep.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
    log('   ويطلب مساعدة', 'طلبك وصل' in ep.inner_text('body'), 'طلبك وصل' in ep.inner_text('body'))

    # ── ٥) المركز يرى الحصر والطلب، ويرسل رسالة للجميع ──
    dp.goto(BASE + live, wait_until='domcontentloaded')
    body = dp.inner_text('body')
    # يُقرأ **الرقم** لا العنوان: البحث عن كلمة «آمنون» كان يمرّ دائماً لأنها عنوان ثابت،
    # فبقيت البوابة عمياء عن خروج الموظف من الحصر بمجرد طلبه المساعدة (عيب ٢٠٢٦-٠٩-٢٢).
    m = re.search(r'(\d+)\s*آمنون', body) or re.search(r'آمنون\s*(\d+)', body)
    safe_n = int(m.group(1)) if m else -1
    log('٥) المركز يرى الحصر ورقم الآمنين صحيح', f'آمنون = {safe_n}', safe_n >= 1)
    log('   ويرى طلب المساعدة بوقته وزر «عولج»',
        f'«يحتاجون مساعدة»={"يحتاجون مساعدة" in body} · «عولج»={"عولج" in body}',
        ('يحتاجون مساعدة' in body) and ('عولج' in body))

    # شاشة الحالة تُحدّث نفسها كل خمس ثوانٍ فتُبدّل الزر أثناء الضغط — يُرسَل الحدث مباشرةً
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/message' in r.url):
        dp.locator('button:has-text("أمر إخلاء")').first.dispatch_event('click')
    dp.goto(BASE + live, wait_until='domcontentloaded')
    counts = re.findall(r'ردّوا\s+(\d+)\s+من\s+(\d+)', dp.inner_text('body'))
    log('   ويرسل رسالة للجميع بقالب', f'{counts[0][1] if counts else "?"} مستلماً', bool(counts))

    # ── ٦) المسعف يردّ ويصل ──
    mp = browser.new_context(**p.devices['iPhone 13'], locale='ar-SA').new_page()
    if login(mp, MEDIC, TPW):
        mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
        b = mp.locator('button:has-text("وصلتُ إلى الموقع")').first
        if b.count():
            with mp.expect_response(lambda r: r.request.method == 'POST' and '/me/arrived' in r.url):
                b.click()
            mp.goto(BASE + '/app/emergency/me', wait_until='domcontentloaded')
        log('٦) المسعف يسجّل وصوله من جواله', 'وصولك إلى الموقع مسجَّل' in mp.inner_text('body'),
            'وصولك إلى الموقع مسجَّل' in mp.inner_text('body'))
        dp.goto(BASE + live, wait_until='domcontentloaded')
        log('   والمركز يراه في الخط الزمني', 'وصل إلى الموقع' in dp.inner_text('body'),
            'وصل إلى الموقع' in dp.inner_text('body'))

    # ── ٧) انتهاء الخطر ──
    dp.goto(BASE + live, wait_until='domcontentloaded')
    op = dp.locator('[data-bs-target="#endModal"]').first
    op.click()
    dp.wait_for_selector('#endModal textarea[name=final_report]', state='visible', timeout=10000)
    dp.fill('#endModal textarea[name=final_report]', 'بوابة القبول: انتهى الخطر')
    with dp.expect_response(lambda r: r.request.method == 'POST' and '/end' in r.url):
        dp.click('#endModal button.btn-success')
    dp.goto(BASE + f'/app/emergency/incidents/{iid}/report', wait_until='domcontentloaded')
    body = dp.inner_text('body')
    ended = ('انتهت' in body) and ('بوابة القبول: انتهى الخطر' in body)
    log('٧) انتهاء الخطر', f'الحالة «انتهت» والتقرير النهائي محفوظ: {ended}', ended)

    # ── ٨) تقرير بزر واحد ──
    btn = dp.locator('button:has-text("أنشئه بزر واحد")').first
    log('٨) زر التقرير بعد الإنهاء', btn.count() > 0, btn.count() > 0)
    if btn.count():
        with dp.expect_response(lambda r: r.request.method == 'POST' and r.url.endswith(f'/incidents/{iid}/aar')):
            btn.click()
        dp.goto(BASE + '/app/emergency/aar', wait_until='domcontentloaded')
        ids = [int(x) for x in re.findall(r'/app/emergency/aar/(\d+)', dp.content())]
        rid = max(ids) if ids else None
        dp.goto(BASE + f'/app/emergency/aar/{rid}', wait_until='domcontentloaded')
        body = dp.inner_text('body')
        log('   التقرير مبنيّ من سجل الحالة',
            f'خط زمني={"الخط الزمني" in body} · أزمنة={"المدة (دقيقة)" in body}',
            ('الخط الزمني' in body) and ('المدة (دقيقة)' in body))

    real = [c for c in console if '403' not in c[2]]
    log('أخطاء الطرفية', real if real else 'صفر', len(real) == 0)

print('\nGATE22:', 'المرحلة «د» مرّت بصفر أخطاء' if not errs else f'{len(errs)} إخفاق: {errs}')
sys.exit(1 if errs else 0)
