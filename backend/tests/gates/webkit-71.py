# -*- coding: utf-8 -*-
"""بوابة قرار ٧١ (٢٠٢٦-١٠-٠٣) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

تفعيل المحدَّد من السجل العام دفعةً:
  المنسق (أضعف صاحب فعل): يحدّد فئة ← «فعّل المحدَّد» ← النافذة بخانتين (وحدته مختارة، بلا «عام») ←
  بلا معالج يُرفض ← بمعالج: تُفعَّل الفئة كلها «بانتظار الاعتماد» ومنسقها هو ←
  «حدّد الكل» ← الباقي يُفعَّل، وما سبق يُتخطّى ويُقال ← تنبيه واحد لكل ضغطة لا لكل خطر ←
  المدير: بطاقة واحدة بعددها ← «اعرضها» ويعتمد واحداً ← «اعتمدها كلها» ← كلها نشطة والبطاقة تختفي.
الحالة تُقرأ من القاعدة بعد كل ضغطة.

وحدة مؤقتة وحسابان مؤقتان بكلمة عشوائية تُنشأ للجولة وتُحذف بعدها مع كل ما فُعّل لها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-71.py [BASE]
"""
import sys, os, re, json, time, secrets, subprocess
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
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
CLEAN = (f'$u={UNIT}::where("code","g71")->first(); '
         f'if ($u) {{ $ids={RISK}::where("organization_unit_id",$u->id)->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); }} '
         f'$us={USER}::whereIn("username",["g71.c","g71.m"])->pluck("id"); '
         f'{DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); '
         f'if ($u) $u->delete(); ')


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
    page = ctx.new_page(); page.set_default_timeout(180000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    assert login(page, user, pw), f'فشل دخول {user}'
    return ctx, page, console


def path(url):
    return url.replace(BASE, '')


def home(page):
    page.goto(BASE + '/app', wait_until='networkidle')


def open_group(page):
    grp = page.locator('#inboxList section[data-module="المخاطر"] > button.grp-h')
    if grp.count() and grp.get_attribute('aria-expanded') == 'false':
        grp.click(); page.wait_for_timeout(600)


def activate_selected(page, handler, shot=None):
    """«فعّل المحدَّد» ← النافذة ← المعالج ← «فعّل» — يعيد نص النتيجة وأعدادها والزمن"""
    page.click('#bulkGo')
    page.wait_for_selector('#bulkModal.show')
    if handler:
        page.select_option('#bulkHandler', str(handler))
    t = time.time()
    page.click('#bulkSubmit')
    page.wait_for_selector('#bulkResult', state='visible', timeout=600000)
    r = page.locator('#bulkResult')
    out = {'text': r.inner_text().strip(), 'created': r.get_attribute('data-created'), 'existing': r.get_attribute('data-existing'),
           'unapproved': r.get_attribute('data-unapproved'), 'sec': round(time.time() - t, 1)}
    if shot:
        page.screenshot(path=os.path.join(SHOT, shot))
    return out


# التجهيز: وحدة مؤقتة تحت «المالية» بمنسقها ومديرها
seed = tinker(CLEAN + f'$p={UNIT}::where("code","fin")->firstOrFail(); $s={USER}::where("username","salama")->firstOrFail(); '
              f'$u={UNIT}::create(["code"=>"g71","name"=>"قسم بوابة ٧١","unit_type"=>"section","parent_id"=>$p->id,"is_active"=>true]); '
              f'$mk=function($n,$role,$label) use ($u,$s) {{ $x={USER}::create(["username"=>$n,"name"=>$label,"email"=>$n."@gate.invalid","password"=>"{PW}"]); '
              f'$q={PROFILE}::create(["user_id"=>$x->id,"role"=>$role,"is_active"=>true,"organization_unit_id"=>$u->id,"job_title"=>$label]); $q->approve($s); return $x->id; }}; '
              '$c=$mk("g71.c","safety_coordinator","منسق سلامة قسم البوابة"); $m=$mk("g71.m","section_manager","مدير قسم البوابة"); '
              f'$ok={RISK}::where("risk_type","reference")->whereIn("status",["approved","active"]); '
              f'$cat=(clone $ok)->orderBy("category_id")->value("category_id"); '
              f'echo "JSON:".json_encode(["unit"=>$u->id,"c"=>$c,"m"=>$m,"all"=>(clone $ok)->count(),"cat"=>$cat,"k"=>(clone $ok)->where("category_id",$cat)->count()]);')
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-800:]
S = json.loads(m.group(1))
N, K, U = S['all'], S['k'], S['unit']
log('التجهيز المحلي', S)
count = lambda st: int(db(f'{RISK}::where("risk_type","active")->where("organization_unit_id",{U})->where("status","{st}")->count()') or 0)
notes = lambda: int(db(f'{DBF}::table("app_notifications")->where("user_id",{S["m"]})->where("type","risk.approve")->count()') or 0)

