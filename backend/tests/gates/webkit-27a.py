# -*- coding: utf-8 -*-
"""بوابة ٢٧-أ (قرار ٦٧) على WebKit iPhone 13 — القاعدة المحلية في وضع التجربة: حالة حقيقية تُفعَّل، فتصل بطاقاتها أصحابها، وضغطة تُنهي كل بطاقة.

المسار: المناوب يفعّل حريقاً في المكاتب ← «لم يستلمها أحد» تصل المناوب ومسعف الفريق الأولي (أضعف صاحب بطاقة) ولا تصل الموظف ←
المسعف يضغط «استلمتُها» فتختفي عن الجميع ← قائد فريق الطوارئ يضغط «سُيطر عليها» ثم «أنهِها» تفتح شاشة الحالة ونافذة الإنهاء ←
المناوب يرى «لم يسجّل وصوله» وتفتح الحصر ← تُنهى الحالة ← «اكتبه» ثم يُرفع التقرير للمراجعة ← مسؤول السلامة «اعتمده» ثم «انشره».

كلمة الحسابات التجريبية من المتغيّر TRIAL_PW (لا تُكتب هنا).
التشغيل: TRIAL_PW=… PYTHONIOENCODING=utf-8 python webkit-27a.py [BASE]
"""
import sys, os, re
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
TRIAL_PW = os.environ.get('TRIAL_PW', '')
errs = []


def log(k, v, ok=None):
    mark = '' if ok is None else ('✓ ' if ok else '✗ ')
    print(f'{mark}{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def login(page, user, pw, tries=4):
    for i in range(tries):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user); page.fill('input[name=password]', pw)
        page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
        if '/login' not in page.url: return True
        page.wait_for_timeout(20000)
    return False


def session(browser, user, pw):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    assert login(page, user, pw), f'فشل دخول {user}'
    return ctx, page, console


def path(url):
    return url.replace(BASE, '')


def home(page):
    page.goto(BASE + '/app', wait_until='networkidle')


def card(page, key):
    return page.locator(f'#inboxList [data-task="{key}"]')


def open_emergency_group(page):
    sec = '#inboxList section[data-module="الطوارئ"]'
    if page.locator(sec).count() == 0: return False
    page.click(sec + ' > button.grp-h'); page.wait_for_selector(sec + ' > .collapse.show', state='visible'); page.wait_for_timeout(400)
    return True


def post(page, url, data=None):
    """طلب POST بجلسة الصفحة ورمز CSRF — لما لا زر له في البطاقة (تجهيز المسار)"""
    token = page.locator('meta[name=csrf-token]').get_attribute('content')
    return page.request.post(url if url.startswith('http') else BASE + url, form=dict(data or {}, _token=token), max_redirects=0)


