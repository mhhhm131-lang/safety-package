# -*- coding: utf-8 -*-
"""بوابة المرحلة ٧ (النماذج الرقمية) على HTTP: إقرار من خطر ← تكليف ← توقيع ← نتائج ← متأخرون.

المسار: توليد نموذج من خطر شديد (النوع يُشتق من الدرجة) ← التكليف بالمكان ← غير المكلَّف يُمنع ←
المكلَّف يعبّئ بتوقيع مرسوم ← التعبئة تُحفظ فعلاً (وهذا ما كان معطوباً في OHSMS) ← لا تعبئة مرتين ←
النتائج والتصدير ← تكليف بمهلة ماضية ← أمر «المتأخرون» يعلّمه ويُشعر.

التشغيل: PYTHONIOENCODING=utf-8 python gate7.py http://127.0.0.1:8089
        (الحسابات salama/fani/mudir بكلمة 1234)
"""
import re, sys, time, io, base64, requests

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TAG = 'بوابة٧-' + time.strftime('%H%M')
def log(k, v): print(k, '|', v, flush=True)

def sess():
    s = requests.Session()
    s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8'))
    return s

def csrf(s, url):
    h = s.get(BASE + url, timeout=120).text
    m = re.search(r'csrf-token" content="([^"]+)"', h) or re.search(r'name="_token" value="([^"]+)"', h)
    return (m.group(1) if m else ''), h

def login(user, pw='1234'):
    s = sess(); tok, _ = csrf(s, '/login')
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw},
               allow_redirects=False, timeout=120)
    assert r.status_code == 302 and 'login' not in r.headers.get('Location', ''), (user, r.status_code)
    return s

def post(s, url, data, page, files=None, method='post'):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    if method != 'post': d['_method'] = method.upper()
    r = s.post(BASE + url, data=d, files=files, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    err = re.search(r'text-danger small mt-1">(.*?)</div>|invalid-feedback[^>]*>(.*?)</div>', r.text, re.S)
    msg = (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:150]) if flash \
        else (('validation: ' + re.sub(r'<[^>]+>', '', (err.group(1) or err.group(2))).strip()[:110]) if err
              else f'no-flash http={r.status_code}')
    return r, msg

def ok(s, url):
    return s.get(BASE + url, timeout=120).status_code

sa = login('salama')     # مسؤول السلامة
fa = login('fani')       # الفني — مكانه HZ-06 في البذرة التجريبية
mu = login('mudir')      # مدير إدارة

b = sa.get(BASE + '/build.txt', timeout=120)
log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) الشاشات
log('0 screens (salama)', [(u.replace('/app/', ''), ok(sa, u)) for u in
                           ['/app/forms', '/app/forms/create', '/app/forms/generate', '/app/forms/mine']])
log('1 fani limited', [('forms', ok(fa, '/app/forms')), ('mine', ok(fa, '/app/forms/mine'))])

# ٢) خطر فعّال شديد في مكان الفني (HZ-06) — يُفعَّل من السجل العام عبر شجرته
place_code = 'HZ-06'
api = '/app/risk/registry/tree/reference'
cats = sa.get(BASE + api + '/categories', headers={'Accept': 'application/json'}, timeout=120).json()
cat_id = (cats[0]['id'] if isinstance(cats, list) else cats['data'][0]['id'])
subs = sa.get(BASE + f'{api}/sub-categories/{cat_id}', headers={'Accept': 'application/json'}, timeout=120).json()
sub_id = (subs[0]['id'] if isinstance(subs, list) else subs['data'][0]['id'])
risks = sa.get(BASE + f'{api}/risks-by-sub-category/{sub_id}', headers={'Accept': 'application/json'}, timeout=120).json()
ref_id = (risks[0]['id'] if isinstance(risks, list) else risks['data'][0]['id'])