try:
    with sync_playwright() as p:
        browser = p.webkit.launch()

        # ── منسق السلامة ──
        cctx, cpage, ccon = session(browser, 'g71.c', PW)
        cpage.goto(BASE + '/app/risk/reference', wait_until='networkidle')
        badge = cpage.locator('#bulkAll .badge').inner_text().strip()
        log('المنسق: شريط الدفعة في السجل العام و«حدّد الكل» بعدد المعتمد', [cpage.locator('#bulkBar').count(), badge], cpage.locator('#bulkBar').count() == 1 and badge == str(N))
        log('المنسق: «فعّل المحدَّد» معطَّل بلا تحديد', cpage.locator('#bulkGo').is_disabled(), cpage.locator('#bulkGo').is_disabled())
        cpage.wait_for_selector(f'#ref-categories-list .cat-bulk-cb[value="{S["cat"]}"]')
        cpage.check(f'#ref-categories-list .cat-bulk-cb[value="{S["cat"]}"]')
        got = cpage.locator('#bulkCount').inner_text().strip()
        log('المنسق: ضغطة على مربع الفئة ← العدد = أخطار الفئة', [got, K], got == str(K))
        cpage.screenshot(path=os.path.join(SHOT, '71-coord-category.png'))

        # النافذة: خانتان، وحدته مختارة، بلا «عام»؛ وبلا معالج يُرفض
        cpage.click('#bulkGo'); cpage.wait_for_selector('#bulkModal.show')
        unit = cpage.locator('#bulkModal select[name=organization_unit_id]').evaluate('e => e.value')
        general = 'عام — المؤسسة بالكامل' in cpage.locator('#bulkModal').inner_html()
        selects = cpage.locator('#bulkModal select').count()
        cpage.click('#bulkSubmit'); cpage.wait_for_selector('#bulkResult', state='visible')
        refusal = cpage.locator('#bulkResult').inner_text().strip()
        log('المنسق: النافذة بخانتين، وحدته مختارة، بلا «عام»؛ وبلا معالج تُرفض', [selects, unit, general, refusal, count('pending_approval')],
            selects == 2 and unit == str(U) and not general and 'المعالج' in refusal and count('pending_approval') == 0)
        cpage.screenshot(path=os.path.join(SHOT, '71-coord-modal.png'))
        cpage.click('#bulkCancel'); cpage.wait_for_selector('#bulkModal', state='hidden')

        r = activate_selected(cpage, S['m'], '71-coord-result.png')
        mine = int(db(f'{RISK}::where("risk_type","active")->where("organization_unit_id",{U})->where("assigned_coordinator_id",{S["c"]})->where("assigned_field_team_id",{S["m"]})->count()') or 0)
        log('المنسق: الفئة كلها بضغطة ← بانتظار الاعتماد، منسقها هو ومعالجها المسمّى', [r, count('pending_approval'), mine, notes()],
            r['created'] == str(K) and r['existing'] == '0' and 'ليعتمدها' in r['text'] and count('pending_approval') == K and mine == K and notes() == 1)
        cpage.click('#bulkCancel'); cpage.wait_for_selector('#bulkModal', state='hidden')

        # «حدّد الكل»: الباقي يُفعَّل وما سبق يُتخطّى ويُقال
        cpage.click('#bulkAll')
        got = cpage.locator('#bulkCount').inner_text().strip()
        log('المنسق: «حدّد الكل» ← العدد = كل المعتمد في السجل العام', [got, N], got == str(N))
        r = activate_selected(cpage, S['m'], '71-coord-all.png')
        log('المنسق: الكل بضغطة ← الباقي فُعّل وما سبق تُخطّي، وتنبيه واحد لكل ضغطة لا لكل خطر', [r, count('pending_approval'), notes()],
            r['created'] == str(N - K) and r['existing'] == str(K) and 'تُخطّي' in r['text'] and count('pending_approval') == N and notes() == 2)
        log('المنسق: أخطاء', ccon or 'صفر', not ccon)
        cctx.close()

        # ── مدير القسم ──
        mctx, mpage, mcon = session(browser, 'g71.m', PW)
        home(mpage); open_group(mpage)
        card = mpage.locator('#inboxList [data-batch="risk.approve"]')
        q = card.locator('.fw-bold').first.inner_text().strip() if card.count() else '—'
        allbtn = card.locator('form[data-all="risk.approve"] button')
        log('المدير: بطاقة واحدة بعددها وزر «اعتمدها كلها»', [card.count(), card.get_attribute('data-n') if card.count() else None, q, allbtn.inner_text().strip() if allbtn.count() else '—'],
            card.count() == 1 and card.get_attribute('data-n') == str(N) and 'بانتظار اعتمادك' in q and allbtn.count() == 1)
        card.scroll_into_view_if_needed(); mpage.wait_for_timeout(300)
        mpage.screenshot(path=os.path.join(SHOT, '71-manager-card.png'))

        # «اعرضها» ← يعتمد واحداً بعينه
        card.locator('.task-actions > button[data-bs-toggle=collapse]').click(); mpage.wait_for_timeout(700)
        rows = card.locator('.batch-row')
        shown = rows.count()
        first = rows.first
        one = first.get_attribute('data-task')
        name = first.locator('.fw-bold').first.inner_text().strip()
        with mpage.expect_navigation(wait_until='domcontentloaded'):
            first.locator('.task-actions form button.btn-g').click()
        one_id = int(one.split(':')[1])
        log('المدير: «اعرضها» ← بنودها بأسمائها، ويعتمد واحداً بعينه', [shown, one, name[:40], db(f'{RISK}::find({one_id})?->status'), count('active'), count('pending_approval')],
            shown == N and len(name) > 3 and db(f'{RISK}::find({one_id})?->status') == 'active' and count('active') == 1 and count('pending_approval') == N - 1)

        # «اعتمدها كلها»
        home(mpage); open_group(mpage)
        card = mpage.locator('#inboxList [data-batch="risk.approve"]')
        t = time.time()
        with mpage.expect_navigation(wait_until='domcontentloaded', timeout=600000) as nav:
            card.locator('form[data-all="risk.approve"] button').click()
        sec = round(time.time() - t, 1)
        home(mpage)
        byhim = int(db(f'{RISK}::where("risk_type","active")->where("organization_unit_id",{U})->where("status","active")->where("approved_by_id",{S["m"]})->count()') or 0)
        log('المدير: «اعتمدها كلها» ← كلها نشطة باسمه، والبطاقة اختفت', [nav.value.status, sec, count('active'), count('pending_approval'), byhim, mpage.locator('#inboxList [data-batch="risk.approve"]').count()],
            nav.value.status == 200 and count('active') == N and count('pending_approval') == 0 and byhim == N and mpage.locator('#inboxList [data-batch="risk.approve"]').count() == 0)
        mpage.screenshot(path=os.path.join(SHOT, '71-manager-after.png'))
        log('المدير: أخطاء', mcon or 'صفر', not mcon)
        mctx.close()
        browser.close()
finally:
    tinker(CLEAN + 'echo "ok";')
    left = db(f'{UNIT}::where("code","g71")->count() + {USER}::whereIn("username",["g71.c","g71.m"])->count()')
    log('التنظيف: لم يبقَ من التجهيز شيء', left, left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة قرار ٧١ خضراء'))
sys.exit(1 if errs else 0)
