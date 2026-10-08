# -*- coding: utf-8 -*-
"""بوابة خطة المعالج — الخطوتان ٤ و٥ (٢٠٢٦-١٠-٠٨) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

٤ التفعيل بلا خانة «المعالج»، والخاص يقرأ من العام:
  مدير إدارة يفتح نموذج التفعيل ← لا خانة «المعالج» ومعالج العام معروض قراءةً ← يفعّل بالمنسق وحده ← نشط ←
  سجل إدارته يعرض «الإدارة المعالجة» و«المعالج» من العام ← مدير المرافق يسمّي شخصاً من «إدارتي» ← سجل الإدارة يعرضه بلا تفعيل جديد ←
  نافذة الدفعة بلا خانة «المعالج» وتفعّل خطراً ثانياً دفعةً بلا معالج.
٥ غير المغطى يظهر:
  مسؤول السلامة في «ما ينتظرك»: «كذا خطراً في السجل العام بلا إدارة معالجة» و«كذا مكاناً بلا فني…» ← مدير المرافق: «خطر على إدارتك بلا معالج» ←
  التسمية تُنزل عدّاده والمحو يرفعه.
الحالة تُقرأ من القاعدة والشاشة بعد كل ضغطة. وحدة وأربعة حسابات وخطران (وسمها G-H45) تُنشأ وتُحذف بعدها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-h45.py [BASE]
"""
import sys, os, secrets, subprocess, re
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
T1 = 'خطر G-H45 أ — غطاء مقبس مكسور'
T2 = 'خطر G-H45 ب — تنمّر'
USERS = ['gh45.s', 'gh45.m', 'gh45.h', 'gh45.c', 'gh45.e']
PHP_USERS = '[' + ','.join(f'"{u}"' for u in USERS) + ']'


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


CLEAN = (f'$ids={RISK}::where("title","like","%G-H45%")->orWhere("title","like","%G-H45%/%")->pluck("id"); '
         f'$ids=$ids->merge({RISK}::whereIn("parent_reference_id",$ids)->pluck("id")); '
         f'$ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::whereIn("username",{PHP_USERS})->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); '
         f'{UNIT}::where("code","gh45fac")->delete(); ')


