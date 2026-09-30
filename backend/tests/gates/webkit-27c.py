# -*- coding: utf-8 -*-
"""بوابة ٢٧-ج (المواعيد) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

التجهيز: seed-27c.php ينشئ موعداً قريباً لكل بطاقة، وهذا السكربت يدخل بصاحب كل بطاقة، يتحقق أنها عنده وليست عند غيره،
يضغط زرها بإصبعه، يؤدي الفعل في الشاشة التي فتحها، ويقرأ الحالة من القاعدة بعده.

  المنسق: تمرين قريب ← يلغيه من شاشة التمارين · معدتا طوارئ في بطاقة واحدة ← يفحص واحدة من شاشة المعدات فتصير بطاقة مفردة ·
           تصريح نشط تقترب نهايته · معدة تصاريح حان فحصها · وثيقة عامل وتدريبه.
  مشرف المقاول (في بوابته): وثيقة طرفه «ارفع الجديدة» ووثيقة عامله — ولا شيء من بطاقات المركز.
  رئيس الأمن والسلامة: زائر فات موعد خروجه ← يسجّل خروجه من شاشة الزوار فتختفي.
  طبيب العيادة: «ملفات طبية لم تُراجع» بعدد لوحته نفسه.
  مسؤول السلامة: وثيقة الطرف «اطلب تجديدها». الموظف: لا شيء.
غير مشمول هنا: «تحقق المقاول انتهى» (يحتاج قناة خارجية مضبوطة) — يحرسها DeadlineCardsTest بقناة مزيّفة.

كلمة الحسابات التجريبية من المتغيّر TRIAL_PW (لا تُكتب هنا).
التشغيل: TRIAL_PW=… PYTHONIOENCODING=utf-8 python webkit-27c.py [BASE]
"""
import sys, os, re, json, subprocess
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
TRIAL_PW = os.environ.get('TRIAL_PW', '')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
errs = []
assert TRIAL_PW, 'TRIAL_PW غير مُمرَّرة'
assert '127.0.0.1' in BASE or 'localhost' in BASE, 'البوابة للمحلي وحده (قرار ٥٧)'


def log(k, v, ok=None):
    mark = '' if ok is None else ('✓ ' if ok else '✗ ')
    print(f'{mark}{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def artisan(*args, env=None):
    r = subprocess.run(['php', 'artisan', *args], cwd=BACKEND, capture_output=True, stdin=subprocess.DEVNULL, env=dict(os.environ, **(env or {})))
    return r.stdout.decode('utf-8', 'replace') + r.stderr.decode('utf-8', 'replace')


def db(expr):
    """قيمة واحدة من القاعدة المحلية — الحالة بعد الفعل"""
    return artisan('tinker', '--execute', f'echo "V:".({expr});').split('V:')[-1].strip()


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
    page.on('dialog', lambda d: d.accept())  # نوافذ «هل أنت متأكد؟» في شاشات التمارين والزوار
    assert login(page, user, pw), f'فشل دخول {user}'
    return ctx, page, console


def path(url):
    return url.replace(BASE, '')


def home(page):
    page.goto(BASE + '/app', wait_until='networkidle')


def has(page, key, prefix=False):
    return page.locator(f'#inboxList [data-task{"^" if prefix else ""}="{key}"]').count()


def card(page, key):
    """البطاقة بمعرّفها؛ تُفتح مجموعتها المطوية، ودفعتها إن كانت بنداً في دفعة"""
    c = page.locator(f'#inboxList [data-task="{key}"]')
    if c.count():
        grp = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')
        if grp.count() and grp.get_attribute('aria-expanded') == 'false':
            grp.click(); page.wait_for_timeout(600)
        batch = c.first.locator('xpath=ancestor::div[@data-batch][1]').locator('.task-actions > button[data-bs-toggle="collapse"]')
        if batch.count() and batch.first.get_attribute('aria-expanded') == 'false':
            batch.first.click(); page.wait_for_timeout(600)
    return c


def press(page, c):
    """ضغطة الزر الأساسي في البطاقة؛ تعيد [الحالة، المسار بعد الضغطة]"""
    form = c.locator('.task-actions form button.btn-g')
    target = form if form.count() else c.locator('.task-actions a.btn-g')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        target.first.click()
    return [nav.value.status, path(page.url)]


def text(c):
    return c.first.locator('.fw-bold').first.inner_text().strip() if c.count() else '—'


seed = artisan('tinker', 'tests/gates/seed-27c.php', env={'GATE27C': 'seed'})
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-600:]
S = json.loads(m.group(1))
log('التجهيز المحلي', S)
NEW = ['drill:', 'eqinspect:', 'visitors:', 'medreview', 'epceq:', 'epdoc:', 'wdoc:', 'wtrain:', 'cverify:']
K = {'drill': f'drill:{S["drill"]}', 'eq1': f'eqinspect:{S["eq_soon"]}', 'eq2': f'eqinspect:{S["eq_late"]}', 'visitors': f'visitors:{S["building"]}',
     'permit': f'permit:{S["permit"]}:expiring', 'epc': f'epceq:{S["epc"]}', 'epdoc': f'epdoc:{S["epdoc"]}:expiry', 'wdoc': f'wdoc:{S["wdoc"]}:expiry', 'wtrain': f'wtrain:{S["wtrain"]}:expiry'}

