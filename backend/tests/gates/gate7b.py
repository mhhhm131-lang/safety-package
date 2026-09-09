# -*- coding: utf-8 -*-
"""بوابة المرحلة ٧-ب (التقارير) على HTTP: لوحة واحدة للمدير العام بالأرقام الحية.

المسار: المدير العام يفتح اللوحة ← بلاغ حقيقي يُرسل من صفحة الشاغل ← الرقم يتحرك فوراً
(بلا ذاكرة مؤقتة) ← الفني يستلمه فتظهر فجوة الاستجابة بالدقائق ← مرشّحا المكان والمدة
يضيّقان كل الأقسام ← «ما يحتاج قراراً» يعرض المصعَّد ← مدير الإدارة يرى أقل من مسؤول
السلامة ← الفني ممنوع ← التصدير CSV.

التشغيل: PYTHONIOENCODING=utf-8 python gate7b.py http://127.0.0.1:8089
        (الحسابات salama/fani/mudir/idara بكلمة 1234)
"""
import re, sys, time, requests

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TAG = 'بوابة٧ب-' + time.strftime('%H%M')
def log(k, v): print(k, '|', v, flush=True)

H = {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}


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
    msg = (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:120]) if flash \
        else f'no-flash http={r.status_code}'
    return r, msg


def ok(s, url):
    return s.get(BASE + url, allow_redirects=False, timeout=120).status_code


def count_of(html, key):
    """قيمة عدّاد على اللوحة من data-count."""
    m = re.search(r'data-count="' + key + r'">\s*([^<]*?)\s*<', html)
    return (m.group(1).strip() if m else None)


def kpi_of(html, key):
    m = re.search(r'data-kpi="' + key + r'">\s*([^<]*?)\s*<', html)
    return (m.group(1).strip() if m else None)


def place_row(html, code):
    """صف مكان من جدول الأماكن: [بلاغات، حالات طارئة، مخاطر حرجة، تصاريح نشطة]."""
    m = re.search(r'data-place="' + code + r'">(.*?)</tr>', html, re.S)
    if not m:
        return None
    cells = re.findall(r'<td[^>]*>(.*?)</td>', m.group(1), re.S)
    return [re.sub(r'<[^>]+>', '', c).strip() for c in cells[1:]]


sa = login('salama')     # مسؤول السلامة — يرى الكل
fa = login('fani')       # الفني — لا صلاحية تقارير
mu = login('mudir')      # مدير إدارة — يرى وحدته
idara = login('idara')   # الإدارة العليا / المدير العام
g = sess()               # شاغل بلا حساب

b = sa.get(BASE + '/build.txt', timeout=120)
log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) الشاشات الأربع
log('0 screens (salama)', [(u.replace('/app/reports', 'reports') or 'reports', ok(sa, u)) for u in
                           ['/app/reports', '/app/reports/incidents', '/app/reports/risks', '/app/reports/export']])

# ١) الفني ممنوع من الأربع
log('1 fani forbidden', [ok(fa, u) for u in
                         ['/app/reports', '/app/reports/incidents', '/app/reports/risks', '/app/reports/export']])

# ٢) المدير العام يفتح اللوحة ويرى فجوة الاستجابة أولاً
dash0 = idara.get(BASE + '/app/reports', timeout=120).text
log('2 GM dashboard', ('فجوة الاستجابة' in dash0, 'ما يحتاج قراراً' in dash0 or 'لا شيء ينتظر قراراً' in dash0,
                       'بلاغات=' + str(count_of(dash0, 'incidents'))))
before = int(count_of(dash0, 'incidents') or 0)

# ٣) بلاغ حقيقي من صفحة الشاغل (المسار الكامل لا إدراج مباشر)
tok, form = csrf(g, '/incident/normal')
place06 = re.search(r'<option value="(\d+)"[^>]*>[^<]*HZ-06', form) \
    or re.search(r'name="place_id"[\s\S]*?<option value="(\d+)"', form)
cats = re.findall(r'<option value="(\d+)">([^<]+)</option>', form.split('id="riskCat"')[1].split('</select>')[0])
subs = g.get(BASE + f'/incident/api/sub-categories?category_id={cats[0][0]}', headers=H, timeout=120).json()
risks = []
for sc in subs:
    risks = g.get(BASE + f"/incident/api/risks?sub_category_id={sc['id']}", headers=H, timeout=120).json()
    if risks:
        break
r = g.post(BASE + '/incident/normal', data={
    '_token': tok, 'place_id': place06.group(1),
    'location_text': 'الممر الرئيسي قرب المصعد',
    'description': TAG + ' — تسرّب ماء تحت وحدة التكييف في الممر',
    'risk_id': risks[0]['id'], 'reporter_name': 'شاغل البوابة',
}, allow_redirects=False, timeout=120)
track_code = re.search(r'code=([^&\s"]+)', r.headers.get('Location', '') or '').group(1)
# رمز التتبع للشاغل ≠ رمز البلاغ الداخلي (ش-NNNN) الذي يظهر في سجل المركز
track = g.get(BASE + f'/incident/track?code={track_code}', timeout=120).text
inc_code = re.search(r'ش-\d{4}', track).group(0)
log('3 occupant report filed', (r.status_code, track_code, inc_code))

