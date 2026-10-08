# -*- coding: utf-8 -*-
"""بوابة «مخاطر المكان لوحدة صاحب الحساب» (٢٠٢٦-١٠-٠٤) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

كما وقع على المنشور: مدير قسم في المكاتب الإدارية يفعّل مخاطر لقسمه (الدفعة لا تكتب مكاناً) ←
  ملف «المكاتب الإدارية» كان يقول «مخاطر المكان: ٠» وزر «السجل كاملاً» يفتح سجلاً فارغاً.
الآن: الملف يعدّ مخاطر قسمه ويعرضها، و«السجل كاملاً» يفتح السجل الفعلي وفيه مخاطره؛ ولا يرى مخاطر إدارة أخرى.

وحدة مؤقتة في المكاتب الإدارية ومديرها بكلمة عشوائية، تُحذف بعد الجولة مع ما فُعّل لها.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-place-risks.py [BASE]
"""
import sys, os, re, json, secrets, subprocess
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


def val(expr):
    return tinker(f'echo "V:".({expr});').split('V:')[-1].strip()


RISK = 'App\\Modules\\Risk\\Models\\Risk'
USER = 'App\\Models\\User'
PROFILE = 'App\\Modules\\Governance\\Models\\UserProfile'
UNIT = 'App\\Modules\\Governance\\Models\\OrganizationUnit'
PLACE = 'App\\Modules\\Governance\\Models\\Place'
DBF = 'Illuminate\\Support\\Facades\\DB'
CLEAN = (f'$u={UNIT}::where("code","gpr")->first(); '
         f'if ($u) {{ $ids={RISK}::where("organization_unit_id",$u->id)->pluck("id"); $ph={DBF}::table("risk_phases")->whereIn("risk_id",$ids)->pluck("id"); '
         f'foreach(["risk_phase_causes","risk_phase_affected_groups","risk_phase_affected_group_details"] as $t) {DBF}::table($t)->whereIn("risk_phase_id",$ph)->delete(); '
         f'foreach(["risk_phases","risk_events","risk_notes","risk_controls"] as $t) {DBF}::table($t)->whereIn("risk_id",$ids)->delete(); '
         f'{RISK}::whereIn("id",$ids)->delete(); }} '
         f'$us={USER}::whereIn("username",["gpr.m","gpr.c"])->pluck("id"); {DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); if ($u) $u->delete(); ')


def login(page, user, pw):
    for i in range(4):
        page.goto(BASE + '/login', wait_until='domcontentloaded')
        if page.locator('input[name=username]').count() == 0:
            page.wait_for_timeout(5000); continue
        page.fill('input[name=username]', user); page.fill('input[name=password]', pw)
        page.click('button[type=submit]'); page.wait_for_load_state('networkidle')
        if '/login' not in page.url: return True
        page.wait_for_timeout(15000)
    return False


# التجهيز: قسم مؤقت مكانه المكاتب الإدارية، ومديره ومنسقه
seed = tinker(CLEAN + f'$hub={PLACE}::where("code","HZ-06")->firstOrFail(); $p={UNIT}::where("code","fin")->firstOrFail(); $s={USER}::where("username","salama")->firstOrFail(); '
              f'$u={UNIT}::create(["code"=>"gpr","name"=>"قسم بوابة المكان","unit_type"=>"section","parent_id"=>$p->id,"place_id"=>$hub->id,"is_active"=>true]); '
              f'$mk=function($n,$role,$label) use ($u,$s,$hub) {{ $x={USER}::create(["username"=>$n,"name"=>$label,"email"=>$n."@gate.invalid","password"=>"{PW}"]); '
              f'$q={PROFILE}::create(["user_id"=>$x->id,"role"=>$role,"is_active"=>true,"organization_unit_id"=>$u->id,"place_id"=>$hub->id,"job_title"=>$label]); $q->approve($s); return $x->id; }}; '
              '$m=$mk("gpr.m","section_manager","مدير قسم بوابة المكان"); $c=$mk("gpr.c","safety_coordinator","منسق قسم بوابة المكان"); '
              f'$ok={RISK}::where("risk_type","reference")->whereIn("status",["approved","active"]); $cat=(clone $ok)->orderBy("category_id")->value("category_id"); '
              f'$other={RISK}::where("risk_type","active")->where("organization_unit_id","!=",$u->id)->whereNotNull("organization_unit_id")->whereNotIn("status",{RISK}::NOT_IN_EFFECT)->value("code"); '
              f'echo "JSON:".json_encode(["unit"=>$u->id,"hub"=>$hub->id,"m"=>$m,"cat"=>$cat,"k"=>(clone $ok)->where("category_id",$cat)->count(),"other"=>$other]);')
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-800:]
S = json.loads(m.group(1))
K, U = S['k'], S['unit']
log('التجهيز المحلي', S)