with sync_playwright() as p:
    browser = p.webkit.launch()

    # ══ المنسق ══
    cctx, cpage, ccon = session(browser, 'tj.comm.c', TRIAL_PW)
    home(cpage)
    have = {k: has(cpage, K[k]) for k in ['drill', 'eq1', 'eq2', 'permit', 'epc', 'wdoc', 'wtrain']}
    log('المنسق: بطاقات المواعيد السبع عنده', have, all(v == 1 for v in have.values()))
    log('المنسق: ليست عنده بطاقة الزوار ولا الملفات الطبية ولا وثيقة الطرف', [has(cpage, K['visitors']), has(cpage, 'medreview'), has(cpage, K['epdoc'])],
        has(cpage, K['visitors']) + has(cpage, 'medreview') + has(cpage, K['epdoc']) == 0)

    # المعدتان في بطاقة واحدة بعددهما، والمتأخرة معلَّمة
    b = cpage.locator('#inboxList [data-batch="equipment.inspect"]')
    log('المنسق: المعدتان في بطاقة واحدة «معدتان في المكاتب: حان فحصها الدوري» وفيها متأخر واحد', [b.count(), b.first.get_attribute('data-n') if b.count() else '—', b.first.get_attribute('data-od') if b.count() else '—',
        b.first.locator('.fw-bold').first.inner_text().strip() if b.count() else '—'], b.count() == 1 and b.first.get_attribute('data-n') == '2' and b.first.get_attribute('data-od') == '1')
    c = card(cpage, K['eq2'])
    cpage.screenshot(path=os.path.join(SHOT, '27c-coord-cards.png'), full_page=True)
    r = press(cpage, c)
    log('المنسق: «افحصها» تفتح معدات المكاتب وفيها المعدتان', [r, cpage.locator('text=G27C-2').count()], r[0] == 200 and r[1].startswith('/app/emergency/equipment?place=HZ-06') and cpage.locator('text=G27C-2').count() >= 1)
    f = cpage.locator(f'form[action$="/equipment/{S["eq_late"]}/inspect"]')
    with cpage.expect_navigation(wait_until='domcontentloaded') as nav:
        f.locator('button').click()
    home(cpage)
    single = cpage.locator('#inboxList [data-batch="equipment.inspect"]').count()
    log('المنسق: سجّل الفحص من الشاشة ← اختفت بطاقتها، وبقيت الأخرى بطاقة مفردة', [nav.value.status, has(cpage, K['eq2']), has(cpage, K['eq1']), single],
        nav.value.status == 200 and has(cpage, K['eq2']) == 0 and has(cpage, K['eq1']) == 1 and single == 0)

    # التمرين: يفتح التمارين ويلغيه هناك
    c = card(cpage, K['drill']); seen = text(c)
    r = press(cpage, c)
    log('المنسق: «تمرين موعده…» يفتح التمارين', [seen, r], r[0] == 200 and r[1].startswith('/app/emergency/drills') and 'موعده' in seen)
    f = cpage.locator(f'form[action$="/drills/{S["drill"]}/cancel"]')
    with cpage.expect_navigation(wait_until='domcontentloaded') as nav:
        f.locator('button').click()
    home(cpage)
    st = db(f'App\\Modules\\Emergency\\Models\\EvacuationDrill::find({S["drill"]})->status')
    log('المنسق: ألغاه من الشاشة ← اختفت البطاقة', [nav.value.status, st, has(cpage, K['drill'])], st == 'cancelled' and has(cpage, K['drill']) == 0)

    # التصريح النشط، معدة التصاريح، وثيقة العامل، تدريبه — كل بطاقة تفتح موضعها
    for k, want, land in [('permit', 'ينتهي', f'/app/permits/{S["permit"]}'), ('epc', 'فحصها الدوري', f'/app/equipment/{S["epc"]}'),
                          ('wdoc', 'تنتهي', f'/app/workers/{S["worker"]}/documents'), ('wtrain', 'ينتهي', f'/app/workers/{S["worker"]}')]:
        home(cpage)
        c = card(cpage, K[k]); n, seen = c.count(), text(c)
        r = press(cpage, c) if n else ['—', '—']
        log(f'المنسق: {k} ← تفتح موضعها', [seen, r], n == 1 and want in seen and r[0] == 200 and r[1].startswith(land))
    log('المنسق: أخطاء', ccon or 'صفر', not ccon)
    cctx.close()

    # ══ مشرف المقاول: في بوابته ══
    sctx, spage, scon = session(browser, 'tj.mushrif', TRIAL_PW)
    home(spage)
    c = card(spage, K['epdoc']); seen = text(c)
    others = sum(has(spage, K[k]) for k in ['drill', 'eq1', 'visitors', 'permit', 'epc', 'wtrain']) + has(spage, 'medreview')
    log('مشرف المقاول: «وثيقة لطرفكم تنتهي — ارفع الجديدة» ووثيقة عامله عنده في بوابته، ولا بطاقة لغيره', [path(spage.url), seen, has(spage, K['wdoc']), others],
        spage.url.endswith('/app/contractor') and c.count() == 1 and 'ارفع الجديدة' in seen and has(spage, K['wdoc']) == 1 and others == 0)
    spage.screenshot(path=os.path.join(SHOT, '27c-supervisor-portal.png'))
    r = press(spage, c)
    log('مشرف المقاول: الزر يفتح وثائق طرفه', r, r[0] == 200 and r[1].startswith(f'/app/external-parties/{S["party"]}/documents'))
    log('مشرف المقاول: أخطاء', scon or 'صفر', not scon)
    sctx.close()

    # ══ رئيس الأمن والسلامة: الزائر ══
    nctx, npage, ncon = session(browser, 'amn', '1234')
    home(npage)
    c = card(npage, K['visitors']); seen = text(c)
    log('رئيس الأمن والسلامة: «زوار فات موعد خروجهم: 1» عنده', seen, c.count() == 1 and ': 1' in seen)
    npage.screenshot(path=os.path.join(SHOT, '27c-amn-visitors.png'))
    r = press(npage, c)
    btn = npage.locator(f'button[onclick="checkOutVisitor({S["visitor"]})"]')
    log('رئيس الأمن والسلامة: الزر يفتح زوار المبنى وفيها الزائر بزر «خروج»', [r, btn.count()], r[0] == 200 and r[1].startswith(f'/app/emergency/visitors/{S["building"]}') and btn.count() >= 1)
    with npage.expect_response(lambda x: f'/visitors/{S["visitor"]}/check-out' in x.url) as resp:
        btn.first.click()
    npage.wait_for_timeout(1500)
    home(npage)
    out = db(f'App\\Modules\\Emergency\\Models\\EmergencyVisitor::find({S["visitor"]})->status')
    log('رئيس الأمن والسلامة: ضغطة «خروج» ← سُجّل خروجه واختفت البطاقة', [resp.value.status, out, has(npage, K['visitors'])], resp.value.status == 200 and out == 'checked_out' and has(npage, K['visitors']) == 0)
    log('رئيس الأمن والسلامة: أخطاء', ncon or 'صفر', not ncon)
    nctx.close()

    # ══ طبيب العيادة ══
    dctx, dpage, dcon = session(browser, 'tj.tabib', TRIAL_PW)
    home(dpage)
    need = db('app(App\\Modules\\Emergency\\Services\\MedicalProfileService::class)->getProfilesNeedingReview()->count()')
    c = card(dpage, 'medreview'); seen = text(c)
    log('طبيب العيادة: «ملفات طبية لم تُراجع: N» بعدد لوحته نفسه', [seen, need], c.count() == 1 and need != '0' and seen.find(': ' + need + ' ') > 0)
    dpage.screenshot(path=os.path.join(SHOT, '27c-doctor.png'))
    r = press(dpage, c)
    log('طبيب العيادة: الزر يفتح لوحة الملفات الطبية', r, r[0] == 200 and r[1].startswith('/app/emergency/medical'))
    log('طبيب العيادة: أخطاء', dcon or 'صفر', not dcon)
    dctx.close()

    # ══ مسؤول السلامة والموظف ══
    actx, apage, acon = session(browser, 'salama', '1234')
    home(apage)
    c = card(apage, K['epdoc']); seen = text(c)
    log('مسؤول السلامة: «وثيقة من الطرف تنتهي — اطلب تجديدها» عنده، وبطاقة الزوار اختفت عنه', [seen, has(apage, K['visitors']), has(apage, 'medreview')],
        c.count() == 1 and 'اطلب تجديدها' in seen and has(apage, K['visitors']) == 0 and has(apage, 'medreview') == 0)
    log('مسؤول السلامة: أخطاء', acon or 'صفر', not acon)
    actx.close()

    ectx, epage, econ = session(browser, 'emp', '1234')
    home(epage)
    n = sum(has(epage, k, True) for k in NEW) + has(epage, K['permit'])
    log('الموظف: لا بطاقة موعد عنده', n, n == 0)
    log('الموظف: أخطاء', econ or 'صفر', not econ)
    ectx.close()
    browser.close()

clean = artisan('tinker', 'tests/gates/seed-27c.php', env={'GATE27C': 'clean'})
left = db('App\\Modules\\Emergency\\Models\\EmergencyEquipment::where("code","like","G27C-%")->count() + Illuminate\\Support\\Facades\\DB::table("permits")->where("title","like","%بوابة ٢٧-ج%")->count() + App\\Modules\\Project\\Models\\ExternalParty::where("name","like","%بوابة ٢٧-ج%")->count() + App\\Modules\\Emergency\\Models\\EvacuationDrill::where("scenario","like","%بوابة ٢٧-ج%")->count()')
log('التنظيف: لم يبقَ من التجهيز شيء', [clean.strip()[-40:], left], left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٧-ج خضراء'))
sys.exit(1 if errs else 0)