# ٤) الرقم يتحرك **فوراً** — لا ذاكرة مؤقتة (في OHSMS كانت خمس دقائق)
dash1 = idara.get(BASE + '/app/reports', timeout=120).text
after = int(count_of(dash1, 'incidents') or 0)
log('4 live number (no cache)', (f'قبل={before}', f'بعد={after}', after == before + 1))

# ٥) البلاغ لم يصل الفني بعد: يُعدّ في «لم يصل الفني» والمتوسط «لا بيانات» أو رقم سابق
pending_before = kpi_of(dash1, 'incident_pending')
log('5 pending before field receipt', (f'بانتظار={pending_before}', int(pending_before or 0) >= 1))

# ٦) الفني يستلم البلاغ ← فجوة الاستجابة تصير رقماً بالدقائق
lst = sa.get(BASE + '/app/incidents', timeout=120).text
iid = re.search(r'/app/incidents/(\d+)"', lst.split(inc_code)[1]).group(1)
users = sa.get(BASE + '/app/users', timeout=120).text
pos = users.find('>fani<')
fid = re.search(r'/app/users/(\d+)/edit', users[pos if pos > 0 else 0:]).group(1)
# fani بلا مكان في البذرة التجريبية فلا تحويل آلي: المركز يحيله ثم يستلمه الفني
r1, f1 = post(sa, f'/app/incidents/{iid}/refer', {'field_worker_id': fid, 'note': 'للفحص العاجل'}, f'/app/incidents/{iid}')
r2, f2 = post(fa, f'/app/incidents/{iid}/field-receive', {}, f'/app/incidents/{iid}')
dash2 = idara.get(BASE + '/app/reports', timeout=120).text
avg = kpi_of(dash2, 'incident_avg')
log('6 response gap becomes a number', (f1[:32], f2[:32], f'متوسط={avg}', avg not in (None, 'لا بيانات')))

# ٧) مرشّح المكان يضيّق كل الأقسام
only06 = idara.get(BASE + f'/app/reports?place_id={place06.group(1)}', timeout=120).text
rows_all = len(re.findall(r'data-place="', dash2))
rows_one = len(re.findall(r'data-place="', only06))
log('7 place filter', (f'كل الأماكن={rows_all}', f'مكان واحد={rows_one}', rows_one == 1))

# ٨) مرشّح المدة: مدة ماضية لا تحوي البلاغ
past = idara.get(BASE + '/app/reports?from=2020-01-01&to=2020-01-31', timeout=120).text
log('8 date filter', (f'بلاغات={count_of(past, "incidents")}', count_of(past, 'incidents') == '0'))

# ٩) جدول الأماكن يعدّ البلاغ على HZ-06
row = place_row(dash2, 'HZ-06')
log('9 by-place table', (row, row and int(row[0]) >= 1))

# ١٠) نطاق الوحدة: مدير الإدارة لا يرى أكثر من مسؤول السلامة.
#     بلاغ الشاغل بلا وحدة تنظيمية فيراه الاثنان (قاعدة AppliesOrgUnitScope: بلا وحدة = مرئي).
#     تضييق الوحدة نفسه مغطّى في ReportScenarioTest::test_department_manager_sees_only_their_unit.
dash_sa = sa.get(BASE + '/app/reports', timeout=120).text
dash_mu = mu.get(BASE + '/app/reports', timeout=120).text
n_sa = int(count_of(dash_sa, 'incidents') or 0)
n_mu = int(count_of(dash_mu, 'incidents') or 0)
log('10 org-unit scope', (f'مسؤول السلامة={n_sa}', f'مدير الإدارة={n_mu}', n_mu <= n_sa))

# ١١) تقرير البلاغات: الأزمنة والتوزيع وآخر البلاغات
inc_page = idara.get(BASE + '/app/reports/incidents', timeout=120).text
log('11 incidents report', (
    'الوصول إلى الفني' in inc_page,
    f'آخر البلاغات={len(re.findall(chr(100) + "ata-incident=", inc_page))}',
    inc_code in inc_page))

# ١٢) تقرير المخاطر: المصفوفة ٥×٥ وأشد المخاطر
risk_page = idara.get(BASE + '/app/reports/risks', timeout=120).text
cells = len(re.findall(r'data-cell="', risk_page))
log('12 risks report', (f'خلايا المصفوفة={cells}', cells == 25, 'أشد عشرة مخاطر' in risk_page))

# ١٣) «لا بيانات» لا صفر حيث لا سجلات
has_nodata = 'لا بيانات' in dash2
log('13 no-data is not zero', has_nodata)

# ١٤) التصدير CSV بعلامة الترتيب
exp = idara.get(BASE + '/app/reports/export', timeout=120)
log('14 csv export', (exp.status_code, 'csv' in exp.headers.get('Content-Type', ''),
                      exp.content[:3] == b'\xef\xbb\xbf', len(exp.content)))

# ١٥) الضيف يُحوَّل للدخول
log('15 guest redirected', g.get(BASE + '/app/reports', allow_redirects=False, timeout=120).status_code)

log('16 done', 'البوابة اكتملت')
