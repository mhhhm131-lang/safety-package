# -*- coding: utf-8 -*-
"""بوابة خطة المعالج — الخطوة ٣ (٢٠٢٦-١٠-٠٨) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

التوجيه يقرأ المعالج من السجل العام. خمسة بلاغات من نموذج البلاغ العادي بحساب موظف، كل واحد ينتهي عند سطر من جدول الخطة:
  ١ شخص مسمّى على الخطر                 ← هو، والبلاغ «حُوّل للمعالج» بلا تفعيل من إدارة المبلّغ
  ٢ تخصص وفني يغطي مكان البلاغ           ← ذلك الفني (لا فني مكان آخر)
  ٣ تخصص بلا فني يغطي المكان             ← مدير الإدارة المعالجة
  ٤ إدارة بلا تخصص                        ← مديرها
  ٥ لا إدارة معالجة في العام              ← المركز، وبطاقته تقول «هذا الخطر بلا إدارة معالجة في السجل العام — أحله»
ثم: مدير المرافق يسمّي شخصاً من «إدارتي» ← البلاغ السادس يصل إليه فوراً (العام يُقرأ عند كل بلاغ).
الحالة تُقرأ من القاعدة بعد كل بلاغ. وحدة وخمسة حسابات وخطران (وسمها G-H3) تُنشأ للجولة وتُحذف بعدها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-h3.py [BASE]
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
PLACE = 'App\\Modules\\Governance\\Models\\Place'
INC = 'App\\Modules\\Incident\\Models\\Incident'
DBF = 'Illuminate\\Support\\Facades\\DB'
T1 = 'خطر G-H3 أ — غطاء مقبس مكسور'
T2 = 'خطر G-H3 ب — تنمّر'
IROW = '["id","code","status","incident_field_team_id","incident_coordinator_id","center_reason"]'


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


def last_incident():
    return db(f'json_encode({INC}::where("description","like","%G-H3%")->orderByDesc("id")->first()?->only({IROW}), JSON_UNESCAPED_UNICODE)')


def create_note():
    return db(f'{DBF}::table("incident_events")->where("incident_id", {INC}::where("description","like","%G-H3%")->orderByDesc("id")->value("id"))->where("action","create")->value("note")')


USERS = ['gh3.s', 'gh3.m', 'gh3.t7', 'gh3.t6', 'gh3.e', 'gh3.h']
PHP_USERS = '[' + ','.join(f'"{u}"' for u in USERS) + ']'
CLEAN = (f'$inc={INC}::where("description","like","%G-H3%")->pluck("id"); '
         f'foreach(["incident_events","incident_attachments","incident_risks"] as $t) {DBF}::table($t)->whereIn("incident_id",$inc)->delete(); '
         f'{INC}::whereIn("id",$inc)->delete(); '
         f'$ids={RISK}::where("title","like","%G-H3%")->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::whereIn("username",{PHP_USERS})->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); {DBF}::table("place_coverages")->whereIn("user_profile_id", {PROFILE}::whereIn("user_id",$us)->pluck("id"))->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); '
         f'{UNIT}::where("code","gh3fac")->delete(); ')


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


def report(page, risk_id, place_code, text):
    """نموذج البلاغ العادي بحساب الموظف: الخطر محدد سلفاً بالرابط، المكان من القائمة، ثم «إرسال البلاغ»"""
    page.goto(BASE + f'/incident/normal?risk={risk_id}&place={place_code}', wait_until='networkidle')
    assert page.locator(f'input[name="risk_id"][value="{risk_id}"]').count() == 1, 'الخطر لم يُحدَّد في النموذج'
    pid = db(f'{PLACE}::idByCode("{place_code}")')
    page.select_option('select[name=place_id]', str(pid))
    page.fill('textarea[name=description]', text)
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        page.locator('button:has-text("إرسال البلاغ")').click()
    page.wait_for_load_state('networkidle')
    if '/incident/success' not in page.url:  # رُدّ النموذج (خطأ تحقق أو حدّ الإرسال ١٠/ساعة): يُقال لا يُسكت عنه
        err = ' / '.join(x.strip() for x in page.locator('.alert-danger, .invalid-feedback').all_inner_texts())[:200]
        log('   البلاغ لم يُرسل', f'HTTP {nav.value.status} {page.url.replace(BASE, "")} | {err or page.locator("body").inner_text()[:120]}', False)
    return nav.value.status, page.url.replace(BASE, '')


print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
# حدّ الإرسال ١٠ بلاغات في الساعة لكل عنوان (throttle:incident-public): الجولة ترسل سبعة، فيُصفّر عدّاد المحلي قبلها
tinker('Illuminate\\Support\\Facades\\Cache::flush();')
try:
    adm = db(f'{UNIT}::where("code","adm-eng")->value("id")')
    fin = db(f'{UNIT}::where("code","fin")->value("id")')
    tinker(f'{UNIT}::create(["code"=>"gh3fac","name"=>"المرافق والصيانة (G-H3)","unit_type"=>"section","parent_id"=>{adm},"order"=>903]);')
    fac = int(db(f'{UNIT}::where("code","gh3fac")->value("id")'))
    hz7 = db(f'{PLACE}::idByCode("HZ-07")'); hz6 = db(f'{PLACE}::idByCode("HZ-06")')
    # القاعدة المحلية ممتلئة بفنيين يغطون كل الأماكن: فنيّو تخصص الجولة الآخرون يُعطَّلون مدة الجولة ويُعادون في النهاية،
    # حتى يكون فنيّا الجولة وحدهما من يغطي (المصاعد: أقلّ التخصصات حسابات في بذرة التجربة)
    SPEC = 'tech_elevator'
    SPEC_LABEL = db(f'App\\Core\\Permissions\\PermissionRegistry::ROLES["{SPEC}"]')
    paused = db(f'json_encode({PROFILE}::where("role","{SPEC}")->where("is_active",true)->pluck("id"))')
    tinker(f'{PROFILE}::whereIn("id",{paused})->update(["is_active"=>false]);')
    log('فنيو التخصص الآخرون عُطّلوا مؤقتاً', paused)
    for name, role, u, label, place in [('gh3.s', 'system_admin', 'null', 'مسؤول السلامة G-H3', 'null'),
                                        ('gh3.m', 'facilities_manager', fac, 'مدير المرافق G-H3', 'null'),
                                        ('gh3.t7', SPEC, fac, 'فني القاعات G-H3', 'null'),
                                        ('gh3.t6', SPEC, fac, 'فني المكاتب G-H3', hz6),
                                        ('gh3.e', 'employee', fin, 'موظف المالية G-H3', 'null'),
                                        ('gh3.h', 'employee', fac, 'موظف المرافق G-H3', 'null')]:
        tinker(f'$u={USER}::create(["username"=>"{name}","name"=>"{label}","password"=>"{PW}"]); '
               f'{PROFILE}::create(["user_id"=>$u->id,"role"=>"{role}","is_active"=>true,"organization_unit_id"=>{u},"place_id"=>{place}]);')
    # كهربائي القاعات يغطي القاعات (HZ-07) تغطيةً؛ كهربائي المكاتب مكان حسابه المكاتب بلا تغطية
    tinker(f'{PROFILE}::where("user_id",{USER}::where("username","gh3.t7")->value("id"))->first()->coverage()->sync([{hz7}]);')
    ids = {n: int(db(f'{USER}::where("username","{n}")->value("id")')) for n in USERS}
    # خطران في العام: أ بإدارة معالجة (المرافق G-H3)، ب بلا إدارة معالجة
    gid = int(db(f'{RISK}::where("risk_type","reference")->where("status","approved")->whereNotNull("sub_category_id")->orderBy("id")->value("id")'))
    cat, sub = [int(x) for x in db(f'implode(",", {RISK}::whereKey({gid})->first()->only(["category_id","sub_category_id"]))').split(',')]
    tinker(f'$r={RISK}::create(["risk_type"=>"reference","title"=>"{T1}","description"=>"x","category_id"=>{cat},"sub_category_id"=>{sub},"severity"=>3,"likelihood"=>2,"status"=>"approved","handling_unit_id"=>{fac},"handling_unit_name"=>"المرافق والصيانة (G-H3)"]); app("App\\\\Modules\\\\Risk\\\\Services\\\\RiskService")->ensurePhases($r); '
           f'$b={RISK}::create(["risk_type"=>"reference","title"=>"{T2}","description"=>"x","category_id"=>{cat},"sub_category_id"=>{sub},"severity"=>2,"likelihood"=>2,"status"=>"approved"]); app("App\\\\Modules\\\\Risk\\\\Services\\\\RiskService")->ensurePhases($b);')
    r1 = int(db(f'{RISK}::where("title","{T1}")->value("id")')); r2 = int(db(f'{RISK}::where("title","{T2}")->value("id")'))
    log('الإعداد', f'الخطر أ #{r1} (الإدارة المعالجة المرافق G-H3)، الخطر ب #{r2} (بلا إدارة معالجة)، تخصص الجولة {SPEC_LABEL}')

    with sync_playwright() as p:
        b = p.webkit.launch()
        ectx, ep, ebad = session(b, 'gh3.e')   # موظف المالية يبلّغ
        sctx, sp, sbad = session(b, 'gh3.s')   # مسؤول السلامة (المركز)
        mctx, mp, mbad = session(b, 'gh3.m')   # مدير المرافق

        # ── ١ شخص مسمّى ──
        tinker(f'{RISK}::whereKey({r1})->update(["handler_user_id"=>{ids["gh3.h"]},"handler_specialty"=>null,"handler_set_by_id"=>{ids["gh3.m"]},"handler_set_at"=>now()]);')
        st, url = report(ep, r1, 'HZ-06', 'بلاغ G-H3 ١ — شخص مسمّى')
        row = last_incident()
        log('١ شخص مسمّى على الخطر ← هو، «حُوّل للمعالج» بلا تفعيل', f'HTTP {st} {url} | {row}', f'"incident_field_team_id":{ids["gh3.h"]}' in row and '"status":"forwarded"' in row and '"center_reason":null' in row)
        log('   الخط الزمني يقول من أين جاء المعالج', create_note()[:90], 'المعالج المسمّى على الخطر' in create_note())

        # ── ٢ تخصص وفني يغطي المكان ──
        tinker(f'{RISK}::whereKey({r1})->update(["handler_user_id"=>null,"handler_specialty"=>"{SPEC}"]);')
        st, url = report(ep, r1, 'HZ-07', 'بلاغ G-H3 ٢ — تخصص في القاعات')
        row = last_incident()
        log(f'٢ تخصص ← {SPEC_LABEL} الذي يغطي القاعات، لا فني المكاتب', row, f'"incident_field_team_id":{ids["gh3.t7"]}' in row and '"status":"forwarded"' in row)
        st, url = report(ep, r1, 'HZ-06', 'بلاغ G-H3 ٢ب — تخصص في المكاتب')
        row = last_incident()
        log('   وفي المكاتب ← فني المكاتب', row, f'"incident_field_team_id":{ids["gh3.t6"]}' in row)

        # ── ٣ تخصص بلا فني يغطي المكان ──
        st, url = report(ep, r1, 'HZ-08', 'بلاغ G-H3 ٣ — تخصص بلا فني في المطاعم')
        row = last_incident()
        log('٣ تخصص بلا فني يغطي المكان ← مدير الإدارة المعالجة', row, f'"incident_field_team_id":{ids["gh3.m"]}' in row and '"status":"forwarded"' in row)
        log('   الخط الزمني يقول السبب', create_note()[:120], f'لا {SPEC_LABEL} يغطي مكان البلاغ' in create_note())

        # ── ٤ إدارة بلا تخصص ──
        tinker(f'{RISK}::whereKey({r1})->update(["handler_specialty"=>null]);')
        st, url = report(ep, r1, 'HZ-06', 'بلاغ G-H3 ٤ — إدارة بلا تخصص')
        row = last_incident()
        log('٤ إدارة بلا تخصص ← مديرها', row, f'"incident_field_team_id":{ids["gh3.m"]}' in row)
        mp.goto(BASE + '/app', wait_until='networkidle')
        card = mp.locator(f'#inboxList [data-task^="incident:"][data-task$=":field"]')
        log('   مدير المرافق يرى بلاغاته في «ما ينتظرك»', f'عددها={card.count()}', card.count() >= 2)

        # ── ٥ لا إدارة معالجة ──
        st, url = report(ep, r2, 'HZ-06', 'بلاغ G-H3 ٥ — خطر بلا إدارة معالجة')
        row = last_incident()
        log('٥ لا إدارة معالجة ← المركز بعلّته', row, '"incident_field_team_id":null' in row and '"status":"received"' in row and 'بلا إدارة معالجة في السجل العام' in row)
        iid = re.search(r'"id":(\d+)', row).group(1)
        sp.goto(BASE + '/app', wait_until='networkidle')
        c = sp.locator(f'#inboxList [data-task="incident:{iid}:center"]')
        if c.count():
            btn = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')
            if btn.count() and btn.get_attribute('aria-expanded') == 'false':
                btn.click(); sp.wait_for_timeout(600)
            if not c.first.is_visible():
                batch = c.first.locator('xpath=ancestor::*[@data-batch][1]')
                batch.locator('.task-actions > button[data-bs-toggle=collapse]').first.click(); sp.wait_for_timeout(700)
        txt = c.first.inner_text().replace('\n', ' ') if c.count() else 'لا بطاقة'
        log('   بطاقة المركز تقول العلّة', txt[:120], 'بلا إدارة معالجة في السجل العام' in txt and 'أحله' in txt)
        sp.screenshot(path=os.path.join(SHOT, 'h3-5-center-card.png'), full_page=True)

        # ── ٦ العام يُقرأ عند كل بلاغ: مدير المرافق يسمّي شخصاً من «إدارتي» ──
        mp.goto(BASE + '/app/risk/handlers', wait_until='networkidle')
        rc = mp.locator(f'.card[data-risk="{r1}"]')
        log('مدير المرافق: الخطر أ في شاشته', f'عدده={rc.count()}', rc.count() == 1)
        with mp.expect_navigation(wait_until='networkidle'):
            rc.first.locator('select[name=handler]').select_option(f'user:{ids["gh3.h"]}')
        st, url = report(ep, r1, 'HZ-06', 'بلاغ G-H3 ٦ — بعد تسمية شخص')
        row = last_incident()
        log('٦ بعد التسمية من «إدارتي» ← البلاغ التالي إلى الشخص فوراً', row, f'"incident_field_team_id":{ids["gh3.h"]}' in row and '"status":"forwarded"' in row)

        for who, bad in [('الموظف', ebad), ('مسؤول السلامة', sbad), ('مدير المرافق', mbad)]:
            log(f'أخطاء خادم عند {who}', '؛ '.join(bad) or 'لا شيء', not bad)
        b.close()
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
    try:
        if paused:
            print('أُعيد تفعيلهم:', db(f'{PROFILE}::whereIn("id",{paused})->update(["is_active"=>true])'))
    except NameError:
        pass

print('\n==== الأخطاء:', errs if errs else 'صفر')
sys.exit(1 if errs else 0)