with sync_playwright() as p:
    browser = p.webkit.launch()

    # ── المناوب يفعّل حالة في المكاتب ──
    mctx, mpage, mcon = session(browser, 'munawib', '1234')
    home(mpage)
    place_id = re.search(r'/app/places/(\d+)/file', mpage.locator('#places a[data-place="HZ-06"]').get_attribute('href')).group(1)
    mpage.goto(BASE + '/app/emergency', wait_until='domcontentloaded')
    control = mpage.locator('a[data-door="فعّل حالة طارئة"]').get_attribute('href')
    building = re.search(r'/buildings/(\d+)/control', control).group(1)
    r = post(mpage, f'/app/emergency/buildings/{building}/trigger', {'incident_type': 'fire', 'severity': 'high', 'place_id': place_id, 'description': 'بوابة ٢٧-أ'})
    loc = r.headers.get('location', '')
    inc = re.search(r'/incidents/(\d+)', loc).group(1) if re.search(r'/incidents/(\d+)', loc) else None
    log('munawib: فُعّلت حالة في المكاتب', [r.status, path(loc)], r.status in (302, 303) and inc is not None)
    assert inc, 'لم تُفعَّل الحالة'

    home(mpage); open_emergency_group(mpage)
    have = {k: card(mpage, f'{k}:{inc}').count() for k in ['eack', 'ectl', 'emuster']}
    log('munawib: بطاقات الحالة الثلاث عنده (لم تُستلم، سُيطر عليها؟، لم يسجّل وصوله)', have, all(v == 1 for v in have.values()))
    log('munawib: نص «لم يستلمها أحد»', card(mpage, f'eack:{inc}').locator('.fw-bold').inner_text().strip(), 'لم يستلمها أحد' in card(mpage, f'eack:{inc}').inner_text())
    mpage.screenshot(path=os.path.join(SHOT, '27a-munawib-cards.png'), full_page=True)

    # ── الموظف: ليست بطاقاته ──
    ectx, epage, econ = session(browser, 'emp', '1234')
    home(epage)
    n = sum(card(epage, f'{k}:{inc}').count() for k in ['eack', 'ectl', 'emuster'])
    log('emp: لا بطاقة قيادة عنده، وشريط «حالة طارئة — ماذا أفعل» ظاهر', [n, epage.locator('#myEmergencyBanner').count()], n == 0 and epage.locator('#myEmergencyBanner').count() == 1)
    log('emp: أخطاء', econ or 'صفر', not econ)
    ectx.close()

    # ── مسعف الفريق الأولي (أضعف صاحب بطاقة): يستلمها بضغطة ──
    if TRIAL_PW:
        dctx, dpage, dcon = session(browser, 'tj.hz06.medic', TRIAL_PW)
        home(dpage); open_emergency_group(dpage)
        c = card(dpage, f'eack:{inc}')
        log('medic: بطاقة «لم يستلمها أحد» عنده، ولا بطاقة سيطرة', [c.count(), card(dpage, f'ectl:{inc}').count()], c.count() == 1 and card(dpage, f'ectl:{inc}').count() == 0)
        dpage.screenshot(path=os.path.join(SHOT, '27a-medic-card.png'))
        with dpage.expect_navigation(wait_until='domcontentloaded') as nav:
            c.locator('form button.btn-g').click()
        home(dpage)
        log('medic: ضغطة «استلمتُها» ← البطاقة اختفت عنده', [nav.value.status, card(dpage, f'eack:{inc}').count()], nav.value.status == 200 and card(dpage, f'eack:{inc}').count() == 0)
        log('medic: أخطاء', dcon or 'صفر', not dcon)
        dctx.close()
        home(mpage)
        log('munawib: واختفت عنه أيضاً', card(mpage, f'eack:{inc}').count(), card(mpage, f'eack:{inc}').count() == 0)
    else:
        log('medic: لم يُجرَّب — TRIAL_PW غير مُمرَّرة', '—', False)

    # ── قائد فريق الطوارئ: سُيطر عليها ثم أنهِها ──
    cctx, cpage, ccon = session(browser, 'shuon', '1234')
    home(cpage); open_emergency_group(cpage)
    c = card(cpage, f'ectl:{inc}')
    log('shuon: بطاقة «سُيطر عليها؟» عنده، ولا بطاقة حصر (للمركز)', [c.count(), card(cpage, f'emuster:{inc}').count()], c.count() == 1 and card(cpage, f'emuster:{inc}').count() == 0)
    with cpage.expect_navigation(wait_until='domcontentloaded') as nav:
        c.locator('form button.btn-g').click()
    home(cpage); open_emergency_group(cpage)
    c = card(cpage, f'ectl:{inc}')
    txt = c.inner_text() if c.count() else ''
    log('shuon: ضغطة «سُيطر عليها» ← البطاقة صارت «أنهِها»', [nav.value.status, txt.strip()[:70]], nav.value.status == 200 and 'أنهِها' in txt)
    cpage.screenshot(path=os.path.join(SHOT, '27a-commander-end.png'))
    with cpage.expect_navigation(wait_until='domcontentloaded') as nav:
        c.locator('a.btn-g').click()
    cpage.wait_for_timeout(1200)
    shown = cpage.locator('#endModal.show').count()
    log('shuon: «أنهِها» تفتح شاشة الحالة ونافذة الإنهاء معاً', [nav.value.status, path(cpage.url), shown], nav.value.status == 200 and f'/incidents/{inc}/live' in cpage.url and shown == 1)
    cpage.screenshot(path=os.path.join(SHOT, '27a-end-modal.png'))
    log('shuon: أخطاء', ccon or 'صفر', not ccon)
    cctx.close()

    # ── المناوب: من لم يسجّل وصوله ──
    home(mpage); open_emergency_group(mpage)
    c = card(mpage, f'emuster:{inc}')
    log('munawib: بطاقة الحصر بعددها', c.locator('.fw-bold').inner_text().strip() if c.count() else '—', c.count() == 1 and 'لم يسجّل وصوله' in c.inner_text())
    with mpage.expect_navigation(wait_until='domcontentloaded') as nav:
        c.locator('a.btn-g').click()
    log('munawib: تفتح شاشة الحالة على الحصر', [nav.value.status, path(mpage.url)], nav.value.status == 200 and mpage.url.endswith(f'/incidents/{inc}/live#muster') and mpage.locator('#muster').count() == 1)

    # ── الإنهاء فالتقرير فالاعتماد والنشر ──
    r = post(mpage, f'/app/emergency/incidents/{inc}/end', {'final_report': 'انتهت — بوابة ٢٧-أ'})
    home(mpage)
    gone = sum(card(mpage, f'{k}:{inc}').count() for k in ['eack', 'ectl', 'emuster'])
    log('munawib: بعد الإنهاء اختفت بطاقات الحالة', [r.status, gone], r.status in (302, 303) and gone == 0)
    r = post(mpage, f'/app/emergency/incidents/{inc}/aar')
    rep = re.search(r'/aar/(\d+)', r.headers.get('location', ''))
    log('munawib: كُتب تقرير ما بعد الحادث', [r.status, path(r.headers.get('location', ''))], rep is not None)
    if rep:
        rid = rep.group(1)
        r = post(mpage, f'/app/emergency/aar/{rid}/submit')
        sctx, spage, scon = session(browser, 'salama', '1234')
        home(spage); open_emergency_group(spage)
        c = card(spage, f'aarreview:{rid}')
        log('salama: «رُفع للمراجعة — اعتمده» عنده', c.locator('.fw-bold').inner_text().strip() if c.count() else '—', c.count() == 1)
        with spage.expect_navigation(wait_until='domcontentloaded') as nav:
            c.locator('form button.btn-g').click()
        home(spage); open_emergency_group(spage)
        c2 = card(spage, f'aarpublish:{rid}')
        log('salama: ضغطة «اعتمده» ← صارت «انشره»', [nav.value.status, card(spage, f'aarreview:{rid}').count(), c2.count()], nav.value.status == 200 and card(spage, f'aarreview:{rid}').count() == 0 and c2.count() == 1)
        with spage.expect_navigation(wait_until='domcontentloaded') as nav:
            c2.locator('form button.btn-g').click()
        home(spage)
        log('salama: ضغطة «انشره» ← اختفت', [nav.value.status, card(spage, f'aarpublish:{rid}').count()], nav.value.status == 200 and card(spage, f'aarpublish:{rid}').count() == 0)
        log('salama: أخطاء', scon or 'صفر', not scon)
        sctx.close()
    log('munawib: أخطاء المتصفح/الخادم', mcon or 'صفر', not mcon)
    mctx.close()
    browser.close()

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٧-أ خضراء'))
sys.exit(1 if errs else 0)
