# -*- coding: utf-8 -*-
"""بوابة القرارات ٧٤ و٧٥ و٧٦ (٢٠٢٦-١٠-٠٥) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

٧٤: السجل العام يعدّله مسؤول السلامة وحده؛ وغيره يقترح خطراً جديداً ويصحّح مقترحه إذا أُعيد له.
٧٥: المقترح لا يظهر في السجل العام ولا يُفعَّل حتى يعتمده مسؤول السلامة، وبعد اعتماده يظهر دائماً؛ والمقترِح يُقال له ذلك عند «حفظ».
٧٦: «حفظ» ضغطة واحدة — يرسل المقترح إلى مسؤول السلامة؛ ومسؤول السلامة يضيف فيُعتمد؛ والمرفوض «صحّحه» ثم «حفظ» يعيد إرساله.
  المنسق (أضعف من كان يعدّل): خطر معتمد ← لا زر «تعديل»، والعنوان باليد يُرفض ←
  يقترح خطراً ← «حفظ» ← رسالة «أُرسل مقترحك إلى مسؤول السلامة…» ← بانتظار الاعتماد بلا ضغطة ثانية، ولا بطاقة عنده ←
  ليس في الشجرة ولا الجدول، وتعديله وتفعيله بالعنوان يُرفضان ←
  مسؤول السلامة: لا يراه في العام، يرفضه من شاشة الاعتماد ← المنسق: بطاقة «صحّحه» ← يصحّح و«حفظ» يعيد إرساله ←
  مسؤول السلامة يعتمده من بطاقته ← يظهر في الشجرة، يعدّله فيبقى «معتمد» ←
  مقترح بلا فئة فرعية ← بعد اعتماده يظهر تحت «بلا فئة فرعية» ← مسؤول السلامة يضيف خطراً ← معتمد ويظهر فوراً ←
  المنسق بعد الاعتماد: يراه بلا زر «تعديل». مدير الإدارة: لا زر، وباب الخاص لا يفتح الخطر العام.
الحالة تُقرأ من القاعدة بعد كل ضغطة. خطر الكتاب يُقرأ ولا يُكتب عليه؛ الكتابة كلها على أخطار الجولة.

ثلاثة حسابات مؤقتة بكلمة عشوائية وثلاثة أخطار (وسمها G74) تُنشأ للجولة وتُحذف بعدها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-74.py [BASE]
"""
import sys, os, secrets, subprocess
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
PW = secrets.token_urlsafe(12)
errs = []
assert '127.0.0.1' in BASE or 'localhost' in BASE, 'البوابة للمحلي وحده (قرار ٥٧)'

RISK = 'App\\Modules\\Risk\\Models\\Risk'
USER = 'App\\Models\\User'
PROFILE = 'App\\Modules\\Governance\\Models\\UserProfile'
UNIT = 'App\\Modules\\Governance\\Models\\OrganizationUnit'
DBF = 'Illuminate\\Support\\Facades\\DB'
# الوسم اللاتيني يُبحث به من سطر الأوامر (العربية لا تمر فيه سليمة على ويندوز)
PROPOSAL = 'مقترح G74A — انزلاق عند مدخل القاعة'
NOSUB = 'مقترح G74B — بلا فئة فرعية'
OWN = 'خطر G74C — يضيفه مسؤول السلامة'
MINE = f'{RISK}::where("title","like","%G74A%")'
MINE_B = f'{RISK}::where("title","like","%G74B%")'
MINE_C = f'{RISK}::where("title","like","%G74C%")'
ROW = '["title","severity","likelihood","status","organization_unit_id"]'
SENT = 'أُرسل مقترحك إلى مسؤول السلامة. يظهر في السجل العام بعد اعتماده.'


def log(k, v, ok=None):
    mark = '' if ok is None else ('✓ ' if ok else '✗ ')
    print(f'{mark}{k} | {v}', flush=True)
    if ok is False:
        errs.append(k)


def tinker(code):
    r = subprocess.run(['php', 'artisan', 'tinker', '--execute', code], cwd=BACKEND, capture_output=True, stdin=subprocess.DEVNULL)
    return r.stdout.decode('utf-8', 'replace') + r.stderr.decode('utf-8', 'replace')


def db(expr):
    return tinker(f'echo "V:".({expr});').split('V:')[-1].strip()


