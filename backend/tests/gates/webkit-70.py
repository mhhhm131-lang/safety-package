# -*- coding: utf-8 -*-
"""بوابة قرار ٧٠ (٢٠٢٦-١٠-٠٣) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

من يعتمد الخطر إن فعّله بنفسه صار نشطاً؛ غيره ينتظر المعتمد. ومنسق السلامة نطاقه نطاق مديره.
  منسق الإدارة (أضعف صاحب فعل): نموذج التفعيل بلا «عام» ووحدته مختارة سلفاً ← يفعّل ← «بانتظار الاعتماد» ←
  لا يفعّل لإدارة غيره ولا يعتمد خطره من باب خلفي ولا يفتح خطر إدارة أخرى ←
  مدير الإدارة: بطاقة «ينتظر اعتمادك» ← ضغطة ← نشط؛ وتفعيله هو نشط فوراً ←
  مسؤول السلامة: يفعّل للإدارة فينتظر مديرها.
الحالة تُقرأ من القاعدة بعد كل ضغطة.

حسابا المنسق والمدير مؤقتان يُنشآن للجولة بكلمة عشوائية ويُحذفان بعدها (لا كلمة مكتوبة هنا).
التشغيل: PYTHONIOENCODING=utf-8 python webkit-70.py [BASE]
"""
import sys, os, re, json, secrets, subprocess
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
TAG = 'بوابة ٧٠'
PW = secrets.token_urlsafe(12)
errs = []
assert '127.0.0.1' in BASE or 'localhost' in BASE, 'البوابة للمحلي وحده (قرار ٥٧)'


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


RISK = 'App\\Modules\\Risk\\Models\\Risk'
USER = 'App\\Models\\User'
PROFILE = 'App\\Modules\\Governance\\Models\\UserProfile'
UNIT = 'App\\Modules\\Governance\\Models\\OrganizationUnit'
DBF = 'Illuminate\\Support\\Facades\\DB'
CLEAN = (f'$ids={RISK}::where("title","like","%{TAG}%")->pluck("id"); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {{ try {{ {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); }} catch (\\Throwable $e) {{}} }} '
         f'{DBF}::table("app_notifications")->where("type","risk.approve")->where("message","like","%{TAG}%")->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::whereIn("username",["g70.c","g70.m"])->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); ')


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


def has(page, key):
    return page.locator(f'#inboxList [data-task="{key}"]').count()


def card(page, key):
    c = page.locator(f'#inboxList [data-task="{key}"]')
    if c.count():
        grp = c.first.locator('xpath=ancestor::section[1]').locator(':scope > button.grp-h')
        if grp.count() and grp.get_attribute('aria-expanded') == 'false':
            grp.click(); page.wait_for_timeout(600)
    return c


def press(page, c):
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        c.locator('.task-actions form button.btn-g').first.click()
    return [nav.value.status, path(page.url)]


def post(page, url, data=None):
    token = page.locator('meta[name=csrf-token]').get_attribute('content')
    return page.request.post(BASE + url, form=dict(data or {}, _token=token), max_redirects=0)


def text(c):
    return c.first.locator('.fw-bold').first.inner_text().strip() if c.count() else '—'


def form_scope(page, ref):
    """يفتح نموذج التفعيل ويعيد: هل يعرض «عام»، وحدات القائمة، المختار سلفاً"""
    r = page.goto(BASE + f'/app/risk/{ref}/activate', wait_until='domcontentloaded')
    general = 'عام — المؤسسة بالكامل' in page.content()
    sel = page.locator('select[name=organization_unit_id]').first
    opts = [int(v) for v in sel.locator('option').evaluate_all('els => els.map(e => e.value)') if v]
    chosen = sel.evaluate('e => e.value')
    return r.status, general, opts, chosen


def activate_by_form(page, ref, title, coord, handler):
    """يملأ نموذج التفعيل المفتوح ويضغط «تفعيل» — يعيد الحالة والمسار ونص الرسالة"""
    page.fill('input[name=title]', title)
    page.select_option('select[name=assigned_coordinator_id]', str(coord))
    page.select_option('select[name=assigned_field_team_id]', str(handler))
    btn = page.locator(f'form[action$="/app/risk/{ref}/activate"] button[type=submit]')
    btn.scroll_into_view_if_needed()
    with page.expect_navigation(wait_until='domcontentloaded') as nav:
        btn.click()
    flash = page.locator('.alert-success, .alert-danger').first
    return [nav.value.status, path(page.url), flash.inner_text().strip() if flash.count() else '—']