try:
    with sync_playwright() as p:
        browser = p.webkit.launch()
        ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
        page = ctx.new_page(); page.set_default_timeout(180000)
        con = []
        page.on('console', lambda mm: con.append(mm.text) if mm.type == 'error' else None)
        page.on('response', lambda r: con.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
        assert login(page, 'gpr.m', PW), 'فشل دخول المدير'

        # قبل التفعيل: الملف يقول «لا خطر مفعّل بعد»
        page.goto(BASE + f"/app/places/{S['hub']}/file", wait_until='networkidle')
        before = page.locator('#pfRisks').inner_text().strip().replace('\n', ' | ')
        log('قبل التفعيل: زر «مخاطر المكان»', before, 'لا خطر مفعّل بعد' in before)

        # المدير يفعّل فئة كاملة لقسمه من نافذة الدفعة — كما على المنشور: وحدة بلا مكان
        page.goto(BASE + '/app/risk/reference', wait_until='networkidle')
        page.wait_for_selector(f'#ref-categories-list .cat-bulk-cb[value="{S["cat"]}"]')
        page.check(f'#ref-categories-list .cat-bulk-cb[value="{S["cat"]}"]')
        page.click('#bulkGo'); page.wait_for_selector('#bulkModal.show')
        if page.locator('#bulkHandler').count(): page.select_option('#bulkHandler', str(S['m']))  # خانة المعالج خرجت من الدفعة (خطة المعالج، الخطوة ٤)
        page.click('#bulkSubmit'); page.wait_for_selector('#bulkResult', state='visible', timeout=600000)
        created = page.locator('#bulkResult').get_attribute('data-created')
        noplace = int(val(f'{RISK}::where("organization_unit_id",{U})->whereNull("place_id")->where("status","active")->count()') or 0)
        log('فعّل الفئة لقسمه: نشطة فوراً، والوحدة مكتوبة والمكان فارغ', [created, noplace], created == str(K) and noplace == K)

        # ملف المكان: يعدّها ويعرضها
        page.goto(BASE + f"/app/places/{S['hub']}/file", wait_until='networkidle')
        btn = page.locator('#pfRisks').inner_text().strip().replace('\n', ' | ')
        page.click('#pfRisks'); page.wait_for_timeout(600)
        badge = page.locator('#pfSecRisks .badge').first.inner_text().strip()
        cards = page.locator('#pfSecRisks [data-risk]').evaluate_all("els => els.map(e => e.getAttribute('data-risk'))")
        mine = [c for c in cards if c.endswith('/GPR')]
        log('ملف المكان: زر «مخاطر المكان» بعدد مخاطر قسمه، وبطاقاتها تحته', [btn, badge, len(cards), len(mine)],
            f'{K} مفعّل' in btn and badge == str(K) and len(mine) == min(K, 12) and len(cards) == len(mine))
        log('ملف المكان: لا خطر لإدارة أخرى', [S['other'], S['other'] in cards], S['other'] not in cards)
        page.screenshot(path=os.path.join(SHOT, 'place-risks-file.png'))

        # «السجل كاملاً»: يفتح السجل الفعلي وفيه مخاطره
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.locator('#pfSecRisks a', has_text='السجل كاملاً').click()
        page.wait_for_load_state('networkidle')
        cats = page.locator('.cat-nav-item').count()
        empty = 'لا توجد مخاطر فعلية بعد' in page.inner_text('body')
        got = page.request.get(BASE + '/app/risk/registry/tree/active/categories?place=HZ-06').json()
        log('«السجل كاملاً»: السجل الفعلي ليس فارغاً وفيه فئة مخاطره', [page.url.replace(BASE, ''), cats, empty, [c['id'] for c in got]], cats >= 1 and not empty and S['cat'] in [c['id'] for c in got])
        page.screenshot(path=os.path.join(SHOT, 'place-risks-register.png'))
        log('أخطاء', con or 'صفر', not con)
        ctx.close(); browser.close()
finally:
    tinker(CLEAN + 'echo "ok";')
    left = val(f'{UNIT}::where("code","gpr")->count() + {USER}::whereIn("username",["gpr.m","gpr.c"])->count()')
    log('التنظيف: لم يبقَ من التجهيز شيء', left, left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة مخاطر المكان خضراء'))
sys.exit(1 if errs else 0)