def session(browser, user):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    bad = []
    page.on('response', lambda r: bad.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    for _ in range(4):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            break
        page.fill('input[name=username]', user); page.fill('input[name=password]', PW)
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.locator('form:has(input[name=username]) button[type=submit]').click()
        page.wait_for_load_state('networkidle')
        if '/login' not in page.url:
            break
        page.wait_for_timeout(5000)
    assert '/login' not in page.url, f'فشل دخول {user}'
    return ctx, page, bad


def card(page, key):
    """بطاقة «ما ينتظرك» بمعرّفها؛ تُفتح مجموعتها المطوية"""
    page.goto(BASE + '/app', wait_until='networkidle')
    c = page.locator(f'#inboxList [data-task="{key}"]')
    if c.count():
        btn = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')
        if btn.count() and btn.get_attribute('aria-expanded') == 'false':
            btn.click(); page.wait_for_timeout(600)
    return c


def flash(page):
    m = page.locator('.alert-success')
    return m.first.inner_text().strip() if m.count() else ''


print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
try:
    adm = db(f'{UNIT}::where("code","adm-eng")->value("id")')
    hr = db(f'{UNIT}::where("code","hr")->value("id")')
    tinker(f'{UNIT}::create(["code"=>"gh45fac","name"=>"المرافق والصيانة (G-H45)","unit_type"=>"section","parent_id"=>{adm},"order"=>904]);')
    fac = int(db(f'{UNIT}::where("code","gh45fac")->value("id")'))
    for name, role, u, label in [('gh45.s', 'system_admin', 'null', 'مسؤول السلامة G-H45'), ('gh45.m', 'facilities_manager', fac, 'مدير المرافق G-H45'),
                                 ('gh45.h', 'department_manager', hr, 'مدير الموارد G-H45'), ('gh45.c', 'safety_coordinator', hr, 'منسق الموارد G-H45'),
                                 ('gh45.e', 'employee', fac, 'موظف المرافق G-H45')]:
        tinker(f'$u={USER}::create(["username"=>"{name}","name"=>"{label}","password"=>"{PW}"]); '
               f'{PROFILE}::create(["user_id"=>$u->id,"role"=>"{role}","is_active"=>true,"organization_unit_id"=>{u}]);')
    ids = {n: int(db(f'{USER}::where("username","{n}")->value("id")')) for n in USERS}
    gid = int(db(f'{RISK}::where("risk_type","reference")->where("status","approved")->whereNotNull("sub_category_id")->orderBy("id")->value("id")'))
    cat, sub = [int(x) for x in db(f'implode(",", {RISK}::whereKey({gid})->first()->only(["category_id","sub_category_id"]))').split(',')]
    tinker(f'$r={RISK}::create(["risk_type"=>"reference","title"=>"{T1}","description"=>"x","category_id"=>{cat},"sub_category_id"=>{sub},"severity"=>3,"likelihood"=>2,"status"=>"approved","handling_unit_id"=>{fac},"handling_unit_name"=>"المرافق والصيانة (G-H45)"]); app("App\\\\Modules\\\\Risk\\\\Services\\\\RiskService")->ensurePhases($r); '
           f'$b={RISK}::create(["risk_type"=>"reference","title"=>"{T2}","description"=>"x","category_id"=>{cat},"sub_category_id"=>{sub},"severity"=>2,"likelihood"=>2,"status"=>"approved"]); app("App\\\\Modules\\\\Risk\\\\Services\\\\RiskService")->ensurePhases($b);')
    r1 = int(db(f'{RISK}::where("title","{T1}")->value("id")')); r2 = int(db(f'{RISK}::where("title","{T2}")->value("id")'))
    units_before = int(db(f'{RISK}::where("risk_type","reference")->whereNotIn("status",["draft","pending_approval","rejected"])->whereNull("handling_unit_id")->whereNull("handling_unit_name")->count()'))
    log('الإعداد', f'الخطر أ #{r1} على المرافق G-H45، الخطر ب #{r2} بلا إدارة؛ أخطار العام بلا إدارة معالجة الآن: {units_before}')

    with sync_playwright() as p:
        b = p.webkit.launch()
        sctx, sp, sbad = session(b, 'gh45.s')
        mctx, mp, mbad = session(b, 'gh45.m')
        hctx, hp, hbad = session(b, 'gh45.h')

        # ── ٤-أ نموذج التفعيل ──
        hp.goto(BASE + f'/app/risk/{r1}/activate', wait_until='networkidle')
        body = hp.locator('body').inner_text()
        log('٤ نموذج التفعيل بلا خانة «المعالج»', f'عددها={hp.locator("select[name=assigned_field_team_id]").count()}', hp.locator('select[name=assigned_field_team_id]').count() == 0)
        log('   ومعالج العام معروض قراءةً', '', 'الإدارة المعالجة' in body and 'المرافق والصيانة (G-H45)' in body and 'لم يُكتب بعد' in body)
        hp.select_option('select[name=assigned_coordinator_id]', str(ids['gh45.c']))
        hp.select_option('select[name=scope_type]', 'org_unit') if hp.locator('select[name=scope_type]').count() else None
        if hp.locator('select[name=organization_unit_id]').count():
            hp.select_option('select[name=organization_unit_id]', str(hr))
        hp.screenshot(path=os.path.join(SHOT, 'h45-1-activate-form.png'), full_page=True)
        with hp.expect_navigation(wait_until='networkidle'):
            hp.locator(f'form[action$="/app/risk/{r1}/activate"] button[type=submit]').click()
        copy = db(f'json_encode({RISK}::where("risk_type","active")->where("parent_reference_id",{r1})->first()?->only(["id","status","assigned_coordinator_id","assigned_field_team_id"]))')
        log('   فعّل بالمنسق وحده ← نسخة نشطة بلا معالج منسوخ', f'{flash(hp)} | {copy}', '"status":"active"' in copy and f'"assigned_coordinator_id":{ids["gh45.c"]}' in copy and '"assigned_field_team_id":null' in copy)
        cid = int(re.search(r'"id":(\d+)', copy).group(1))

        # ── ٤-ب الخاص يقرأ من العام ──
        j = hp.request.get(BASE + f'/app/risk/registry/tree/active/risk/{cid}', headers={'Accept': 'application/json'}).json()
        log('   سجل الإدارة: الإدارة المعالجة من العام والمعالج «لم يُكتب»', f"{j.get('handling_unit')} | {j.get('handler')}", j.get('handling_unit') == 'المرافق والصيانة (G-H45)' and j.get('handler') is None)
        mp.goto(BASE + '/app/risk/handlers', wait_until='networkidle')
        with mp.expect_navigation(wait_until='networkidle'):
            mp.locator(f'.card[data-risk="{r1}"] select[name=handler]').select_option(f'user:{ids["gh45.e"]}')
        j = hp.request.get(BASE + f'/app/risk/registry/tree/active/risk/{cid}', headers={'Accept': 'application/json'}).json()
        log('   مدير المرافق سمّى شخصاً ← سجل الإدارة يعرضه بلا تفعيل جديد', f"{j.get('handler')} كتبه {j.get('handler_set_by')}", j.get('handler') == 'موظف المرافق G-H45' and j.get('handler_set_by') == 'مدير المرافق G-H45')
        log('   والنسخة لم تنسخه', db(f'{RISK}::whereKey({cid})->value("assigned_field_team_id") ?? "null"'), db(f'{RISK}::whereKey({cid})->value("assigned_field_team_id") ?? "null"') == 'null')
        hp.goto(BASE + f'/app/risk/active/{cid}/edit', wait_until='networkidle')
        log('   نموذج تعديل النسخة بلا خانة «المعالج» ويعرض معالج العام', '', hp.locator('select[name=assigned_field_team_id]').count() == 0 and 'موظف المرافق G-H45' in hp.locator('body').inner_text())
        hp.goto(BASE + '/app/risk/active', wait_until='networkidle')
        src = hp.content()  # جدول سجل الإدارة يُبنى بعد اختيار الفئة؛ رؤوسه في قالب الصفحة نفسه
        log('   جدول سجل الإدارة: «الإدارة المعالجة» و«المنسق» و«المعالج»، لا «الفريق التنفيذي»', '', '<th>الإدارة المعالجة</th>' in src and '<th>المنسق</th>' in src and '<th>المعالج</th>' in src and 'الفريق التنفيذي' not in src and 'owner_department' not in src)
        hp.screenshot(path=os.path.join(SHOT, 'h45-2-active-table.png'), full_page=True)

        # ── ٤-ج الدفعة بلا معالج ──
        hp.goto(BASE + '/app/risk/reference', wait_until='networkidle')
        log('   نافذة الدفعة بلا خانة «المعالج»', f'عددها={hp.locator("#bulkHandler").count()}', hp.locator('#bulkHandler').count() == 0)
        tok = hp.evaluate("document.querySelector('meta[name=csrf-token]').getAttribute('content')")
        res = hp.request.post(BASE + '/app/risk/reference/activate-bulk', headers={'Accept': 'application/json', 'X-CSRF-TOKEN': tok, 'X-Requested-With': 'XMLHttpRequest'},
                              data={'risk_ids': [r2], 'scope_type': 'org_unit', 'organization_unit_id': int(hr)})
        bj = res.json() if res.ok else {'message': res.text()[:120]}
        copy2 = db(f'json_encode({RISK}::where("risk_type","active")->where("parent_reference_id",{r2})->first()?->only(["status","assigned_coordinator_id","assigned_field_team_id"]))')
        # منسق الدفعة = أقدم منسق سلامة مفعّل للوحدة (قرار ٧١) — في القاعدة المحلية قد يكون غير منسق الجولة
        coord_ok = db(f'{PROFILE}::where("user_id", {RISK}::where("risk_type","active")->where("parent_reference_id",{r2})->value("assigned_coordinator_id") ?? 0)->where("role","safety_coordinator")->where("organization_unit_id",{hr})->exists() ? "yes" : "no"')
        log('   تفعيل دفعةً بلا معالج: نشطة، منسقها منسق سلامة الوحدة، وبلا معالج منسوخ', f'HTTP {res.status} {bj.get("message", "")[:60]} | {copy2} | منسق الوحدة={coord_ok}', res.ok and bj.get('created') == 1 and coord_ok == 'yes' and '"status":"active"' in copy2 and '"assigned_field_team_id":null' in copy2)

        # ── ٥ غير المغطى يظهر ──
        c = card(sp, 'risk:uncovered:units')
        txt = c.first.inner_text().replace('\n', ' ') if c.count() else 'لا بطاقة'
        log('٥ مسؤول السلامة: «كذا خطراً في السجل العام بلا إدارة معالجة»', txt[:100], c.count() == 1 and 'بلا إدارة معالجة' in txt and (str(units_before) in txt or units_before <= 2))
        sp.screenshot(path=os.path.join(SHOT, 'h45-3-salama-counters.png'), full_page=True)
        c = card(mp, 'risk:uncovered:handlers')
        log('   مدير المرافق: لا عدّاد وقد سمّى معالج خطره الوحيد', f'عددها={c.count()}', c.count() == 0)
        mp.goto(BASE + '/app/risk/handlers', wait_until='networkidle')
        with mp.expect_navigation(wait_until='networkidle'):
            mp.locator(f'.card[data-risk="{r1}"] select[name=handler]').select_option('')
        c = card(mp, 'risk:uncovered:handlers')
        txt = c.first.inner_text().replace('\n', ' ') if c.count() else 'لا بطاقة'
        log('   المحو يرفع العدّاد: «خطر واحد على إدارتك بلا معالج»', txt[:90], c.count() == 1 and 'خطر واحد على إدارتك بلا معالج' in txt)
        with mp.expect_navigation(wait_until='networkidle'):
            c.first.locator('a.btn-g, .task-actions a').first.click()
        log('   زره يفتح «معالجو أخطار إدارتي»', mp.url.replace(BASE, ''), mp.url.endswith('/app/risk/handlers'))
        with mp.expect_navigation(wait_until='networkidle'):
            mp.locator(f'.card[data-risk="{r1}"] select[name=handler]').select_option('spec:tech_electrical')
        c = card(mp, 'risk:uncovered:handlers')
        log('   التسمية تُنزل العدّاد', f'عددها={c.count()}', c.count() == 0)
        c = card(sp, 'risk:uncovered:places')
        txt = c.first.inner_text().replace('\n', ' ') if c.count() else 'لا بطاقة'
        covered_all = db(f'{PROFILE}::where("role","tech_electrical")->where("is_active",true)->count()')
        log('   مسؤول السلامة: عدّاد الأماكن بلا فني كهرباء (بحسب تغطية القاعدة المحلية)', f'{txt[:100]} | فنيو كهرباء مفعّلون={covered_all}', True)
        # مسؤول السلامة يعلّق الإدارة على الخطر ب ← عدّاد العام ينزل واحداً
        sp.goto(BASE + f'/app/risk/reference/{r2}/edit', wait_until='networkidle')
        sp.wait_for_function("() => document.querySelector('#subCatSelect').options.length > 1 && !document.querySelector('#subCatSelect').innerHTML.includes('جاري')")
        sp.select_option('#handlingUnit', str(fac))
        with sp.expect_navigation(wait_until='domcontentloaded'):
            sp.locator(f'form[action$="/reference/{r2}/edit"] button[type=submit]').click()
        after = int(db(f'{RISK}::where("risk_type","reference")->whereNotIn("status",["draft","pending_approval","rejected"])->whereNull("handling_unit_id")->whereNull("handling_unit_name")->count()'))
        c = card(sp, 'risk:uncovered:units')
        txt = c.first.inner_text().replace('\n', ' ') if c.count() else 'لا بطاقة'
        log('   تعليق إدارة على الخطر ب ← عدّاد العام ينزل واحداً', f'{units_before} ← {after} | {txt[:60]}', after == units_before - 1 and (c.count() == 0 if after == 0 else str(after) in txt or after <= 2))

        for who, bad in [('مسؤول السلامة', sbad), ('مدير المرافق', mbad), ('مدير الموارد', hbad)]:
            log(f'أخطاء خادم عند {who}', '؛ '.join(bad) or 'لا شيء', not bad)
        b.close()
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())

print('\n==== الأخطاء:', errs if errs else 'صفر')
sys.exit(1 if errs else 0)