activate_page = sa.get(BASE + f'/app/risk/{ref_id}/activate', timeout=120).text
unit_opt = re.search(r'name="organization_unit_id"[\s\S]*?<option value="(\d+)"', activate_page)
place_opt = re.search(r'name="place_id"[\s\S]*?<option value="(\d+)"[^>]*>[^<]*' + place_code, activate_page)
r, f = post(sa, f'/app/risk/{ref_id}/activate', {
    'organization_unit_id': unit_opt.group(1), 'place_id': place_opt.group(1),
    'severity': 5, 'likelihood': 4, 'scope_type': 'general',
}, f'/app/risk/{ref_id}/activate')
log('2 active risk seeded (5×4=20)', f[:110])

# ٣) توليد نموذج من المخاطر — النوع يُشتق من أشدّ درجة
gen = sa.get(BASE + '/app/forms/generate', timeout=120).text
risk_ids = re.findall(r'name="risk_ids\[\]" value="(\d+)"[^>]*data-score="(\d+)"', gen)
top = max(risk_ids, key=lambda x: int(x[1])) if risk_ids else None
r, f = post(sa, '/app/forms/generate', {
    'title': f'{TAG} إقرار بمخاطر المكان', 'risk_ids[]': top[0],
}, '/app/forms/generate')
fid = re.search(r'/app/forms/(\d+)', r.url).group(1)
show = sa.get(BASE + f'/app/forms/{fid}', timeout=120).text
ftype = re.search(r'data-form-type="(\w+)"', show)
fields = len(re.findall(r'data-field="\d+"', show))
log('3 form generated from risk', (fid, f'أشدّ درجة={top[1]}', f'النوع={ftype.group(1) if ftype else "?"}',
                                    f'حقول={fields}', f[:80]))

# ٤) المقدمة تعرض الخطر وضوابطه
has_intro = 'المقدمة التي يقرؤها المكلَّف' in show
log('4 intro shows risk + controls', has_intro)

# ٤-ب) ضبط مكان الفني من شاشة المستخدمين (البذرة التجريبية بلا مكان) — شرط التكليف بالمكان
users = sa.get(BASE + '/app/users', timeout=120).text
# صف الفني: من موضع اسم المستخدم إلى أول رابط تعديل بعده
pos = users.find('>fani<')
if pos < 0:
    pos = users.find('fani')
fani_id = re.search(r'/app/users/(\d+)/edit', users[pos:]).group(1)
edit_page = sa.get(BASE + f'/app/users/{fani_id}/edit', timeout=120).text
place_user = re.search(r'name="place_id"[\s\S]*?<option value="(\d+)"[^>]*>[^<]*' + place_code, edit_page)
r, f = post(sa, f'/app/users/{fani_id}', {
    'username': 'fani', 'name': 'الفني المنفّذ', 'role': 'field_worker',
    'place_id': place_user.group(1),
}, f'/app/users/{fani_id}/edit', method='put')
log('4b fani place set to ' + place_code, f[:90])

# ٥) التكليف بالمكان + مهلة
send = sa.get(BASE + f'/app/forms/{fid}/send', timeout=120).text
place_send = re.search(r'name="place_id"[\s\S]*?<option value="(\d+)"[^>]*>' + place_code, send)
due = time.strftime('%Y-%m-%d', time.localtime(time.time() + 3 * 86400))
r, f = post(sa, f'/app/forms/{fid}/send', {
    'mode': 'place', 'place_id': place_send.group(1), 'due_date': due,
}, f'/app/forms/{fid}/send')
track = sa.get(BASE + f'/app/forms/{fid}/tracking', timeout=120).text
assigned = len(re.findall(r'data-assignment="\d+"', track))
log('5 assigned by place', (f'مكلَّفون={assigned}', f[:90]))

# ٦) غير المكلَّف ممنوع، والمكلَّف يرى النموذج في «نماذجي»
mine = fa.get(BASE + '/app/forms/mine', timeout=120).text
log('6 assignment visibility', ('mudir يُمنع', ok(mu, f'/app/forms/{fid}/fill'),
                                'fani يرى', TAG in mine, 'fill', ok(fa, f'/app/forms/{fid}/fill')))

