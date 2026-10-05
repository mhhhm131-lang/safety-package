# -*- coding: utf-8 -*-
"""بوابة قراري ٧٤ و٧٥ (٢٠٢٦-١٠-٠٥) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

٧٤: السجل العام يعدّله مسؤول السلامة وحده؛ وغيره يقترح خطراً جديداً ويصحّح مقترحه ما دام مسودة.
٧٥: المقترح لا يظهر في السجل العام ولا يُفعَّل حتى يعتمده مسؤول السلامة، وبعد اعتماده يظهر دائماً؛ والمقترِح يُقال له ذلك عند «حفظ».
  المنسق (أضعف من كان يعدّل): خطر معتمد ← لا زر «تعديل»، والعنوان باليد يُرفض ←
  يقترح خطراً ← رسالة «لا يظهر… إلا بعد اعتماد مسؤول السلامة» ← المقترح ليس في الشجرة ولا الجدول ←
  بطاقته «عدّله» ← يصحّح ويحفظ ← «قدّمه» ← ما زال خارج العام، وتعديله وتفعيله بالعنوان يُرفضان ←
  مسؤول السلامة: لا يراه في العام، يعتمده من بطاقته ← يظهر في الشجرة، يعدّله فيبقى «معتمد» ←
  مقترح بلا فئة فرعية ← بعد اعتماده يظهر تحت «بلا فئة فرعية» ←
  المنسق بعد الاعتماد: يراه بلا زر «تعديل». مدير الإدارة: لا زر، وباب الخاص لا يفتح الخطر العام.
الحالة تُقرأ من القاعدة بعد كل ضغطة. خطر الكتاب يُقرأ ولا يُكتب عليه؛ الكتابة كلها على مقترحَي الجولة.

ثلاثة حسابات مؤقتة بكلمة عشوائية ومقترحان (وسمهما G74) تُنشأ للجولة وتُحذف بعدها.
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
MINE = f'{RISK}::where("title","like","%G74A%")'
MINE_B = f'{RISK}::where("title","like","%G74B%")'
ROW = '["title","severity","likelihood","status","organization_unit_id"]'
NOTE = 'لا يظهر في السجل العام إلا بعد اعتماد مسؤول السلامة'


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
    """ضغطة الزر الأساسي في البطاقة؛ تعيد حالة الاستجابة"""
    form = c.locator('.task-actions form button.btn-g')
    target = form if form.count() else c.locator('.task-actions a.btn-g')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        target.first.click()
    return nav.value.status


def propose(page, cat, sub, title):
    """نموذج الإضافة ← «حفظ»؛ يعيد (حالة الاستجابة، نص الرسالة الخضراء في الصفحة التي عاد إليها)"""
    page.goto(BASE + '/app/risk/reference/create', wait_until='networkidle')
    page.select_option('#catSelect', str(cat))
    if sub:
        page.wait_for_selector(f'#subCatSelect option[value="{sub}"]', state='attached')
        page.select_option('#subCatSelect', str(sub))
    page.fill('input[name=title]', title)
    page.select_option('#sevSelect', '2'); page.select_option('#likSelect', '3')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.locator('form[action$="/app/risk/reference/create"] button[type=submit]').click()
    msg = page.locator('.alert-success')
    return nav.value.status, (msg.first.inner_text().strip() if msg.count() else '')


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

        # ── المنسق: المعتمد بلا زر تعديل (٧٤) ──
        ctx, page, bad = session(b, 'g74.c')
        edit, act = open_risk(page, cat, sub, gid)
        log('المنسق: زر «تعديل» على خطر معتمد', f'عدده={edit}', edit == 0)
        log('المنسق: زر «تفعيل للخاص» باقٍ', f'عدده={act}', act == 1)
        st = status_of(page, f'/app/risk/reference/{gid}/edit')
        log('المنسق: عنوان تعديل المعتمد باليد', f'HTTP {st}', st == 403)
        st = status_of(page, f'/app/risk/active/{gid}/edit')
        log('المنسق: الخطر العام من باب الخاص', f'HTTP {st}', st == 404)

        # ── المنسق يقترح: الرسالة عند «حفظ»، والمقترح خارج العام (٧٥) ──
        st, msg = propose(page, cat, sub, PROPOSAL)
        page.screenshot(path=os.path.join(SHOT, 'g75-1-coord-saved-message.png'))
        prow = db(f'json_encode({MINE}->first()?->only(["risk_type","status"]))')
        pid = db(f'{MINE}->value("id")')
        log('المنسق اقترح خطراً ← مسودة', f'HTTP {st} #{pid} {prow}', prow == '{"risk_type":"reference","status":"draft"}')
        log('المنسق: رسالة الحفظ تقول أين مقترحه', msg, NOTE in msg and 'قدّمه' in msg)
        pid = int(pid)
        n = tree_count(page, cat, sub, pid)
        log('المنسق: مقترحه المسودة في شجرة العام', f'عدده={n}', n == 0)
        page.screenshot(path=os.path.join(SHOT, 'g75-2-coord-tree-without-proposal.png'))
        n = in_table(page, pid)
        log('المنسق: مقترحه المسودة في جدول العام', f'عدده={n}', n == 0)

        # ── يصحّحه من بطاقته ثم يرفعه ──
        c = card(page, f'risk:{pid}:draft')
        log('المنسق: بطاقة مقترحه في ما ينتظرك', f'عددها={c.count()}', c.count() == 1)
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            c.locator(f'a[href$="/reference/{pid}/edit"]').first.click()
        log('المنسق: «عدّله» من البطاقة يفتح نموذج مقترحه', f'HTTP {nav.value.status}', nav.value.status == 200)
        page.wait_for_load_state('networkidle')
        page.fill('input[name=title]', PROPOSAL + ' الكبرى')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            page.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        t = db(f'{MINE}->value("title")')
        msg = page.locator('.alert-success').first.inner_text().strip() if page.locator('.alert-success').count() else ''
        log('المنسق صحّح مقترحه', f'HTTP {nav.value.status} {t}', t == PROPOSAL + ' الكبرى')
        log('المنسق: رسالة التصحيح', msg, NOTE in msg)
        c = card(page, f'risk:{pid}:draft')
        st = press(page, c)
        s = db(f'{MINE}->value("status")')
        log('المقترح بعد «قدّمه»', f'HTTP {st} ← {s}', s == 'pending_approval')
        n = tree_count(page, cat, sub, pid)
        log('المنسق: مقترحه المرفوع في شجرة العام', f'عدده={n}', n == 0)
        st = status_of(page, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه بعد الرفع', f'HTTP {st}', st == 403)
        st = status_of(page, f'/app/risk/{pid}/activate')
        log('المنسق: عنوان تفعيل مقترح لم يُعتمد', f'HTTP {st}', st == 404)

        # ── مقترح ثانٍ بلا فئة فرعية، يرفعه ──
        st, msg = propose(page, cat, None, NOSUB)
        qid = int(db(f'{MINE_B}->value("id")'))
        qrow = db(f'json_encode({MINE_B}->first()->only(["status","sub_category_id"]))')
        log('المنسق اقترح خطراً بلا فئة فرعية', f'HTTP {st} #{qid} {qrow}', qrow == '{"status":"draft","sub_category_id":null}')
        st = press(page, card(page, f'risk:{qid}:draft'))
        s = db(f'{MINE_B}->value("status")')
        log('المقترح الثاني بعد «قدّمه»', f'HTTP {st} ← {s}', s == 'pending_approval')
        log('المنسق: أخطاء خادم', bad, not bad)
        ctx.close()

        # ── مسؤول السلامة ──
        ctx, page, bad = session(b, 'g74.s')
        edit, _ = open_risk(page, cat, sub, gid)
        log('مسؤول السلامة: زر «تعديل» على خطر الكتاب المعتمد', f'عدده={edit}', edit == 1)
        n = tree_count(page, cat, sub, pid)
        log('مسؤول السلامة: المقترح المرفوع في شجرة العام', f'عدده={n}', n == 0)
        c = card(page, f'risk:{pid}:approve')
        log('مسؤول السلامة: بطاقة «اعتمد» للمقترح', f'عددها={c.count()}', c.count() == 1)
        page.screenshot(path=os.path.join(SHOT, 'g75-3-officer-awaiting-card.png'))
        st = press(page, c)
        s = db(f'{MINE}->value("status")')
        log('مسؤول السلامة اعتمد المقترح', f'HTTP {st} ← {s}', s == 'approved')
        edit, _ = open_risk(page, cat, sub, pid)
        log('مسؤول السلامة: المقترح بعد اعتماده في الشجرة وعليه «تعديل»', f'عدده={edit}', edit == 1)
        page.locator('#ref-detail-panel-header').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, 'g75-4-officer-approved-in-tree.png'))
        n = in_table(page, pid)
        log('مسؤول السلامة: المعتمد في جدول العام', f'عدده={n}', n == 1)
        page.goto(BASE + f'/app/risk/reference/{pid}/edit', wait_until='networkidle')
        page.fill('input[name=title]', PROPOSAL + ' — عدّله مسؤول السلامة')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            page.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        row = db(f'json_encode({MINE}->first()->only(["title","status"]), JSON_UNESCAPED_UNICODE)')
        log('مسؤول السلامة عدّل خطراً معتمداً وبقي «معتمد»', f'HTTP {nav.value.status} {row}',
            row == '{"title":"' + PROPOSAL + ' — عدّله مسؤول السلامة","status":"approved"}')

        # بلا فئة فرعية: قبل الاعتماد لا يظهر، وبعده تحت «بلا فئة فرعية»
        n = tree_count(page, cat, -cat, qid)
        log('مسؤول السلامة: المقترح بلا فئة فرعية قبل اعتماده', f'عدده={n} (‎-1 = لا خانة)', n in (0, -1))
        st = press(page, card(page, f'risk:{qid}:approve'))
        s = db(f'{MINE_B}->value("status")')
        log('مسؤول السلامة اعتمد المقترح الثاني', f'HTTP {st} ← {s}', s == 'approved')
        n = tree_count(page, cat, -cat, qid)
        name = page.locator(f'label[for="ref-sc--{cat}"]').inner_text().strip() if n >= 0 else ''
        log('مسؤول السلامة: المعتمد بلا فئة فرعية يظهر تحت خانته', f'«{name}» عدده={n}', n == 1 and name == 'بلا فئة فرعية')
        page.screenshot(path=os.path.join(SHOT, 'g75-5-officer-no-subcategory.png'))
        log('مسؤول السلامة: أخطاء خادم', bad, not bad)
        ctx.close()

        # ── المنسق بعد الاعتماد ──
        ctx, page, bad = session(b, 'g74.c')
        edit, act = open_risk(page, cat, sub, pid)
        log('المنسق: مقترحه بعد اعتماده في الشجرة بلا «تعديل»', f'تعديل={edit} تفعيل={act}', edit == 0 and act == 1)
        st = status_of(page, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه بعد اعتماده', f'HTTP {st}', st == 403)
        st = status_of(page, f'/app/risk/{pid}/activate')
        log('المنسق: نموذج تفعيله بعد اعتماده', f'HTTP {st}', st == 200)
        ctx.close()

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
