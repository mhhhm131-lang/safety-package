# -*- coding: utf-8 -*-
"""بوابة قرار ٧٢ (٢٠٢٦-١٠-٠٤) على WebKit iPhone 13 — القاعدة المحلية وحدها (قرار ٥٧).

بلاغ الشاغل لا يُرسل بلا خطر، والخطر أول النموذج؛ العادي بحساب والسري بلا دخول ولا هوية.
  ضيف: صفحة اختيار النوع تقول «بحسابك» ← بطاقة «عادي» ← صفحة الدخول ← يدخل ← يعود إلى النموذج نفسه ←
  الخطر أول خانة قبل المكان، وبلا خطر لا يُرسل ← يختار الفئة ثم الفرعية ثم الخطر ← يُرسل باسمه بلا رمز تتبع.
  ضيف آخر: السري يفتح بلا دخول، الخطر أول خانة وإلزامي، بلا اسم ولا هاتف ← يُرسل بلا هوية وبرمز تتبع.
  ضيف: «رأيت هذا؟ بلّغ» من كتاب المخاطر ← صفحة الدخول ومعها الخطر.
الحالة تُقرأ من القاعدة بعد كل إرسال.

حساب الموظف مؤقت بكلمة عشوائية يُنشأ للجولة ويُحذف بعدها مع ما أُرسل.
التشغيل: PYTHONIOENCODING=utf-8 python webkit-72.py [BASE]
"""
import sys, os, re, json, secrets, subprocess
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
SHOT = os.environ.get('SHOT_DIR', '.')
BACKEND = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
TAG = 'بوابة ٧٢'
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


def row(expr):
    """صف واحد من القاعدة بصيغة JSON"""
    out = tinker(f'echo "J:".json_encode({expr});').split('J:')[-1].strip()
    try:
        return json.loads(out)
    except Exception:
        return None


INC = 'App\\Modules\\Incident\\Models\\Incident'
USER = 'App\\Models\\User'
PROFILE = 'App\\Modules\\Governance\\Models\\UserProfile'
UNIT = 'App\\Modules\\Governance\\Models\\OrganizationUnit'
PLACE = 'App\\Modules\\Governance\\Models\\Place'
DBF = 'Illuminate\\Support\\Facades\\DB'
CLEAN = (f'$ids={INC}::where("description","like","%{TAG}%")->pluck("id"); '
         f'foreach(["incident_events","incident_attachments","incident_risks"] as $t) {DBF}::table($t)->whereIn("incident_id",$ids)->delete(); '
         f'foreach($ids as $i) {DBF}::table("app_notifications")->where("url","like","%/app/incidents/".$i)->delete(); '
         f'{INC}::whereIn("id",$ids)->delete(); '
         f'$us={USER}::where("username","g72.e")->pluck("id"); {DBF}::table("app_notifications")->whereIn("user_id",$us)->delete(); '
         f'{PROFILE}::whereIn("user_id",$us)->delete(); {USER}::whereIn("id",$us)->delete(); ')
LAST = (f'{INC}::where("description","like","%{TAG}%")->latest("id")->first()?->only(["id","code","incident_type","actor_id","risk_id","risk_reference_id",'
        '"secret_tracking_code","status","reporter_name","place_id"])')
count = lambda: int(tinker(f'echo "V:".{INC}::where("description","like","%{TAG}%")->count();').split('V:')[-1].strip() or 0)


def ctx_page(browser):
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale='ar-SA')
    page = ctx.new_page(); page.set_default_timeout(120000)
    console = []
    page.on('console', lambda m: console.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: console.append(f'HTTP {r.status} {r.url}') if r.status >= 500 else None)
    return ctx, page, console


def path(url):
    return unquote(url.replace(BASE, ''))


def y(page, sel):
    b = page.locator(sel).first.bounding_box()
    return round(b['y']) if b else None


