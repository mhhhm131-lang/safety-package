# -*- coding: utf-8 -*-
"""بوابة ٢٧-ب وقرار ٦٩ على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

التجهيز: seed-27b.php ينشئ حالة لكل بطاقة موسومة «بوابة ٢٧-ب»، وهذا السكربت يدخل بأضعف صاحب لكل بطاقة، يتحقق أنها عنده
وليست عند غيره، يضغط زرها بإصبعه، ويقرأ الحالة من القاعدة بعد الضغطة (لا من وعد الشاشة).

  المخاطر (قرار ٦٩): المنسق يقدّم ← مدير الإدارة وحده يعتمد (لا مسؤول السلامة ولا المناوب ولا الإدارة العليا ولا اللجنة) ←
                      المرفوض يعود لكاتبه بسببه ← «أعده للتعديل»؛ والسجل العام يعتمده مسؤول السلامة وحده.
  التصاريح: مشرف المقاول يقدّم مسودته ويرى بنوده الناقصة؛ من يفعّل يرى «فعّله» بالعدد والانحراف والتقييم؛ المعتمد بشرط عند مسؤول السلامة.
  الحسابات والاستعداد: إدارة بلا منسق ← مديرها؛ حساب مُعاد ← من سجّله؛ فريق فعالية ← رئيس الأمن والسلامة؛ نموذج فات موعده ← مرسله.
  المقاولون: عامل في التعريف، طرف قيد التسجيل، تقييم بعد مشروع اكتمل.
  الموظف: لا شيء من هذا عنده.
غير مشمول هنا: بطاقة «أماكن بلا خطة استجابة» — المحلي خططه الثماني في النظام؛ يحرسها ReadinessCardsTest.

كلمة الحسابات التجريبية من المتغيّر TRIAL_PW (لا تُكتب هنا).
التشغيل: TRIAL_PW=… PYTHONIOENCODING=utf-8 python webkit-27b.py [BASE]
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
    """قيمة واحدة من القاعدة المحلية — الحالة بعد الضغطة"""
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
    assert login(page, user, pw), f'فشل دخول {user}'
    return ctx, page, console


def path(url):
    return url.replace(BASE, '')


def home(page):
    page.goto(BASE + '/app', wait_until='networkidle')


def card(page, key, prefix=False):
    """البطاقة بمعرّفها؛ إن وُجدت تُفتح مجموعتها المطوية"""
    c = page.locator(f'#inboxList [data-task{"^" if prefix else ""}="{key}"]')
    if c.count():
        btn = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')  # بوابة المقاول: قائمة بلا مجموعات
        if btn.count() and btn.get_attribute('aria-expanded') == 'false':
            btn.click(); page.wait_for_timeout(600)
    return c


def has(page, key, prefix=False):
    return page.locator(f'#inboxList [data-task{"^" if prefix else ""}="{key}"]').count()


def press(page, c):
    """ضغطة الزر الأساسي في البطاقة: نموذج POST أو رابط؛ تعيد [الحالة، المسار بعد الضغطة]"""
    form = c.locator('.task-actions form button.btn-g')
    target = form if form.count() else c.locator('.task-actions a.btn-g')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        target.first.click()
    return [nav.value.status, path(page.url)]


def post(page, url, data=None):
    """طلب POST بجلسة الصفحة ورمز CSRF — لما ليس زراً في البطاقة (تجهيز المسار)"""
    token = page.locator('meta[name=csrf-token]').get_attribute('content')
    return page.request.post(url if url.startswith('http') else BASE + url, form=dict(data or {}, _token=token), max_redirects=0)


def text(c):
    return c.first.locator('.fw-bold').first.inner_text().strip() if c.count() else '—'


seed = artisan('tinker', 'tests/gates/seed-27b.php', env={'GATE27B': 'seed'})
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-600:]
S = json.loads(m.group(1))
log('التجهيز المحلي', S)
NEW = ['permit:', 'risk:', 'account:', 'formremind:', 'worker:', 'party:', 'eventteam:', 'emplans', 'coordgap:']

with sync_playwright() as p:
    browser = p.webkit.launch()
    rd, rr, rg = S['risk_draft'], S['risk_toreject'], S['risk_general']

    # ══ المخاطر — قرار ٦٩ ══
    # المنسق (أضعف من يرفع): مسودتاه عنده ← يقدّمهما
    cctx, cpage, ccon = session(browser, 'tj.comm.c', TRIAL_PW)
    home(cpage)
    c = card(cpage, f'risk:{rd}:draft')
    log('المنسق: «مسودة لم تُقدَّم — قدّمه» عنده', text(c), c.count() == 1 and 'قدّمه' in c.inner_text())
    cpage.screenshot(path=os.path.join(SHOT, '27b-coord-risk-draft.png'))
    r = press(cpage, c); home(cpage)
    log('المنسق: ضغطة «قدّمه» ← اختفت والخطر «بانتظار الاعتماد»', [r, has(cpage, f'risk:{rd}:draft'), db(f'App\\Modules\\Risk\\Models\\Risk::find({rd})->status')],
        r[0] == 200 and has(cpage, f'risk:{rd}:draft') == 0 and db(f'App\\Modules\\Risk\\Models\\Risk::find({rd})->status') == 'pending_approval')
    r = press(cpage, card(cpage, f'risk:{rr}:draft')); home(cpage)
    log('المنسق: لا بطاقة اعتماد عنده (يرفع ولا يعتمد)', [has(cpage, f'risk:{rd}:approve'), has(cpage, f'risk:{rr}:approve')], has(cpage, f'risk:{rd}:approve') + has(cpage, f'risk:{rr}:approve') == 0)
    r = post(cpage, f'/app/risk/{rd}/approve')
    log('المنسق: الاعتماد مرفوض له في الخادم', r.status, r.status == 403)

    # من يرى ولا يعتمد: مسؤول السلامة (خطر إدارة)، المناوب، الإدارة العليا، اللجنة
    for user, pw, who in [('salama', '1234', 'مسؤول السلامة'), ('munawib', '1234', 'المناوب'), ('idara', '1234', 'الإدارة العليا'), ('tj.lajna', TRIAL_PW, 'لجنة السلامة')]:
        xctx, xpage, xcon = session(browser, user, pw)
        home(xpage)
        n = has(xpage, f'risk:{rd}:approve') + has(xpage, f'risk:{rr}:approve')
        r = post(xpage, f'/app/risk/{rd}/approve')
        seen = xpage.request.get(BASE + f'/app/risk/{rd}/detail').status
        log(f'{who}: لا بطاقة اعتماد لخطر الإدارة، والاعتماد مرفوض، والخطر يُرى', [n, r.status, seen], n == 0 and r.status == 403 and seen == 200)
        if user == 'salama':
            g = card(xpage, f'risk:{rg}:approve')
            log('مسؤول السلامة: خطر السجل العام ينتظر اعتماده هو', text(g), g.count() == 1)
            xpage.screenshot(path=os.path.join(SHOT, '27b-salama-general-risk.png'))
            r = press(xpage, g); home(xpage)
            log('مسؤول السلامة: ضغطة «اعتمد» ← اختفت والخطر معتمد', [r, has(xpage, f'risk:{rg}:approve'), db(f'App\\Modules\\Risk\\Models\\Risk::find({rg})->status')],
                r[0] == 200 and has(xpage, f'risk:{rg}:approve') == 0 and db(f'App\\Modules\\Risk\\Models\\Risk::find({rg})->status') == 'approved')
        elif user == 'munawib':
            r = post(xpage, f'/app/risk/{rg}/approve')
            log('المناوب: لا يعتمد السجل العام', [has(xpage, f'risk:{rg}:approve'), r.status], has(xpage, f'risk:{rg}:approve') == 0 and r.status == 403)
        log(f'{who}: أخطاء', xcon or 'صفر', not xcon)
        xctx.close()

    # مدير الإدارة: يعتمد خطر إدارته بضغطة، ويرفض الآخر بسبب
    mctx, mpage, mcon = session(browser, 'tj.comm.m', TRIAL_PW)
    home(mpage)
    c = card(mpage, f'risk:{rd}:approve')
    log('مدير الإدارة: «ينتظر اعتمادك» عنده، وخطر السجل العام ليس عنده', [text(c), has(mpage, f'risk:{rg}:approve')], c.count() == 1 and has(mpage, f'risk:{rg}:approve') == 0)
    mpage.screenshot(path=os.path.join(SHOT, '27b-manager-risk-approve.png'))
    q = mpage.request.get(BASE + '/app/risk/approval/queue')
    log('مدير الإدارة: طابور الاعتماد يفتح وفيه خطرا إدارته وحدهما', [q.status, 'انزلاق عند مدخل الإدارة' in q.text(), 'انسكاب وقود المولد' in q.text()],
        q.status == 200 and 'انزلاق عند مدخل الإدارة' in q.text() and 'انسكاب وقود المولد' not in q.text())
    r = press(mpage, c); home(mpage)
    log('مدير الإدارة: ضغطة «اعتمد» ← اختفت والخطر معتمد', [r, has(mpage, f'risk:{rd}:approve'), db(f'App\\Modules\\Risk\\Models\\Risk::find({rd})->status')],
        r[0] == 200 and has(mpage, f'risk:{rd}:approve') == 0 and db(f'App\\Modules\\Risk\\Models\\Risk::find({rd})->status') == 'approved')
    r = post(mpage, f'/app/risk/{rr}/reject', {'note': 'الدرجة أقل من الواقع'})
    log('مدير الإدارة: رفض الخطر الثاني بسبب', [r.status, db(f'App\\Modules\\Risk\\Models\\Risk::find({rr})->status')], r.status in (302, 303) and db(f'App\\Modules\\Risk\\Models\\Risk::find({rr})->status') == 'rejected')
    log('مدير الإدارة: أخطاء', mcon or 'صفر', not mcon)
    mctx.close()

    # المرفوض يعود لكاتبه بسببه
    home(cpage)
    c = card(cpage, f'risk:{rr}:rejected')
    log('المنسق: «رُفض: السبب — أعده للتعديل» عنده', text(c), c.count() == 1 and 'الدرجة أقل من الواقع' in c.inner_text())
    cpage.screenshot(path=os.path.join(SHOT, '27b-coord-risk-rejected.png'))
    r = press(cpage, c); home(cpage)
    log('المنسق: ضغطة «أعده للتعديل» ← صار مسودة وعادت بطاقة «قدّمه»', [r, has(cpage, f'risk:{rr}:rejected'), has(cpage, f'risk:{rr}:draft')],
        r[0] == 200 and has(cpage, f'risk:{rr}:rejected') == 0 and has(cpage, f'risk:{rr}:draft') == 1)

    # ══ التصاريح ══
    pd, pc, pa, pv, pe = S['permit_draft'], S['permit_conditional'], S['permit_approved'], S['permit_active'], S['permit_completed']
    sctx, spage, scon = session(browser, 'tj.mushrif', TRIAL_PW)
    home(spage)
    c = card(spage, f'permit:{pd}:draft'); q = card(spage, f'permit:{pa}:reqs')
    others = sum(has(spage, k) for k in [f'permit:{pa}:activate', f'permit:{pv}:deviations', f'permit:{pe}:evaluate', f'permit:{pc}:final'])
    log('مشرف المقاول: «مسودة — قدّمه» و«بنود إلزامية باقية — استوفِها» عنده، ولا بطاقة من بطاقات المركز', [text(c), text(q), others], c.count() == 1 and q.count() == 1 and 'باقية قبل التفعيل: 1' in q.inner_text() and others == 0)
    spage.screenshot(path=os.path.join(SHOT, '27b-supervisor-permits.png'), full_page=True)
    r = press(spage, q)
    log('مشرف المقاول: «استوفِها» تفتح تصريحه', r, r[0] == 200 and r[1].startswith(f'/app/permits/{pa}'))
    home(spage)
    r = press(spage, card(spage, f'permit:{pd}:draft')); home(spage)
    log('مشرف المقاول: ضغطة «قدّمه» ← اختفت والتصريح مقدَّم', [r, has(spage, f'permit:{pd}:draft'), db(f'App\\Modules\\Permit\\Models\\Permit::find({pd})->status')],
        r[0] == 200 and has(spage, f'permit:{pd}:draft') == 0 and db(f'App\\Modules\\Permit\\Models\\Permit::find({pd})->status') == 'submitted')
    log('مشرف المقاول: أخطاء', scon or 'صفر', not scon)
    sctx.close()

    # المنسق: يراجع ويفعّل ويعالج ويقيّم — كل بطاقة تفتح موضعها
    home(cpage)
    for key, want, land in [(f'permit:{pd}:review', 'ينتظر مراجعتك', f'/app/permits/{pd}/review'),
                            (f'permit:{pa}:activate', 'بنود إلزامية باقية: 1', f'/app/permits/{pa}/activate'),
                            (f'permit:{pv}:deviations', 'انحرافات مفتوحة: 1', f'/app/permits/{pv}'),
                            (f'permit:{pe}:evaluate', 'اكتمل — قيّمه', f'/app/permits/{pe}/evaluate')]:
        home(cpage)
        c = card(cpage, key)
        ok, seen = c.count() == 1 and want in c.inner_text(), text(c)
        if key.endswith(':activate'): cpage.screenshot(path=os.path.join(SHOT, '27b-coord-permits.png'), full_page=True)
        r = press(cpage, c) if c.count() else ['—', '—']
        log(f'المنسق: {want} ← تفتح موضعها', [seen, r], ok and r[0] == 200 and r[1].startswith(land))
    home(cpage)
    log('المنسق: «معتمد بشرط» ليست عنده (الاعتماد النهائي للمركز)', has(cpage, f'permit:{pc}:final'), has(cpage, f'permit:{pc}:final') == 0)

    # المنسق: العامل في التعريف، وتقييم الطرف بعد اكتمال مشروعه
    c = card(cpage, f'worker:{S["worker"]}:induction')
    n, seen = c.count(), text(c)
    r = press(cpage, c) if n else ['—', '—']
    log('المنسق: عامل في التعريف ← تفتح ملفه', [seen, r], n == 1 and r[0] == 200 and r[1] == f'/app/workers/{S["worker"]}')
    home(cpage)
    c = card(cpage, f'party:{S["party"]}:eval:{S["project"]}')
    n, seen = c.count(), text(c)
    r = press(cpage, c) if n else ['—', '—']
    sel = cpage.locator(f'select[name=project_id] option[value="{S["project"]}"][selected]').count()
    log('المنسق: «قيّم الطرف بعد مشروعه» ← نموذج التقييم والمشروع محدد فيه', [seen, r, sel], n == 1 and r[0] == 200 and sel == 1)
    cpage.screenshot(path=os.path.join(SHOT, '27b-party-eval.png'))
    log('المنسق: أخطاء', ccon or 'صفر', not ccon)
    cctx.close()

    # ══ الحسابات والاستعداد ══
    gctx, gpage, gcon = session(browser, 'gate27b.m', TRIAL_PW)
    home(gpage)
    c = card(gpage, f'coordgap:{S["gap_unit"]}')
    log('مدير إدارة بلا منسق: «بلا منسق سلامة — رشّح منسقاً» عنده', text(c), c.count() == 1)
    gpage.screenshot(path=os.path.join(SHOT, '27b-manager-coordgap.png'))
    r = press(gpage, c)
    log('مدير إدارة بلا منسق: الزر يفتح تسجيل حساب', r, r[0] == 200 and r[1].startswith('/app/users/create'))
    r = post(gpage, '/app/users', {'name': 'منسق الإدارة التجريبية', 'username': 'gate27b.c', 'password': TRIAL_PW, 'role': 'safety_coordinator', 'organization_unit_id': S['gap_unit']})
    new_id = db('App\\Models\\User::where("username","gate27b.c")->value("id")')
    home(gpage)
    log('مدير إدارة بلا منسق: رشّح منسقاً ← اختفت البطاقة', [r.status, new_id, has(gpage, f'coordgap:{S["gap_unit"]}')], r.status in (302, 303) and new_id.isdigit() and has(gpage, f'coordgap:{S["gap_unit"]}') == 0)

    actx, apage, acon = session(browser, 'salama', '1234')
    home(apage)
    r = post(apage, f'/app/users/{new_id}/return', {'note': 'المسمى الوظيفي ناقص'})
    log('مسؤول السلامة: أعاد الحساب بسبب', r.status, r.status in (302, 303))
    home(gpage)
    c = card(gpage, f'account:{new_id}:returned')
    log('من سجّل الحساب: «أُعيد: السبب» عنده', text(c), c.count() == 1 and 'المسمى الوظيفي ناقص' in c.inner_text())
    log('من سجّل الحساب: بطاقة واحدة للسؤال — «رشّح منسقاً» لا تعود مع «صحّحه»', has(gpage, f'coordgap:{S["gap_unit"]}'), has(gpage, f'coordgap:{S["gap_unit"]}') == 0)
    gpage.screenshot(path=os.path.join(SHOT, '27b-account-returned.png'))
    r = press(gpage, c) if c.count() else ['—', '—']
    log('من سجّل الحساب: الزر يفتح تعديل الحساب', r, r[0] == 200 and f'/app/users/{new_id}' in r[1])
    log('مدير إدارة بلا منسق: أخطاء', gcon or 'صفر', not gcon)
    gctx.close()

    # مسؤول السلامة: المعتمد بشرط، والنموذج الذي فات موعده، والطرف قيد التسجيل
    home(apage)
    c = card(apage, f'permit:{pc}:final')
    log('مسؤول السلامة: «معتمد بشرط — اعتمده نهائياً» عنده', text(c), c.count() == 1 and 'معتمد بشرط' in c.inner_text())
    c = card(apage, f'party:{S["party_pending"]}:pending')
    n, seen = c.count(), text(c)
    r = press(apage, c) if n else ['—', '—']
    log('مسؤول السلامة: طرف قيد التسجيل ← يفتح الطرف', [seen, r], n == 1 and r[0] == 200 and r[1].startswith(f'/app/external-parties/{S["party_pending"]}'))
    home(apage)
    c = card(apage, f'formremind:{S["form"]}')
    log('مسؤول السلامة: نموذج فات موعده ولم يُذكَّر أصحابه', text(c), c.count() == 1)
    apage.screenshot(path=os.path.join(SHOT, '27b-salama-formremind.png'))
    r = press(apage, c) if c.count() else ['—', '—']
    home(apage)
    reminded = db(f'App\\Modules\\Form\\Models\\FormAssignment::where("form_id",{S["form"]})->whereNotNull("reminded_at")->count()')
    log('مسؤول السلامة: ضغطة «ذكّرهم» ← اختفت وسُجّل التذكير', [r, has(apage, f'formremind:{S["form"]}'), reminded], r[0] == 200 and has(apage, f'formremind:{S["form"]}') == 0 and reminded == '1')
    log('مسؤول السلامة: فريق الفعالية ليس عنده (لرئيس الأمن والسلامة)', has(apage, f'eventteam:{S["event_index"]}:', True), has(apage, f'eventteam:{S["event_index"]}:', True) == 0)
    log('مسؤول السلامة: أخطاء', acon or 'صفر', not acon)
    actx.close()

    # رئيس الأمن والسلامة: فريق الفعالية
    nctx, npage, ncon = session(browser, 'amn', '1234')
    home(npage)
    key = f'eventteam:{S["event_index"]}:'
    c = card(npage, key, True)
    log('رئيس الأمن والسلامة: «فريقها مرشَّح — اعتمده» عنده', text(c), c.count() == 1 and 'ملتقى القيادات' in c.inner_text())
    npage.screenshot(path=os.path.join(SHOT, '27b-amn-eventteam.png'))
    r = press(npage, c) if c.count() else ['—', '—']
    home(npage)
    log('رئيس الأمن والسلامة: ضغطة «اعتمده» ← اختفت', [r, has(npage, key, True)], r[0] == 200 and has(npage, key, True) == 0)
    log('رئيس الأمن والسلامة: أخطاء', ncon or 'صفر', not ncon)
    nctx.close()

    # الموظف: لا شيء من بطاقات ٢٧-ب
    ectx, epage, econ = session(browser, 'emp', '1234')
    home(epage)
    n = sum(has(epage, k, True) for k in NEW)
    log('الموظف: لا بطاقة قرار عنده', n, n == 0)
    log('الموظف: أخطاء', econ or 'صفر', not econ)
    ectx.close()
    browser.close()

clean = artisan('tinker', 'tests/gates/seed-27b.php', env={'GATE27B': 'clean'})
left = db('App\\Modules\\Risk\\Models\\Risk::where("title","like","%بوابة ٢٧-ب%")->count() + Illuminate\\Support\\Facades\\DB::table("permits")->where("title","like","%بوابة ٢٧-ب%")->count() + App\\Models\\User::where("username","like","gate27b.%")->count()')
log('التنظيف: لم يبقَ من التجهيز شيء', [clean.strip()[-40:], left], left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة ٢٧-ب خضراء'))
sys.exit(1 if errs else 0)
