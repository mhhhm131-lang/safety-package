# -*- coding: utf-8 -*-
"""بوابة خطة المعالج — الخطوة ١ (٢٠٢٦-١٠-٠٨) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

الخطوة ١: خانتا «الإدارة المعالجة» و«المعالج» في العام.
  مسؤول السلامة: نموذج الإضافة فيه «الإدارة المعالجة» من قائمة الهيكل ← يحفظ خطراً عليها «المرافق والصيانة» ←
  في تفاصيل الخطر عمودا «الإدارة المعالجة» و«المعالج» (لم يُكتب بعد) و«المنسق»، ولا «الفريق التنفيذي» ←
  مدير المرافق: زر «معالجو أخطار إدارتي» في «أريد أن» ← الشاشة تعرض الخطر ← يختار «فني الكهرباء» فيُحفظ بنفسه ←
  يختار شخصاً من إدارته ← مدير إدارة أخرى لا يرى الخطر في شاشته ولا يفتح نموذج العام ←
  مسؤول السلامة ينقل الإدارة المعالجة إلى «الأمن والسلامة» ← المعالج السابق يُمحى ← وشاشة الهيكل ترفض حذف «الأمن والسلامة» وتسمّي الخطر.
الحالة تُقرأ من القاعدة بعد كل ضغطة. وحدتان وأربعة حسابات وخطر واحد (وسمها G-H1) تُنشأ للجولة وتُحذف بعدها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-h1.py [BASE]
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
TITLE = 'خطر G-H1 — غطاء مقبس مكسور في القاعة'
MINE = f'{RISK}::where("title","like","%G-H1%")'
HROW = '["handling_unit_id","handling_unit_name","handler_specialty","handler_user_id","handler_set_by_id"]'


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


def hrow(rid):
    return db(f'json_encode({RISK}::whereKey({rid})->first()?->only({HROW}), JSON_UNESCAPED_UNICODE)')


CLEAN = (f'$ids={MINE}->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::whereIn("username",["gh1.s","gh1.m","gh1.t","gh1.h"])->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); '
         f'{UNIT}::whereIn("code",["gh1fac","gh1sec"])->delete(); ')


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


def open_risk(page, cat_id, sub_id, risk_id):
    page.goto(BASE + '/app/risk/reference', wait_until='networkidle')
    page.wait_for_selector(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"]')
    with page.expect_response(lambda r: f'/sub-categories/{cat_id}' in r.url):
        page.locator(f'#ref-categories-list .cat-nav-item[data-id="{cat_id}"] span').click()
    page.wait_for_timeout(400)
    with page.expect_response(lambda r: f'/risks-by-sub-category/{sub_id}' in r.url):
        page.locator(f'#ref-sc-{sub_id}').check()
    page.wait_for_timeout(500)
    item = page.locator(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"]')
    assert item.count() == 1, f'الخطر {risk_id} ليس في الشجرة'
    item.locator('.risk-title').click()
    page.wait_for_selector('#ref-detail-panel-body table')
    page.wait_for_timeout(400)
    return page.locator('#ref-detail-panel-body table')


def flash(page):
    m = page.locator('.alert-success')
    return m.first.inner_text().strip() if m.count() else ''


def status_of(page, url):
    return page.request.get(BASE + url).status


print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
try:
    adm = db(f'{UNIT}::where("code","adm-eng")->value("id")')
    hr = db(f'{UNIT}::where("code","hr")->value("id")')
    tinker(f'{UNIT}::create(["code"=>"gh1fac","name"=>"المرافق والصيانة (G-H1)","unit_type"=>"section","parent_id"=>{adm},"order"=>901]); '
           f'{UNIT}::create(["code"=>"gh1sec","name"=>"الأمن والسلامة (G-H1)","unit_type"=>"section","parent_id"=>{adm},"order"=>902]);')
    fac = int(db(f'{UNIT}::where("code","gh1fac")->value("id")'))
    sec = int(db(f'{UNIT}::where("code","gh1sec")->value("id")'))
    for name, role, u, label in [('gh1.s', 'system_admin', 'null', 'مسؤول السلامة'), ('gh1.m', 'facilities_manager', fac, 'مدير المرافق G-H1'),
                                 ('gh1.t', 'tech_electrical', fac, 'فني الكهرباء G-H1'), ('gh1.h', 'department_manager', hr, 'مدير الموارد G-H1')]:
        tinker(f'$u={USER}::create(["username"=>"{name}","name"=>"{label}","password"=>"{PW}"]); '
               f'{PROFILE}::create(["user_id"=>$u->id,"role"=>"{role}","is_active"=>true,"organization_unit_id"=>{u}]);')
    tech_id = int(db(f'{USER}::where("username","gh1.t")->value("id")'))
    marafiq_id = int(db(f'{USER}::where("username","gh1.m")->value("id")'))
    # فئة وفرعية من كتاب المعهد (تُقرأ فقط)
    gid = int(db(f'{RISK}::where("risk_type","reference")->where("status","approved")->whereNotNull("sub_category_id")->orderBy("id")->value("id")'))
    cat, sub = [int(x) for x in db(f'implode(",", {RISK}::whereKey({gid})->first()->only(["category_id","sub_category_id"]))').split(',')]

    with sync_playwright() as p:
        b = p.webkit.launch()
        sctx, sp, sbad = session(b, 'gh1.s')   # مسؤول السلامة
        mctx, mp, mbad = session(b, 'gh1.m')   # مدير المرافق
        hctx, hp, hbad = session(b, 'gh1.h')   # مدير الموارد البشرية

        # ── ١ مسؤول السلامة يضيف خطراً وعليه «الإدارة المعالجة» ──
        sp.goto(BASE + '/app/risk/reference/create', wait_until='networkidle')
        sel = sp.locator('#handlingUnit')
        log('مسؤول السلامة: نموذج الإضافة فيه «الإدارة المعالجة»', f'عددها={sel.count()}', sel.count() == 1)
        log('مسؤول السلامة: لا خانات «الجهة» لكل طور', f'عددها={sp.locator("[name*=responsible_org_unit_id]").count()}', sp.locator('[name*=responsible_org_unit_id]').count() == 0)
        sp.select_option('#catSelect', str(cat))
        sp.wait_for_selector(f'#subCatSelect option[value="{sub}"]', state='attached')
        sp.select_option('#subCatSelect', str(sub))
        sp.fill('input[name=title]', TITLE)
        sp.select_option('#sevSelect', '3'); sp.select_option('#likSelect', '2')
        sp.select_option('#handlingUnit', str(fac))
        sp.screenshot(path=os.path.join(SHOT, 'h1-1-salama-form.png'), full_page=True)
        log('نموذج الإضافة بلا خانة «نوع الخطر»', f'عددها={sp.locator("#riskTypeSelect").count()}', sp.locator('#riskTypeSelect').count() == 0)
        sp.wait_for_function("() => !document.getElementById('subCatSelect').innerHTML.includes('جاري')")
        with sp.expect_navigation(wait_until='domcontentloaded') as nav:
            sp.locator('form[action$="/app/risk/reference/create"] button[type=submit]').click()
        rid = db(f'{MINE}->value("id")')
        log('مسؤول السلامة: «حفظ» ← الخطر في العام', f'HTTP {nav.value.status} #{rid} | {flash(sp)}', rid.isdigit() and 'أُضيف الخطر' in flash(sp))
        rid = int(rid)
        row = hrow(rid)
        log('القاعدة: الإدارة المعالجة باسمها، والمعالج فارغ', row, f'"handling_unit_id":{fac}' in row and 'المرافق والصيانة (G-H1)' in row and '"handler_specialty":null' in row)

        # ── ٢ تفاصيل الخطر: الأعمدة الجديدة ──
        tbl = open_risk(sp, cat, sub, rid)
        heads = [h.strip() for h in tbl.locator('thead th').all_inner_texts()]
        log('تفاصيل الخطر: عمودا «الإدارة المعالجة» و«المعالج» و«المنسق»', ' | '.join(heads), all(h in heads for h in ['الإدارة المعالجة', 'المعالج', 'المنسق', 'الإدارة']))
        log('تفاصيل الخطر: لا «الفريق التنفيذي» ولا «المسؤول»', '', 'الفريق التنفيذي' not in heads and 'المسؤول' not in heads)
        body = tbl.locator('tbody').inner_text()
        log('تفاصيل الخطر: الإدارة المعالجة ظاهرة والمعالج «لم يُكتب بعد»', '', 'المرافق والصيانة (G-H1)' in body and 'لم يُكتب بعد' in body)
        sp.screenshot(path=os.path.join(SHOT, 'h1-2-salama-detail.png'), full_page=True)

        # ── ٣ مدير المرافق: الباب والشاشة والاختيار ──
        mp.goto(BASE + '/app', wait_until='networkidle')
        door = mp.locator('a[data-intent="handlers"]')
        log('مدير المرافق: زر «معالجو أخطار إدارتي» في «أريد أن»', door.first.inner_text().strip() if door.count() else 'لا زر', door.count() == 1)
        with mp.expect_navigation(wait_until='networkidle'):
            door.first.click()
        log('الزر يفتح الشاشة', mp.url.replace(BASE, ''), mp.url.endswith('/app/risk/handlers'))
        rowm = mp.locator(f'.card[data-risk="{rid}"]')
        log('مدير المرافق: الخطر في شاشته ببطاقة صفراء (بلا معالج)', f'عدده={rowm.count()}', rowm.count() == 1 and 'border-warning' in (rowm.first.get_attribute('class') or ''))
        log('مدير المرافق: العدّاد «1 بلا معالج»', '', '1 بلا معالج' in mp.locator('body').inner_text())
        mp.screenshot(path=os.path.join(SHOT, 'h1-3-marafiq-screen.png'), full_page=True)
        with mp.expect_navigation(wait_until='networkidle'):
            rowm.first.locator('select[name=handler]').select_option('spec:tech_electrical')  # الاختيار يُحفظ بنفسه
        row = hrow(rid)
        log('مدير المرافق اختار «فني الكهرباء» ← حُفظ بنفسه', f'{flash(mp)} | {row}', '"handler_specialty":"tech_electrical"' in row and f'"handler_set_by_id":{marafiq_id}' in row and 'يعالجه فني الكهرباء' in flash(mp))
        rowm = mp.locator(f'.card[data-risk="{rid}"]')
        log('البطاقة لم تعد صفراء، وفيها «كتبه مدير المرافق»', '', 'border-warning' not in (rowm.first.get_attribute('class') or '') and 'كتبه مدير المرافق G-H1' in rowm.first.inner_text())
        log('رسالة الحفظ مرة واحدة لا مرتين', f'عددها={mp.locator(".alert-success").count()}', mp.locator('.alert-success').count() == 1)
        mp.screenshot(path=os.path.join(SHOT, 'h1-4-marafiq-saved.png'), full_page=True)
        opts = rowm.first.locator('select[name=handler] option').all_inner_texts()
        log('قائمة الأشخاص: فنيّه فيها ومدير الموارد ليس فيها', f'{len(opts)} خياراً', any('فني الكهرباء G-H1' in o for o in opts) and not any('مدير الموارد' in o for o in opts))
        with mp.expect_navigation(wait_until='networkidle'):
            rowm.first.locator('select[name=handler]').select_option(f'user:{tech_id}')
        row = hrow(rid)
        log('مدير المرافق اختار شخصاً من إدارته', row, f'"handler_user_id":{tech_id}' in row and '"handler_specialty":null' in row)
        tbl = open_risk(sp, cat, sub, rid)
        log('مسؤول السلامة يرى المعالج في تفاصيل الخطر مع من كتبه', '', 'فني الكهرباء G-H1' in tbl.inner_text() and 'كتبه مدير المرافق G-H1' in tbl.inner_text())

        # ── ٤ مدير إدارة أخرى ──
        hp.goto(BASE + '/app/risk/handlers', wait_until='networkidle')
        log('مدير الموارد: شاشته بلا هذا الخطر', '', hp.locator(f'.card[data-risk="{rid}"]').count() == 0 and 'لا أخطار معلَّقة' in hp.locator('body').inner_text())
        st = status_of(hp, f'/app/risk/reference/{rid}/edit')
        log('مدير الموارد: نموذج العام مغلق عليه', f'HTTP {st}', st == 403)

        # ── ٥ مسؤول السلامة ينقل الإدارة المعالجة: المعالج السابق يُمحى ──
        sp.goto(BASE + f'/app/risk/reference/{rid}/edit', wait_until='networkidle')
        log('نموذج التعديل: الإدارة المعالجة مختارة، والمعالج معروضاً قراءةً', '', sp.locator('#handlingUnit').input_value() == str(fac) and 'فني الكهرباء G-H1' in sp.locator('body').inner_text())
        log('نموذج التعديل: نص الكتاب «من يطبّق الضوابط» موضعه باقٍ بلا خانات', f'خانات={sp.locator("[name*=responsible_]").count()}', sp.locator('[name*=responsible_]').count() == 0)
        log('نموذج التعديل بلا خانة «نوع الخطر»، والفرعية محمّلة ومختارة', sp.locator('#subCatSelect').input_value(), sp.locator('#riskTypeSelect').count() == 0 and sp.locator('#subCatSelect').input_value() == str(sub))
        sp.select_option('#handlingUnit', str(sec))
        sp.wait_for_function("() => !document.getElementById('subCatSelect').innerHTML.includes('جاري')")
        with sp.expect_navigation(wait_until='domcontentloaded'):
            sp.locator(f'form[action$="/reference/{rid}/edit"] button[type=submit]').click()
        row = hrow(rid)
        log('نُقلت إلى «الأمن والسلامة» ← المعالج السابق مُحي', row, f'"handling_unit_id":{sec}' in row and '"handler_user_id":null' in row and '"handler_specialty":null' in row)
        mp.goto(BASE + '/app/risk/handlers', wait_until='networkidle')
        log('مدير المرافق: الخطر خرج من شاشته', '', mp.locator(f'.card[data-risk="{rid}"]').count() == 0)

        # ── ٦ الهيكل: الوحدة المربوطة بخطر لا تُحذف ──
        sp.goto(BASE + '/app/org', wait_until='networkidle')
        sp.on('dialog', lambda d: d.accept())
        form = sp.locator(f'form[action$="/app/org/{sec}"]')
        log('شاشة الهيكل: زر حذف «الأمن والسلامة»', f'عدده={form.count()}', form.count() == 1)
        with sp.expect_navigation(wait_until='networkidle'):
            form.first.locator('button').click()
        err = sp.locator('.alert-danger, .alert-warning').first.inner_text().strip() if sp.locator('.alert-danger, .alert-warning').count() else ''
        still = db(f'{UNIT}::whereKey({sec})->exists() ? "yes" : "no"')
        log('الحذف رُفض وسمّى الخطر، والوحدة باقية', f'{err[:120]} | باقية={still}', 'G-H1' in err and still == 'yes')
        sp.screenshot(path=os.path.join(SHOT, 'h1-5-org-refused.png'), full_page=True)

        for who, bad in [('مسؤول السلامة', sbad), ('مدير المرافق', mbad), ('مدير الموارد', hbad)]:
            log(f'أخطاء خادم عند {who}', '؛ '.join(bad) or 'لا شيء', not bad)
        b.close()
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())

print('\n==== الأخطاء:', errs if errs else 'صفر')
sys.exit(1 if errs else 0)