def choose_risk(page):
    """الفئة ← الفرعية ← الخطر: أول خيار في كل قائمة — يعيد ما اختير وهل ظهر «الإجراء المتوقع»"""
    cat = page.locator('#riskCat option').nth(1).get_attribute('value')
    page.select_option('#riskCat', cat)
    page.wait_for_function("() => !document.getElementById('riskSub').disabled && document.getElementById('riskSub').options.length > 1")
    page.select_option('#riskSub', page.locator('#riskSub option').nth(1).get_attribute('value'))
    page.wait_for_function("() => !document.getElementById('riskId').disabled && document.getElementById('riskId').options.length > 1")
    rid = page.locator('#riskId option').nth(1).get_attribute('value')
    page.select_option('#riskId', rid)
    return int(rid), page.locator('#riskId option').nth(1).inner_text().strip()[:50], page.locator('#riskHint').is_visible()


# التجهيز: موظف مؤقت في «المالية» ومكانه المكاتب الإدارية
seed = tinker(CLEAN + f'$s={USER}::where("username","salama")->firstOrFail(); $u={UNIT}::where("code","fin")->firstOrFail(); '
              f'$x={USER}::create(["username"=>"g72.e","name"=>"موظف بوابة ٧٢","email"=>"g72.e@gate.invalid","password"=>"{PW}"]); '
              f'$p={PROFILE}::create(["user_id"=>$x->id,"role"=>"employee","is_active"=>true,"organization_unit_id"=>$u->id,"place_id"=>{PLACE}::idByCode("HZ-06"),"job_title"=>"موظف"]); $p->approve($s); '
              f'echo "JSON:".json_encode(["emp"=>$x->id,"place"=>{PLACE}::idByCode("HZ-06")]);')
m = re.search(r'JSON:(\{.*\})', seed)
assert m, 'فشل التجهيز: ' + seed[-800:]
S = json.loads(m.group(1))
log('التجهيز المحلي', S)

