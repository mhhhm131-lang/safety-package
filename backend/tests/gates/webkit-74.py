# -*- coding: utf-8 -*-
"""بوابة قرار ٧٤ (٢٠٢٦-١٠-٠٥) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

السجل العام يعدّله مسؤول السلامة وحده؛ وغيره يقترح خطراً جديداً ويصحّح مقترحه ما دام مسودة:
  المنسق (أضعف من كان يعدّل): خطر معتمد في السجل العام ← لا زر «تعديل»، والعنوان باليد يُرفض ←
  يقترح خطراً جديداً ← مسودة باسمه ← زر «تعديل» يظهر له ويحفظ ← يرفعه من «ما ينتظرك» ← الزر يختفي والعنوان يُرفض ←
  مسؤول السلامة: الزر على المعتمد وعلى المقترح، يعتمد المقترح ثم يعدّله فيبقى «معتمد» ←
  المنسق بعد الاعتماد: لا زر. مدير الإدارة: لا زر، وباب الخاص لا يفتح الخطر العام.
الحالة تُقرأ من القاعدة بعد كل ضغطة. خطر الكتاب يُقرأ ولا يُكتب عليه؛ الكتابة كلها على مقترح الجولة.

ثلاثة حسابات مؤقتة بكلمة عشوائية ومقترح واحد (وسمه G74) تُنشأ للجولة وتُحذف بعدها.
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
PROPOSAL = 'مقترح G74 — انزلاق عند مدخل القاعة'  # الوسم اللاتيني يُبحث به من سطر الأوامر (العربية لا تمر فيه سليمة على ويندوز)
MINE = f'{RISK}::where("title","like","%G74%")'
ROW = '["title","severity","likelihood","status","organization_unit_id"]'


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


CLEAN = (f'$ids={MINE}->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
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


def open_risk(page, cat_id, sub_id, risk_id):
    """السجل العام ← الفئة ← الفرعية ← الخطر؛ يعيد (عدد أزرار «تعديل»، عدد أزرار «تفعيل للخاص») في رأس لوحة التفاصيل"""
    page.goto(BASE + '/app/risk/reference', wait_until='networkidle')
    page.wait_for_selector(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"]')
    page.locator(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"] span').click()
    page.wait_for_selector(f'#ref-sc-{sub_id}')
    page.locator(f'#ref-sc-{sub_id}').check()
    page.wait_for_selector(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"]')
    page.locator(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"] .risk-title').click()
    page.wait_for_selector('#ref-detail-panel-body table')
    page.wait_for_timeout(400)
    head = page.locator('#ref-detail-panel-header')
    return head.locator(f'a[href$="/reference/{risk_id}/edit"]').count(), head.locator(f'a[href$="/{risk_id}/activate"]').count()


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
    return c


def press(page, c):
    """ضغطة الزر الأساسي في البطاقة؛ تعيد حالة الاستجابة"""
    form = c.locator('.task-actions form button.btn-g')
    target = form if form.count() else c.locator('.task-actions a.btn-g')
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        target.first.click()
    return nav.value.status


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

        # ── المنسق: المعتمد بلا زر تعديل ──
        ctx, page, bad = session(b, 'g74.c')
        edit, act = open_risk(page, cat, sub, gid)
        log('المنسق: زر «تعديل» على خطر معتمد', f'عدده={edit}', edit == 0)
        log('المنسق: زر «تفعيل للخاص» باقٍ', f'عدده={act}', act == 1)
        page.locator('#ref-detail-panel-header').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, 'g74-1-coord-approved.png'))
        st = status_of(page, f'/app/risk/reference/{gid}/edit')
        log('المنسق: عنوان تعديل المعتمد باليد', f'HTTP {st}', st == 403)
        st = status_of(page, f'/app/risk/active/{gid}/edit')
        log('المنسق: الخطر العام من باب الخاص', f'HTTP {st}', st == 404)

        # ── المنسق يقترح خطراً جديداً ──
        page.goto(BASE + '/app/risk/reference/create', wait_until='networkidle')
        page.select_option('#catSelect', str(cat)); page.wait_for_selector(f'#subCatSelect option[value="{sub}"]', state='attached')
        page.select_option('#subCatSelect', str(sub))
        page.fill('input[name=title]', PROPOSAL)
        page.select_option('#sevSelect', '2'); page.select_option('#likSelect', '3')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            page.locator('form[action$="/app/risk/reference/create"] button[type=submit]').click()
        prow = db(f'json_encode({MINE}->first()?->only(["risk_type","status"]))')
        pid = db(f'{MINE}->value("id")')
        log('المنسق اقترح خطراً ← مسودة في السجل العام', f'HTTP {nav.value.status} #{pid} {prow}', prow == '{"risk_type":"reference","status":"draft"}')
        pid = int(pid)
        edit, _ = open_risk(page, cat, sub, pid)
        log('المنسق: زر «تعديل» على مقترحه المسودة', f'عدده={edit}', edit == 1)
        page.locator('#ref-detail-panel-header').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, 'g74-2-coord-own-draft.png'))
        page.locator(f'#ref-detail-panel-header a[href$="/reference/{pid}/edit"]').click(); page.wait_for_load_state('networkidle')
        page.fill('input[name=title]', PROPOSAL + ' الكبرى')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            page.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        t = db(f'{MINE}->value("title")')
        log('المنسق صحّح مقترحه', f'HTTP {nav.value.status} {t}', t == PROPOSAL + ' الكبرى')

        # ── يرفعه من «ما ينتظرك» ──
        c = card(page, f'risk:{pid}:draft')
        log('المنسق: بطاقة «قدّمه» في ما ينتظرك', f'عددها={c.count()}', c.count() == 1)
        st = press(page, c)
        s = db(f'{MINE}->value("status")')
        log('المقترح بعد «قدّمه»', f'HTTP {st} ← {s}', s == 'pending_approval')
        edit, _ = open_risk(page, cat, sub, pid)
        log('المنسق: زر «تعديل» على مقترحه بعد الرفع', f'عدده={edit}', edit == 0)
        st = status_of(page, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه بعد الرفع', f'HTTP {st}', st == 403)
        log('المنسق: أخطاء خادم', bad, not bad)
        ctx.close()

        # ── مسؤول السلامة ──
        ctx, page, bad = session(b, 'g74.s')
        edit, _ = open_risk(page, cat, sub, gid)
        log('مسؤول السلامة: زر «تعديل» على خطر الكتاب المعتمد', f'عدده={edit}', edit == 1)
        page.locator('#ref-detail-panel-header').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, 'g74-3-officer-approved.png'))
        c = card(page, f'risk:{pid}:approve')
        log('مسؤول السلامة: بطاقة «اعتمد» للمقترح', f'عددها={c.count()}', c.count() == 1)
        st = press(page, c)
        s = db(f'{MINE}->value("status")')
        log('مسؤول السلامة اعتمد المقترح', f'HTTP {st} ← {s}', s == 'approved')
        edit, _ = open_risk(page, cat, sub, pid)
        log('مسؤول السلامة: زر «تعديل» على المقترح بعد اعتماده', f'عدده={edit}', edit == 1)
        page.locator(f'#ref-detail-panel-header a[href$="/reference/{pid}/edit"]').click(); page.wait_for_load_state('networkidle')
        page.fill('input[name=title]', PROPOSAL + ' — عدّله مسؤول السلامة')
        with page.expect_navigation(wait_until='domcontentloaded') as nav:
            page.locator(f'form[action$="/reference/{pid}/edit"] button[type=submit]').click()
        row = db(f'json_encode({MINE}->first()->only(["title","status"]), JSON_UNESCAPED_UNICODE)')
        log('مسؤول السلامة عدّل خطراً معتمداً وبقي «معتمد»', f'HTTP {nav.value.status} {row}',
            row == '{"title":"' + PROPOSAL + ' — عدّله مسؤول السلامة","status":"approved"}')
        log('مسؤول السلامة: أخطاء خادم', bad, not bad)
        ctx.close()

        # ── المنسق بعد الاعتماد ──
        ctx, page, bad = session(b, 'g74.c')
        edit, _ = open_risk(page, cat, sub, pid)
        log('المنسق: زر «تعديل» على مقترحه بعد اعتماده', f'عدده={edit}', edit == 0)
        st = status_of(page, f'/app/risk/reference/{pid}/edit')
        log('المنسق: عنوان تعديل مقترحه بعد اعتماده', f'HTTP {st}', st == 403)
        ctx.close()

        # ── مدير الإدارة ──
        ctx, page, bad = session(b, 'g74.m')
        edit, act = open_risk(page, cat, sub, gid)
        log('مدير الإدارة: زر «تعديل» على خطر معتمد', f'عدده={edit}', edit == 0)
        log('مدير الإدارة: زر «تفعيل للخاص» باقٍ', f'عدده={act}', act == 1)
        page.locator('#ref-detail-panel-header').scroll_into_view_if_needed()
        page.screenshot(path=os.path.join(SHOT, 'g74-4-manager-approved.png'))
        st = status_of(page, f'/app/risk/active/{gid}/edit')
        log('مدير الإدارة: الخطر العام من باب الخاص', f'HTTP {st}', st == 404)
        log('مدير الإدارة: أخطاء خادم', bad, not bad)
        ctx.close()
        b.close()

    after = db(f'json_encode({RISK}::whereKey({gid})->first()->only({ROW}), JSON_UNESCAPED_UNICODE)')
    log('خطر الكتاب بعد الجولة كما كان', after, after == before)
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())

print('\nفشل: ' + '، '.join(errs) if errs else '\nكل الفحوص مرّت')
sys.exit(1 if errs else 0)