# التجهيز: منسق ومدير مؤقتان في «المالية»، وثلاثة أخطار معتمدة من السجل العام، وخطر فعلي لإدارة أخرى
seed = tinker(CLEAN + f'$u={UNIT}::where("code","fin")->firstOrFail(); $s={USER}::where("username","salama")->firstOrFail(); '
              f'$mk=function($n,$role,$label) use ($u,$s) {{ $x={USER}::create(["username"=>$n,"name"=>$label,"email"=>$n."@gate.invalid","password"=>"{PW}"]); '
              f'$p={PROFILE}::create(["user_id"=>$x->id,"role"=>$role,"is_active"=>true,"organization_unit_id"=>$u->id,"job_title"=>$label]); $p->approve($s); return $x->id; }}; '
              '$c=$mk("g70.c","safety_coordinator","منسق سلامة المالية (بوابة)"); $m=$mk("g70.m","department_manager","مدير المالية (بوابة)"); '
              f'$refs={RISK}::where("risk_type","reference")->where("status","approved")->orderBy("id")->take(3)->pluck("id")->all(); '
              f'$hr={UNIT}::where("code","hr")->value("id"); '
              f'$theirs={RISK}::where("risk_type","active")->where("organization_unit_id",$hr)->value("id"); '
              f'$mine={RISK}::where("risk_type","active")->where("organization_unit_id",$u->id)->value("id"); '
              f'echo "JSON:".json_encode(["unit"=>$u->id,"scope"=>$u->descendantIds(),"hr"=>$hr,"c"=>$c,"m"=>$m,"refs"=>$refs,"theirs"=>$theirs,"mine"=>$mine]);')
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-800:]
S = json.loads(m.group(1))
assert len(S['refs']) == 3, 'السجل العام المحلي بلا ثلاثة أخطار معتمدة'
R1, R2, R3 = S['refs']
log('التجهيز المحلي', S)
tagged = lambda: int(db(f'{RISK}::where("title","like","%{TAG}%")->count()') or 0)
newest = lambda: int(db(f'{RISK}::where("title","like","%{TAG}%")->max("id")') or 0)
status = lambda i: db(f'{RISK}::find({i})?->status')
approver = lambda i: db(f'{USER}::find({RISK}::find({i})?->approved_by_id)?->username')
unit_of = lambda i: db(f'{RISK}::find({i})?->organization_unit_id')