try:
    with sync_playwright() as p:
        browser = p.webkit.launch()

        # ── ١. العادي: ضيف ← دخول ← النموذج ──
        ctx, page, con = ctx_page(browser)
        page.goto(BASE + '/incident?place=HZ-06', wait_until='networkidle')
        body = page.inner_text('body')
        log('صفحة اختيار النوع: «بحسابك» ولا «بلا تسجيل دخول»، والسري «يخفي هويتك»', [('بحسابك' in body), ('بلا تسجيل دخول' in body), ('يخفي هويتك تماماً' in body)],
            'بحسابك' in body and 'بلا تسجيل دخول' not in body and 'يخفي هويتك تماماً' in body)
        page.screenshot(path=os.path.join(SHOT, '72-types.png'))
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.click('a[data-type="normal"]')
        log('ضيف يضغط «عادي» ← صفحة الدخول ومعها عنوان العودة', path(page.url), '/login' in page.url and 'next=/incident/normal?place=HZ-06' in path(page.url))
        page.fill('input[name=username]', 'g72.e'); page.fill('input[name=password]', PW)
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.click('button[type=submit]')
        log('بعد الدخول يعود إلى النموذج نفسه بمكانه', path(page.url), path(page.url) == '/incident/normal?place=HZ-06')

        yr, yp, yd = y(page, '#riskCat'), y(page, 'select[name=place_id]'), y(page, 'textarea[name=description]')
        place = page.locator('select[name=place_id]').evaluate('e => e.options[e.selectedIndex].textContent.trim()')
        log('النموذج: الخطر أول خانة قبل المكان ثم «ماذا رأيت»، والمكان مختار سلفاً', [yr, yp, yd, place], yr < yp < yd and place.startswith('HZ-06'))
        log('النموذج: بقية الخانات كما هي، وبلا اسم ولا هاتف لصاحب الحساب',
            [page.locator('input[name=location_text]').count(), page.locator('#img').count(), page.locator('input[name=reporter_name]').count()],
            page.locator('input[name=location_text]').count() == 1 and page.locator('#img').count() == 1 and page.locator('input[name=reporter_name]').count() == 0)
        page.screenshot(path=os.path.join(SHOT, '72-normal-form.png'))

        page.fill('textarea[name=description]', f'المقبس بجانب الباب يشرر — {TAG}')
        page.click('#incForm button.btn-lg')
        page.wait_for_timeout(900)
        missing = page.evaluate("() => document.getElementById('riskCat').validity.valueMissing")
        log('بلا خطر: الإرسال يُمنع ولا يُنشأ بلاغ', [missing, path(page.url), count()], missing and path(page.url).startswith('/incident/normal') and count() == 0)

        rid, rname, hint = choose_risk(page)
        page.screenshot(path=os.path.join(SHOT, '72-normal-risk.png'))
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.click('#incForm button.btn-lg')
        a = row(LAST)
        txt = page.inner_text('body')
        log('بالخطر: أُرسل باسمه، مربوطاً بخطره، بلا رمز تتبع', [path(page.url)[:40], rname, a],
            '/incident/success' in page.url and a and a['actor_id'] == S['emp'] and a['risk_reference_id'] == rid and a['secret_tracking_code'] is None and 'أُرسل باسمك' in txt)
        page.screenshot(path=os.path.join(SHOT, '72-normal-sent.png'))
        log('العادي: أخطاء', con or 'صفر', not con)
        ctx.close()

        # ── ٢. السري: بلا دخول ولا هوية، والخطر أول خانة وإلزامي ──
        ctx, page, con = ctx_page(browser)
        r = page.goto(BASE + '/incident/secret?place=HZ-06', wait_until='networkidle')
        yr, yp = y(page, '#riskCat'), y(page, 'select[name=place_id]')
        log('السري: يفتح بلا دخول، الخطر أول خانة، بلا اسم ولا هاتف', [r.status, path(page.url), yr, yp, page.locator('input[name=reporter_name]').count()],
            r.status == 200 and path(page.url).startswith('/incident/secret') and yr < yp and page.locator('input[name=reporter_name]').count() == 0)
        page.fill('textarea[name=description]', f'مقاول يعمل بلا تصريح — {TAG}')
        before = count()
        page.click('#incForm button.btn-lg'); page.wait_for_timeout(900)
        log('السري بلا خطر: الإرسال يُمنع', [page.evaluate("() => document.getElementById('riskCat').validity.valueMissing"), count()], count() == before and path(page.url).startswith('/incident/secret'))
        rid, rname, hint = choose_risk(page)
        page.screenshot(path=os.path.join(SHOT, '72-secret-form.png'))
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.click('#incForm button.btn-lg')
        b = row(LAST)
        code = page.locator('#code').inner_text().strip() if page.locator('#code').count() else ''
        log('السري بالخطر: أُرسل بلا هوية، مربوطاً بخطره، وبرمز تتبع يظهر له', [rname, b, code],
            b and b['incident_type'] == 'secret' and b['actor_id'] is None and b['reporter_name'] is None and b['risk_reference_id'] == rid and b['secret_tracking_code'] == code and len(code) >= 6)
        log('السري: أخطاء', con or 'صفر', not con)

        # ── ٣. من كتاب المخاطر: «رأيت هذا؟ بلّغ» ← الدخول ومعه الخطر ──
        page.goto(BASE + '/hazards', wait_until='networkidle')
        link = page.locator('a[href*="/incident/normal?risk="]').first
        href = link.get_attribute('href')
        page.goto(href if href.startswith('http') else BASE + href, wait_until='domcontentloaded')
        log('ضيف من كتاب المخاطر «رأيت هذا؟ بلّغ» ← صفحة الدخول ومعها الخطر', path(page.url), '/login' in page.url and 'next=/incident/normal?risk=' in path(page.url))
        ctx.close()
        browser.close()
finally:
    tinker(CLEAN + 'echo "ok";')
    left = tinker(f'echo "V:".({INC}::where("description","like","%{TAG}%")->count() + {USER}::where("username","g72.e")->count());').split('V:')[-1].strip()
    log('التنظيف: لم يبقَ من التجهيز شيء', left, left == '0')

print('\n' + ('✗ سقطت: ' + ', '.join(errs) if errs else '✓ بوابة قرار ٧٢ خضراء'))
sys.exit(1 if errs else 0)