CLEAN = (f'$ids={RISK}::where("title","like","%G74%")->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::whereIn("username",["g74.c","g74.m","g74.s"])->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); ')


def session(browser, user):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    bad = []
    page.on('response', lambda r: bad.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    for _ in range(4):  # الخادم المحلي بخيط واحد: الدخول يُعاد إن سبقته الصفحة
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            break  # الجلسة قائمة
        page.fill('input[name=username]', user); page.fill('input[name=password]', PW)
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.locator('form:has(input[name=username]) button[type=submit]').click()
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            break
        page.wait_for_timeout(5000)
    assert '/login' not in page.url, f'فشل دخول {user}'
    return ctx, page, bad


def tree_count(page, cat_id, sub_dom_id, risk_id):
    """السجل العام ← الفئة ← الفرعية: عدد مرات ظهور الخطر في القائمة (‎-1 إن لم تظهر خانة الفرعية أصلاً)"""
    page.goto(BASE + '/app/risk/reference', wait_until='networkidle')
    page.wait_for_selector(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"]')
    with page.expect_response(lambda r: f'/sub-categories/{cat_id}' in r.url):
        page.locator(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"] span').click()
    page.wait_for_timeout(400)
    if page.locator(f'#ref-sc-{sub_dom_id}').count() == 0:
        return -1
    with page.expect_response(lambda r: f'/risks-by-sub-category/{sub_dom_id}' in r.url):
        page.locator(f'#ref-sc-{sub_dom_id}').check()
    page.wait_for_timeout(500)
    return page.locator(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"]').count()


def open_risk(page, cat_id, sub_dom_id, risk_id):
    """يفتح الخطر في الشجرة؛ يعيد (عدد أزرار «تعديل»، عدد أزرار «تفعيل للخاص») في رأس لوحة التفاصيل"""
    n = tree_count(page, cat_id, sub_dom_id, risk_id)
    assert n == 1, f'الخطر {risk_id} ليس في الشجرة (العدد {n})'
    page.locator(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"] .risk-title').click()
    page.wait_for_selector('#ref-detail-panel-body table')
    page.wait_for_timeout(400)
    head = page.locator('#ref-detail-panel-header')
    return head.locator(f'a[href$="/reference/{risk_id}/edit"]').count(), head.locator(f'a[href$="/{risk_id}/activate"]').count()


def in_table(page, risk_id):
    page.goto(BASE + '/app/risk/reference', wait_until='networkidle')
    return page.locator(f'#ref-table-view a.risk-title-link[href$="/app/risk/{risk_id}/detail"]').count()  # رابط العنوان: واحد لكل خطر


def status_of(page, url):
    return page.request.get(BASE + url).status


def card(page, key):
    """بطاقة «ما ينتظرك» بمعرّفها؛ إن وُجدت تُفتح مجموعتها المطوية"""
    page.goto(BASE + '/app', wait_until='networkidle')
    c = page.locator(f'#inboxList [data-task="{key}"]')
    if c.count():
        btn = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')
        if btn.count() and btn.get_attribute('aria-expanded') == 'false':
            btn.click(); page.wait_for_timeout(600)
        if not c.first.is_visible():  # قرار ٧١: بطاقتان فأكثر تُدمجان في واحدة — البند تحت «اعرضها»
            batch = c.first.locator('xpath=ancestor::*[@data-batch][1]')
            batch.locator('.task-actions > button[data-bs-toggle=collapse]').first.click(); page.wait_for_timeout(700)
    return c


def press(page, c):
    """ضغطة الزر الأساسي في البطاقة (نموذج أو رابط)؛ تعيد حالة الاستجابة"""
    form = c.locator('.task-actions form button.btn-g')
    target = form if form.count() else c.locator('.task-actions a.btn-g')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        target.first.click()
    return nav.value.status


def flash(page):
    msg = page.locator('.alert-success')
    return msg.first.inner_text().strip() if msg.count() else ''


def add(page, cat, sub, title):
    """نموذج الإضافة ← «حفظ» (ضغطة واحدة)؛ يعيد (حالة الاستجابة، نص الرسالة الخضراء في الصفحة التي عاد إليها)"""
    page.goto(BASE + '/app/risk/reference/create', wait_until='networkidle')
    page.select_option('#catSelect', str(cat))
    if sub:
        page.wait_for_selector(f'#subCatSelect option[value="{sub}"]', state='attached')
        page.select_option('#subCatSelect', str(sub))
    page.fill('input[name=title]', title)
    page.select_option('#sevSelect', '2'); page.select_option('#likSelect', '3')
    # القائمتان تُحمَّلان بعد الاختيار، وخانة «جاري التحميل...» بلا قيمة فارغة: الحفظ قبل اكتمالها يُرفض (مسجَّل في المؤجلات) — ننتظرها كما ينتظر الإنسان
    page.wait_for_function("() => !document.getElementById('subCatSelect').innerHTML.includes('جاري')")  # «نوع الخطر» حُذفت من النموذج (٢٠٢٦-١٠-٠٨)
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.locator('form[action$="/app/risk/reference/create"] button[type=submit]').click()
    bad = page.locator('.alert-danger')
    if bad.count():  # النموذج عاد بخطأ: يُقال نصّه لا صمت
        return nav.value.status, 'خطأ: ' + bad.first.inner_text().strip()[:200]
    return nav.value.status, flash(page)


print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
try:
    unit = db(f'{UNIT}::where("is_active",true)->whereNotNull("parent_id")->orderBy("id")->value("id")')
    for name, role, u in [('g74.c', 'safety_coordinator', unit), ('g74.m', 'department_manager', unit), ('g74.s', 'system_admin', 'null')]:
        tinker(f'$u={USER}::create(["username"=>"{name}","name"=>"gate74 {role}","password"=>"{PW}"]); '
               f'{PROFILE}::create(["user_id"=>$u->id,"role"=>"{role}","is_active"=>true,"organization_unit_id"=>{u}]);')
    # خطر معتمد من كتاب المعهد: يُقرأ ولا يُكتب عليه
    gid = int(db(f'{RISK}::where("risk_type","reference")->where("status","approved")->whereNotNull("sub_category_id")->orderBy("id")->value("id")'))
    cat, sub = [int(x) for x in db(f'implode(",", {RISK}::whereKey({gid})->first()->only(["category_id","sub_category_id"]))').split(',')]
    before = db(f'json_encode({RISK}::whereKey({gid})->first()->only({ROW}), JSON_UNESCAPED_UNICODE)')
    log('خطر الكتاب قبل الجولة', f'#{gid} {before}')

    with sync_playwright() as p:
        b = p.webkit.launch()
        cctx, cp, cbad = session(b, 'g74.c')   # المنسق
        sctx, sp, sbad = session(b, 'g74.s')   # مسؤول السلامة

        # ── المنسق: المعتمد بلا زر تعديل (٧٤) ──
        edit, act = open_risk(cp, cat, sub, gid)
        log('المنسق: زر «تعديل» على خطر معتمد', f'عدده={edit}', edit == 0)
        log('المنسق: زر «تفعيل للخاص» باقٍ', f'عدده={act}', act == 1)
        st = status_of(cp, f'/app/risk/reference/{gid}/edit')
        log('المنسق: عنوان تعديل المعتمد باليد', f'HTTP {st}', st == 403)
        st = status_of(cp, f'/app/risk/active/{gid}/edit')
        log('المنسق: الخطر العام من باب الخاص', f'HTTP {st}', st == 404)

        # ── المنسق يقترح: «حفظ» ضغطة واحدة ترسل، والرسالة تقول ذلك (٧٥ و٧٦) ──
        st, msg = add(cp, cat, sub, PROPOSAL)
        cp.screenshot(path=os.path.join(SHOT, 'g76-1-coord-sent-message.png'))
        prow = db(f'json_encode({MINE}->first()?->only(["risk_type","status"]))')
        pid = db(f'{MINE}->value("id")')
        log('المنسق ضغط «حفظ» ← مقترحه عند مسؤول السلامة', f'HTTP {st} #{pid} {prow}', prow == '{"risk_type":"reference","status":"pending_approval"}')
        log('المنسق: رسالة الحفظ', msg, msg == SENT)
        pid = int(pid)
        c = card(cp, f'risk:{pid}:draft')
        log('المنسق: لا بطاقة «قدّمه» عنده (لا ضغطة ثانية)', f'عددها={c.count()}', c.count() == 0)
        n = tree_count(cp, cat, sub, pid)
        log('المنسق: مقترحه في شجرة العام قبل اعتماده', f'عدده={n}', n == 0)
        n = in_table(cp, pid)
        log('المنسق: مقترحه في جدول العام قبل اعتماده', f'عدده={n}', n == 0)
        st = status_of(cp, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه المرسَل', f'HTTP {st}', st == 403)
        st = status_of(cp, f'/app/risk/{pid}/activate')
        log('المنسق: عنوان تفعيل مقترح لم يُعتمد', f'HTTP {st}', st == 404)

        # ── مقترح ثانٍ بلا فئة فرعية ──
        st, msg = add(cp, cat, None, NOSUB)
        qid = int(db(f'{MINE_B}->value("id")'))
        qrow = db(f'json_encode({MINE_B}->first()->only(["status","sub_category_id"]))')
        log('المنسق اقترح خطراً بلا فئة فرعية', f'HTTP {st} #{qid} {qrow}', qrow == '{"status":"pending_approval","sub_category_id":null}' and msg == SENT)

        # ── مسؤول السلامة: لا يراه في العام؛ يرفضه من شاشة الاعتماد ──
        n = tree_count(sp, cat, sub, pid)
        log('مسؤول السلامة: المقترح في شجرة العام قبل اعتماده', f'عدده={n}', n == 0)
        sp.goto(BASE + '/app/risk/approval/queue', wait_until='networkidle')
        sp.locator(f'[data-bs-target="#rejectModal{pid}"]').click(); sp.wait_for_selector(f'#rejectModal{pid}.show')
        sp.fill(f'#rejectModal{pid} textarea[name=note]', 'حدّد أي مدخل')
        with sp.expect_navigation(wait_until='domcontentloaded') as nav:
            sp.locator(f'#rejectModal{pid} button[type=submit]').click()  # النافذة داخل جدول: الزر مربوط بنموذجه لا ابناً له
        s = db(f'{MINE}->value("status")')
        log('مسؤول السلامة رفض المقترح بسببه', f'HTTP {nav.value.status} ← {s}', s == 'rejected')

        # ── المنسق: «صحّحه» ثم «حفظ» يعيد إرساله (ضغطتان) ──
        c = card(cp, f'risk:{pid}:rejected')
        txt = c.first.inner_text().strip().replace('\n', ' ') if c.count() else ''
        log('المنسق: بطاقة المرفوض بسببه وزرها «صحّحه»', txt[:110], c.count() == 1 and 'حدّد أي مدخل' in txt and 'صحّحه' in txt)
        cp.screenshot(path=os.path.join(SHOT, 'g76-2-coord-rejected-card.png'))
        st = press(cp, c)
        log('المنسق: «صحّحه» تفتح نموذج مقترحه', f'HTTP {st} {cp.url.replace(BASE, "")}', st == 200 and cp.url.endswith(f'/reference/{pid}/edit'))
        cp.wait_for_load_state('networkidle')
        cp.fill('input[name=title]', PROPOSAL + ' الكبرى')
        with cp.expect_navigation(wait_until='domcontentloaded') as nav:
            cp.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        row = db(f'json_encode({MINE}->first()->only(["title","status"]), JSON_UNESCAPED_UNICODE)')
        log('المنسق صحّح و«حفظ» أعاد الإرسال', f'HTTP {nav.value.status} {row}', row == '{"title":"' + PROPOSAL + ' الكبرى","status":"pending_approval"}')
        log('المنسق: رسالة حفظ التصحيح', flash(cp), flash(cp) == SENT)

        # ── مسؤول السلامة يعتمد، ثم يعدّل المعتمد ──
        edit, _ = open_risk(sp, cat, sub, gid)
        log('مسؤول السلامة: زر «تعديل» على خطر الكتاب المعتمد', f'عدده={edit}', edit == 1)
        c = card(sp, f'risk:{pid}:approve')
        log('مسؤول السلامة: بطاقة «اعتمد» للمقترح', f'عددها={c.count()}', c.count() == 1)
        st = press(sp, c)
        s = db(f'{MINE}->value("status")')
        log('مسؤول السلامة اعتمد المقترح', f'HTTP {st} ← {s}', s == 'approved')
        edit, _ = open_risk(sp, cat, sub, pid)
        log('مسؤول السلامة: المقترح بعد اعتماده في الشجرة وعليه «تعديل»', f'عدده={edit}', edit == 1)
        n = in_table(sp, pid)
        log('مسؤول السلامة: المعتمد في جدول العام', f'عدده={n}', n == 1)
        sp.goto(BASE + f'/app/risk/reference/{pid}/edit', wait_until='networkidle')
        sp.fill('input[name=title]', PROPOSAL + ' — عدّله مسؤول السلامة')
        with sp.expect_navigation(wait_until='domcontentloaded') as nav:
            sp.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        row = db(f'json_encode({MINE}->first()->only(["title","status"]), JSON_UNESCAPED_UNICODE)')
        log('مسؤول السلامة عدّل خطراً معتمداً وبقي «معتمد»', f'HTTP {nav.value.status} {row}',
            row == '{"title":"' + PROPOSAL + ' — عدّله مسؤول السلامة","status":"approved"}')

        # بلا فئة فرعية: قبل الاعتماد لا يظهر، وبعده تحت «بلا فئة فرعية»
        n = tree_count(sp, cat, -cat, qid)
        log('مسؤول السلامة: المقترح بلا فئة فرعية قبل اعتماده', f'عدده={n} (‎-1 = لا خانة)', n in (0, -1))
        st = press(sp, card(sp, f'risk:{qid}:approve'))
        s = db(f'{MINE_B}->value("status")')
        log('مسؤول السلامة اعتمد المقترح الثاني', f'HTTP {st} ← {s}', s == 'approved')
        n = tree_count(sp, cat, -cat, qid)
        name = sp.locator(f'label[for="ref-sc--{cat}"]').inner_text().strip() if n >= 0 else ''
        log('مسؤول السلامة: المعتمد بلا فئة فرعية يظهر تحت خانته', f'«{name}» عدده={n}', n == 1 and name == 'بلا فئة فرعية')

        # مسؤول السلامة يضيف خطراً: «حفظ» ← معتمد ويظهر فوراً (٧٦)
        st, msg = add(sp, cat, sub, OWN)
        sp.screenshot(path=os.path.join(SHOT, 'g76-3-officer-added-message.png'))
        oid = int(db(f'{MINE_C}->value("id")'))
        orow = db(f'json_encode({MINE_C}->first()->only(["status"]))')
        log('مسؤول السلامة ضغط «حفظ» ← خطره معتمد', f'HTTP {st} #{oid} {orow}', orow == '{"status":"approved"}')
        log('مسؤول السلامة: رسالة الحفظ', msg, msg == 'أُضيف الخطر إلى السجل العام.')
        n = tree_count(sp, cat, sub, oid)
        log('مسؤول السلامة: خطره في شجرة العام فوراً', f'عدده={n}', n == 1)
        log('مسؤول السلامة: أخطاء خادم', sbad, not sbad)
        sctx.close()

        # ── المنسق بعد الاعتماد ──
        edit, act = open_risk(cp, cat, sub, pid)
        log('المنسق: مقترحه بعد اعتماده في الشجرة بلا «تعديل»', f'تعديل={edit} تفعيل={act}', edit == 0 and act == 1)
        st = status_of(cp, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه بعد اعتماده', f'HTTP {st}', st == 403)
        st = status_of(cp, f'/app/risk/{pid}/activate')
        log('المنسق: نموذج تفعيله بعد اعتماده', f'HTTP {st}', st == 200)
        log('المنسق: أخطاء خادم', cbad, not cbad)
        cctx.close()

        # ── مدير الإدارة ──
        ctx, page, bad = session(b, 'g74.m')
        edit, act = open_risk(page, cat, sub, gid)
        log('مدير الإدارة: زر «تعديل» على خطر معتمد', f'عدده={edit}', edit == 0)
        log('مدير الإدارة: زر «تفعيل للخاص» باقٍ', f'عدده={act}', act == 1)
        st = status_of(page, f'/app/risk/active/{gid}/edit')
        log('مدير الإدارة: الخطر العام من باب الخاص', f'HTTP {st}', st == 404)
        n = tree_count(page, cat, -cat, qid)
        log('مدير الإدارة: المعتمد بلا فئة فرعية في الشجرة', f'عدده={n}', n == 1)
        log('مدير الإدارة: أخطاء خادم', bad, not bad)
        ctx.close()
        b.close()

    after = db(f'json_encode({RISK}::whereKey({gid})->first()->only({ROW}), JSON_UNESCAPED_UNICODE)')
    log('خطر الكتاب بعد الجولة كما كان', after, after == before)
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())

print('\nفشل: ' + '، '.join(errs) if errs else '\nكل الفحوص مرّت')
sys.exit(1 if errs else 0)
