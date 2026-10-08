# -*- coding: utf-8 -*-
"""بوابة خطة المعالج — الخطوة ٦ (٢٠٢٦-١٠-٠٨) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

السجل بلا أطوار: الخطر صف واحد.
  مسؤول السلامة يفتح FI-06-01 في العام ← جدول التفاصيل صف واحد بلا عمود «الطور»، أسبابه ١٢ سطراً، ووقائيه نص واحد بترقيم متصل حتى (٢٠) ←
  نموذج التعديل لسان واحد بلا «استباقي/تشغيلي/استجابة» ← يحفظ تعديلاً فتبقى الخانات واحدة ← مدير إدارة يفتح نموذج التفعيل: لسان واحد ←
  الموظف يرسل بلاغاً عادياً على الخطر ← البلاغ يحمل التصحيحي الواحد ← صفحة البلاغ تعرض «الإجراءات من السجل العام» بنصه كله.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-h6.py [BASE]
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
INC = 'App\\Modules\\Incident\\Models\\Incident'
DBF = 'Illuminate\\Support\\Facades\\DB'
USERS = ['gh6.s', 'gh6.h', 'gh6.e']
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


CLEAN = (f'$inc={INC}::where("description","like","%G-H6%")->pluck("id"); '
         f'foreach(["incident_events","incident_attachments","incident_risks"] as $t) {DBF}::table($t)->whereIn("incident_id",$inc)->delete(); {INC}::whereIn("id",$inc)->delete(); '
         f'$us={USER}::whereIn("username",{PHP_USERS})->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); {PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); ')


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
    page.locator(f'#ref-risks-tree .risk-item[data-risk-id="{risk_id}"] .risk-title').click()
    page.wait_for_selector('#ref-detail-panel-body table')
    page.wait_for_timeout(400)
    return page.locator('#ref-detail-panel-body table')


print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())
tinker('Illuminate\\Support\\Facades\\Cache::flush();')  # حدّ الإرسال ١٠/ساعة
try:
    hr = db(f'{UNIT}::where("code","hr")->value("id")')
    for name, role, u, label in [('gh6.s', 'system_admin', 'null', 'مسؤول السلامة G-H6'), ('gh6.h', 'department_manager', hr, 'مدير الموارد G-H6'), ('gh6.e', 'employee', hr, 'موظف الموارد G-H6')]:
        tinker(f'$u={USER}::create(["username"=>"{name}","name"=>"{label}","password"=>"{PW}"]); '
               f'{PROFILE}::create(["user_id"=>$u->id,"role"=>"{role}","is_active"=>true,"organization_unit_id"=>{u}]);')
    rid = int(db(f'{RISK}::where("risk_type","reference")->where("code","FI-06-01")->value("id")'))
    cat, sub = [int(x) for x in db(f'implode(",", {RISK}::whereKey({rid})->first()->only(["category_id","sub_category_id"]))').split(',')]
    rows = db(f'{RISK}::whereKey({rid})->first()->phases()->count()')
    log('الإعداد: FI-06-01 في القاعدة المحلية', f'#{rid} صفوفه={rows}', rows == '1')

    with sync_playwright() as p:
        b = p.webkit.launch()
        sctx, sp, sbad = session(b, 'gh6.s')
        hctx, hp, hbad = session(b, 'gh6.h')
        ectx, ep, ebad = session(b, 'gh6.e')

        # ── ١ تفاصيل الخطر في العام: صف واحد بلا «الطور» ──
        tbl = open_risk(sp, cat, sub, rid)
        heads = [h.strip() for h in tbl.locator('thead th').all_inner_texts()]
        body_rows = tbl.locator('tbody tr').count()
        log('١ جدول التفاصيل بلا عمود «الطور» وصف واحد للخطر', f'الصفوف={body_rows} | ' + ' | '.join(heads[:6]), 'الطور' not in heads and body_rows == 1)
        cause_badge = tbl.locator('tbody .badge-count').first.inner_text().strip() if tbl.locator('tbody .badge-count').count() else '—'
        log('   الأسباب قائمة واحدة (١٢ سطراً من الأطوار الثلاثة)', cause_badge, cause_badge.startswith('12'))
        sp.screenshot(path=os.path.join(SHOT, 'h6-1-detail-one-row.png'), full_page=True)
        j = sp.request.get(BASE + f'/app/risk/registry/tree/reference/risk/{rid}', headers={'Accept': 'application/json'}).json()
        prev = j.get('preventive_action') or ''
        log('   الوقائي نص واحد بترقيم متصل: (١) مرة واحدة و(٢٠) موجود', f'طوله={len(prev)}', prev.count('(١)') == 1 and '(٢٠)' in prev and 'حماية القبو' in prev and 'اكتشاف: دخان' in prev)
        log('   التصحيحي نص واحد فيه نصوص الأطوار الثلاثة', (j.get('corrective_action') or '')[:80], 'تحقيق مع الدفاع المدني' in (j.get('corrective_action') or '') and 'إصلاح فوري' in (j.get('corrective_action') or ''))
        log('   المتأثرون مرة واحدة', f"{len(j.get('affected_groups') or [])} مجموعة", 9 <= len(j.get('affected_groups') or []) <= 10)

        # ── ٢ نموذج التعديل: لسان واحد ──
        sp.goto(BASE + f'/app/risk/reference/{rid}/edit', wait_until='networkidle')
        panes = sp.locator('.tab-pane').count()
        src = sp.content()
        log('٢ نموذج التعديل لسان واحد بلا أطوار', f'الألسنة={panes}', panes == 1 and 'phases[single][preventive_action]' in src and 'phases[proactive]' not in src and 'استباقي' not in src)
        log('   الأسباب في النموذج ١٢ سطراً', f"{sp.locator('input[name=\"phases[single][cause_names][]\"]').count()}", sp.locator('input[name="phases[single][cause_names][]"]').count() == 12)
        sp.screenshot(path=os.path.join(SHOT, 'h6-2-edit-one-panel.png'), full_page=True)
        sp.wait_for_function("() => document.querySelector('#subCatSelect').options.length > 1 && !document.querySelector('#subCatSelect').innerHTML.includes('جاري')")
        sp.fill('input[name="phases[single][cause_names][]"] >> nth=0', 'سبب G-H6 عُدّل من الشاشة')
        with sp.expect_navigation(wait_until='domcontentloaded'):
            sp.locator(f'form[action$="/reference/{rid}/edit"] button[type=submit]').click()
        rows = db(f'{RISK}::whereKey({rid})->first()->phases()->count()')
        first = db(f'{RISK}::whereKey({rid})->first()->phases()->first()->causes()->orderBy("risk_causes.id")->get()->contains("name","سبب G-H6 عُدّل من الشاشة") ? "yes" : "no"')
        log('   الحفظ يبقي الصف واحداً ويحمل التعديل', f'صفوف={rows} السبب الجديد={first}', rows == '1' and first == 'yes')
        tinker(f'$p={RISK}::whereKey({rid})->first()->phases()->first(); $c=App\\Modules\\Risk\\Models\\RiskCause::where("name","سبب G-H6 عُدّل من الشاشة")->first(); if($c){{ $p->causes()->detach($c->id); $c->delete(); }}')  # تنظيف أثر الجولة على خطر الكتاب

        # ── ٣ نموذج التفعيل عند مدير إدارة: لسان واحد ──
        hp.goto(BASE + f'/app/risk/{rid}/activate', wait_until='networkidle')
        src = hp.content()
        log('٣ نموذج التفعيل لسان واحد بلا أطوار', f"الألسنة={hp.locator('.tab-pane').count()}", hp.locator('.tab-pane').count() == 1 and 'phases[single][corrective_action]' in src and 'استباقي' not in src)

        # ── ٤ بلاغ عادي يحمل التصحيحي الواحد ──
        ep.goto(BASE + f'/incident/normal?risk={rid}&place=HZ-02', wait_until='networkidle')
        pid = db('App\\Modules\\Governance\\Models\\Place::idByCode("HZ-02")')
        ep.select_option('select[name=place_id]', str(pid))
        ep.fill('textarea[name=description]', 'بلاغ G-H6 — مركبة تسرب وقوداً في القبو')
        with ep.expect_navigation(wait_until='domcontentloaded'):
            ep.locator('button:has-text("إرسال البلاغ")').click()
        ep.wait_for_load_state('networkidle')
        row = db(f'json_encode({INC}::where("description","like","%G-H6%")->orderByDesc("id")->first()?->only(["id","code","corrective_action"]), JSON_UNESCAPED_UNICODE)')
        log('٤ البلاغ العادي يحمل التصحيحي الواحد', row[:160], '/incident/success' in ep.url and 'تحقيق مع الدفاع المدني' in row and 'إصلاح فوري' in row)
        iid = re.search(r'"id":(\d+)', row).group(1)
        sp.goto(BASE + f'/app/incidents/{iid}', wait_until='networkidle')
        txt = sp.locator('body').inner_text()
        log('   صفحة البلاغ: «الإجراءات من السجل العام» بنصه كله، بلا «الطبقة»', '', 'الإجراءات من السجل العام' in txt and 'حماية القبو' in txt and 'اكتشاف: دخان' in txt and 'الطبقة التشغيلية' not in txt and 'الطبقة الاستجابة' not in txt)
        sp.screenshot(path=os.path.join(SHOT, 'h6-4-incident.png'), full_page=True)

        # ── ٥ صفحة الخطر وكتاب التوعية ──
        sp.goto(BASE + f'/app/risk/{rid}/detail', wait_until='networkidle')
        txt = sp.locator('body').inner_text()
        log('٥ صفحة الخطر: «الأسباب والإجراءات» بلا عناوين الأطوار', '', 'الأسباب والإجراءات' in txt and 'مراحل الخطر' not in txt and txt.count('الإجراء الوقائي') == 1)
        ep.goto(BASE + '/hazards?q=FI-06-01', wait_until='networkidle')
        log('   كتاب التوعية يعرض الخطر', '', 'FI-06-01' in ep.locator('body').inner_text())

        for who, bad in [('مسؤول السلامة', sbad), ('مدير الموارد', hbad), ('الموظف', ebad)]:
            log(f'أخطاء خادم عند {who}', '؛ '.join(bad) or 'لا شيء', not bad)
        b.close()
finally:
    print(tinker(CLEAN + 'echo "V:clean";').split('V:')[-1].strip())

print('\n==== الأخطاء:', errs if errs else 'صفر')
sys.exit(1 if errs else 0)