try:
    with sync_playwright() as p:
        browser = p.webkit.launch()

        # ── منسق السلامة ──
        cctx, cpage, ccon = session(browser, 'g70.c', PW)
        home(cpage)
        door = cpage.locator('a[href$="/app/risk/reference"]')
        log('المنسق: باب «أفعّل خطراً لإدارتي» في الصفحة الأولى', door.count(), door.count() >= 1)
        st, general, opts, chosen = form_scope(cpage, R1)
        log('المنسق: نموذج التفعيل — بلا «عام»، وحدته وما تحتها فقط، ووحدته مختارة سلفاً', [st, general, opts, chosen],
            st == 200 and not general and sorted(opts) == sorted(S['scope']) and chosen == str(S['unit']))
        cpage.screenshot(path=os.path.join(SHOT, '70-coord-form.png'))
        r = activate_by_form(cpage, R1, f'خطر يفعّله المنسق — {TAG}', S['c'], S['m'])
        A = newest()
        log('المنسق: ضغطة «تفعيل» ← بانتظار الاعتماد، وقيل له من يعتمد', [r, status(A), unit_of(A)],
            r[0] == 200 and r[1].startswith('/app/risk/active') and 'ليعتمده' in r[2] and status(A) == 'pending_approval' and unit_of(A) == str(S['unit']))
        cpage.screenshot(path=os.path.join(SHOT, '70-coord-after.png'))
        home(cpage)
        log('المنسق: لا بطاقة اعتماد عنده لخطره', has(cpage, f'risk:{A}:approve'), has(cpage, f'risk:{A}:approve') == 0)
        base = {'severity': 3, 'likelihood': 3, 'assigned_coordinator_id': S['c'], 'assigned_field_team_id': S['m'], 'title': f'خارج النطاق — {TAG}'}
        before = tagged()
        r1 = post(cpage, f'/app/risk/{R2}/activate', dict(base, scope_type='org_unit', organization_unit_id=S['hr']))
        r2 = post(cpage, f'/app/risk/{R2}/activate', dict(base, scope_type='general'))
        log('المنسق: لا يفعّل لإدارة غيره ولا للمعهد كله', [r1.status, r2.status, before, tagged()], tagged() == before)
        r3 = post(cpage, f'/app/risk/{A}/change-status', {'status': 'approved'})
        r4 = post(cpage, f'/app/risk/{A}/approve')
        log('المنسق: لا يعتمد خطره — لا من الباب ولا من تغيير الحالة', [r3.status, r4.status, status(A)], r3.status == 403 and r4.status == 403 and status(A) == 'pending_approval')
        if S['theirs'] and S['mine']:
            a = cpage.request.get(BASE + f"/app/risk/{S['theirs']}/detail"); b = cpage.request.get(BASE + f"/app/risk/{S['mine']}/detail")
            log('المنسق: يفتح خطر وحدته ولا يفتح خطر إدارة أخرى', [b.status, a.status], b.status == 200 and a.status == 403)
        log('المنسق: أخطاء', ccon or 'صفر', not ccon)
        cctx.close()

        # ── مدير الإدارة ──
        mctx, mpage, mcon = session(browser, 'g70.m', PW)
        home(mpage)
        c = card(mpage, f'risk:{A}:approve'); t = text(c)
        mpage.screenshot(path=os.path.join(SHOT, '70-manager-card.png'))
        log('المدير: بطاقة «ينتظر اعتمادك» لما فعّله منسقه', [has(mpage, f'risk:{A}:approve'), t], has(mpage, f'risk:{A}:approve') == 1 and 'ينتظر اعتمادك' in t)
        r = press(mpage, c) if c.count() else ['—', '—']
        home(mpage)
        log('المدير: ضغطة «اعتمد» ← نشط باسمه، والبطاقة اختفت', [r, has(mpage, f'risk:{A}:approve'), status(A), approver(A)],
            r[0] == 200 and has(mpage, f'risk:{A}:approve') == 0 and status(A) == 'active' and approver(A) == 'g70.m')
        st, general, opts, chosen = form_scope(mpage, R2)
        log('المدير: نموذج التفعيل — بلا «عام»، ووحدته مختارة سلفاً', [st, general, opts, chosen], st == 200 and not general and chosen == str(S['unit']))
        r = activate_by_form(mpage, R2, f'خطر يفعّله المدير — {TAG}', S['c'], S['m'])
        B = newest()
        home(mpage)
        log('المدير: تفعيله هو نشط فوراً بلا بطاقة', [r, status(B), has(mpage, f'risk:{B}:approve')],
            r[0] == 200 and B != A and status(B) == 'active' and has(mpage, f'risk:{B}:approve') == 0 and 'ليعتمده' not in r[2])

        # ── مسؤول السلامة: يفعّل للإدارة فينتظر مديرها ──
        sctx, spage, scon = session(browser, 'salama', '1234')
        st, general, opts, chosen = form_scope(spage, R3)
        log('مسؤول السلامة: نموذج التفعيل يعرض «عام» وكل الوحدات', [st, general, len(opts)], st == 200 and general and len(opts) > len(S['scope']))
        rs = post(spage, f'/app/risk/{R3}/activate', dict(base, scope_type='org_unit', organization_unit_id=S['unit'], title=f'خطر يفعّله مسؤول السلامة للإدارة — {TAG}'))
        C = newest()
        home(spage)
        log('مسؤول السلامة: فعّل لإدارة ← بانتظار مديرها، ولا بطاقة عنده', [rs.status, status(C), has(spage, f'risk:{C}:approve')],
            C not in (A, B) and status(C) == 'pending_approval' and has(spage, f'risk:{C}:approve') == 0)
        log('مسؤول السلامة: أخطاء', scon or 'صفر', not scon)
        sctx.close()

        home(mpage)
        c = card(mpage, f'risk:{C}:approve')
        r = press(mpage, c) if c.count() else ['—', '—']
        log('المدير: يعتمد ما فعّله مسؤول السلامة لإدارته ← نشط', [r, status(C), approver(C)], r[0] == 200 and status(C) == 'active' and approver(C) == 'g70.m')
        log('المدير: أخطاء', mcon or 'صفر', not mcon)
        mctx.close()
        browser.close()
finally:
    tinker(CLEAN + 'echo "ok";')
    left = db(f'{RISK}::where("title","like","%{TAG}%")->count() + {USER}::whereIn("username",["g70.c","g70.m"])->count()')
    log('التنظيف: لم يبقَ من التجهيز شيء', left, left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة قرار ٧٠ خضراء'))
sys.exit(1 if errs else 0)
