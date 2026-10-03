# -*- coding: utf-8 -*-
"""بوابة تتمة قرار ٦٩ (٢٠٢٦-١٠-٠٣) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

المديرون الثلاثة بأدوارهم الخاصة يعتمدون مخاطر وحدتهم بحساباتهم نفسها — يُجرَّب بحساب كل واحد منهم لا بحساب واحد:
  منسق الشؤون الإدارية والهندسية يقدّم ثلاثة مخاطر ←
  مدير الشؤون الإدارية والهندسية يرى الثلاثة ويعتمد الأول ← مدير المرافق والصيانة يرى الباقيين ويعتمد الثاني ← رئيس الأمن والسلامة يعتمد الثالث.
  والحساب بالدور نفسه غير المربوط بوحدة: لا بطاقة ولا اعتماد. ومسؤول السلامة: خطر إدارة ليس عنده.
الحالة تُقرأ من القاعدة بعد كل ضغطة (من اعتمد وبأي حساب).

كلمة الحسابات التجريبية من المتغيّر TRIAL_PW (لا تُكتب هنا).
التشغيل: TRIAL_PW=… PYTHONIOENCODING=utf-8 python webkit-69b.py [BASE]
"""
import sys, os, re, json, subprocess
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
TRIAL_PW = os.environ.get('TRIAL_PW', '')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
TAG = 'بوابة ٦٩-ب'
errs = []
assert TRIAL_PW, 'TRIAL_PW غير مُمرَّرة'
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
CLEAN = (f'$ids={RISK}::where("title","like","%{TAG}%")->pluck("id"); '
         'foreach(["risk_phases","risk_events","risk_notes"] as $t) Illuminate\\Support\\Facades\\DB::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'Illuminate\\Support\\Facades\\DB::table("app_notifications")->where("type","risk.approve")->where("message","like","%{TAG}%")->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); ')


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


# التجهيز: ثلاث مسودات في «الشؤون الإدارية والهندسية» بيد منسقها
seed = tinker(CLEAN + '$c=App\\Models\\User::where("username","tj.adm-eng.c")->firstOrFail(); $u=$c->profile->organization_unit_id; $o=[]; '
              'foreach(["تماس كهربائي في غرفة المولد","تسرّب في غرفة المضخات","انزلاق عند بوابة الأمن"] as $t) '
              f'$o[]={RISK}::create(["title"=>$t." — {TAG}","description"=>"وصف","risk_type"=>"active","status"=>"draft","severity"=>3,"likelihood"=>3,"created_by_id"=>$c->id,"organization_unit_id"=>$u])->id; '
              'echo "JSON:".json_encode(["ids"=>$o,"unit"=>$u]);')
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-600:]
S = json.loads(m.group(1))
A, B, C = S['ids']
log('التجهيز المحلي', S)
status = lambda i: db(f'{RISK}::find({i})->status')
approver = lambda i: db(f'App\\Models\\User::find({RISK}::find({i})->approved_by_id)?->username')

with sync_playwright() as p:
    browser = p.webkit.launch()

    # المنسق يقدّم الثلاثة
    cctx, cpage, ccon = session(browser, 'tj.adm-eng.c', TRIAL_PW)
    for i in (A, B, C):
        home(cpage)
        press(cpage, card(cpage, f'risk:{i}:draft'))
    log('منسق الشؤون الإدارية: قدّم الثلاثة', [status(A), status(B), status(C)], [status(A), status(B), status(C)] == ['pending_approval'] * 3)
    log('منسق الشؤون الإدارية: أخطاء', ccon or 'صفر', not ccon)
    cctx.close()

    # كل مدير من الثلاثة بحسابه: يرى ما بقي، ويعتمد واحداً
    left = [A, B, C]
    for user, who, mine in [('tj.shuon', 'مدير الشؤون الإدارية والهندسية', A), ('tj.marafiq', 'مدير المرافق والصيانة', B), ('tj.amn', 'رئيس الأمن والسلامة', C)]:
        ctx, page, con = session(browser, user, TRIAL_PW)
        home(page)
        seen = {i: has(page, f'risk:{i}:approve') for i in (A, B, C)}
        log(f'{who}: بطاقات «ينتظر اعتمادك» لما بقي من مخاطر وحدته', seen, all(seen[i] == (1 if i in left else 0) for i in (A, B, C)))
        c = card(page, f'risk:{mine}:approve'); t = text(c)
        page.screenshot(path=os.path.join(SHOT, f'69b-{user}.png'))
        q = page.request.get(BASE + '/app/risk/approval/queue')
        log(f'{who}: طابور الاعتماد يفتح له', q.status, q.status == 200)
        r = press(page, c) if c.count() else ['—', '—']
        home(page)
        log(f'{who}: ضغطة «اعتمد» ← اختفت، والخطر معتمد باسمه', [t, r, has(page, f'risk:{mine}:approve'), status(mine), approver(mine)],
            r[0] == 200 and has(page, f'risk:{mine}:approve') == 0 and status(mine) == 'approved' and approver(mine) == user)
        log(f'{who}: أخطاء', con or 'صفر', not con)
        ctx.close()
        left.remove(mine)

    # الدور نفسه غير المربوط بوحدة: لا بطاقة ولا اعتماد — الشرط أن يُربط الحساب بوحدته
    back = tinker(f'{RISK}::whereKey({C})->update(["status"=>"pending_approval","approved_by_id"=>null]); echo "ok";')
    uctx, upage, ucon = session(browser, 'shuon', '1234')
    home(upage)
    r = post(upage, f'/app/risk/{C}/approve')
    log('مدير الشؤون الإدارية بحساب غير مربوط بوحدة: لا بطاقة، والاعتماد مرفوض', [has(upage, f'risk:{C}:approve'), r.status, status(C)], has(upage, f'risk:{C}:approve') == 0 and r.status == 403 and status(C) == 'pending_approval')
    log('الحساب غير المربوط: أخطاء', ucon or 'صفر', not ucon)
    uctx.close()

    # مسؤول السلامة: خطر إدارة ليس عنده
    sctx, spage, scon = session(browser, 'salama', '1234')
    home(spage)
    r = post(spage, f'/app/risk/{C}/approve')
    log('مسؤول السلامة: خطر الإدارة ليس عنده ولا يعتمده', [has(spage, f'risk:{C}:approve'), r.status], has(spage, f'risk:{C}:approve') == 0 and r.status == 403)
    log('مسؤول السلامة: أخطاء', scon or 'صفر', not scon)
    sctx.close()
    browser.close()

tinker(CLEAN + 'echo "ok";')
left_n = db(f'{RISK}::where("title","like","%{TAG}%")->count()')
log('التنظيف: لم يبقَ من التجهيز شيء', left_n, left_n == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة تتمة قرار ٦٩ خضراء'))
sys.exit(1 if errs else 0)