# ٧) الإرسال بلا إقرار ولا توقيع مرفوض — ولا تُسجَّل تعبئة
fill = fa.get(BASE + f'/app/forms/{fid}/fill', timeout=120).text
ack_id = re.search(r'data-field="(\d+)" data-field-type="acknowledge"', fill)
sig_ids = re.findall(r'data-field="(\d+)" data-field-type="signature"', fill)
txt_ids = re.findall(r'data-field="(\d+)" data-field-type="text"', fill)
r, f = post(fa, f'/app/forms/{fid}/fill', {}, f'/app/forms/{fid}/fill')
res_before = sa.get(BASE + f'/app/forms/{fid}/results', timeout=120).text
subs_before = len(re.findall(r'data-submission="\d+"', res_before))
log('7 empty submit rejected', (f[:80], f'تعبئات={subs_before}'))

# ٨) التعبئة الكاملة بتوقيع مرسوم (PNG صغير كما يرسله المتصفح)
png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
payload = {f'fields[{ack_id.group(1)}]': '1'}
for t in txt_ids:
    payload[f'fields[{t}]'] = 'م. سعد — مشرف السلامة'
for sg in sig_ids:
    payload[f'signatures[{sg}]'] = png
r, f = post(fa, f'/app/forms/{fid}/fill', payload, f'/app/forms/{fid}/fill')
log('8 submitted with signature', ('سُجّلت' in f or 'شكراً' in f, f[:90]))

# ٩) التعبئة حُفظت فعلاً (الخلل الأصلي في OHSMS: fields[] مقابل answers[] و submitted_at بلا عمود)
res = sa.get(BASE + f'/app/forms/{fid}/results', timeout=120).text
subs = len(re.findall(r'data-submission="\d+"', res))
has_sig_link = 'التوقيع' in res
log('9 submission stored', (f'تعبئات={subs}', 'رابط التوقيع', has_sig_link))

# ١٠) لا تعبئة مرتين
log('10 no double fill', ok(fa, f'/app/forms/{fid}/fill'))

# ١١) التصدير CSV
exp = sa.get(BASE + f'/app/forms/{fid}/export', timeout=120)
log('11 csv export', (exp.status_code, 'csv' in exp.headers.get('Content-Type', ''), len(exp.content)))

# ١٢) تكليف ثانٍ بالدور (وضع آخر من أوضاع التكليف الأربعة) بمهلة اليوم
r, f = post(sa, f'/app/forms/{fid}/send', {
    'mode': 'role', 'role': 'department_manager', 'due_date': time.strftime('%Y-%m-%d'),
}, f'/app/forms/{fid}/send')
track2 = sa.get(BASE + f'/app/forms/{fid}/tracking', timeout=120).text
log('12 assigned by role', (f'مكلَّفون={len(re.findall(chr(100)+"ata-assignment=", track2))}', f[:70]))

# ١٣) المتأخرون: تُرجَع المهلة يوماً (كما لو مضت) ثم يُشغَّل الأمر المجدول
import subprocess
def artisan(*args):
    return subprocess.run(['php', 'artisan', *args], capture_output=True, text=True,
                          encoding='utf-8', errors='replace', timeout=180)

artisan('tinker', '--execute',
        'App\\Modules\\Form\\Models\\FormAssignment::where("status","pending")'
        '->update(["due_date" => now()->subDay()->toDateString()]);')
cmd = artisan('forms:check-overdue')
log('13 overdue command', (cmd.stdout or '').strip().splitlines()[-1] if cmd.stdout else cmd.returncode)

# ١٤) شاشة المتابعة والنسبة
track = sa.get(BASE + f'/app/forms/{fid}/tracking', timeout=120).text
pct = re.search(r'data-response-pct="(\d+)"', track)
statuses = re.findall(r'data-status="(\w+)"', track)
log('14 tracking', (f'نسبة الاستجابة={pct.group(1) if pct else "?"}%', dict((s, statuses.count(s)) for s in set(statuses))))

log('15 done', 'البوابة اكتملت')
