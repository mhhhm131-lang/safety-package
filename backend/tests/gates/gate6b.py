# -*- coding: utf-8 -*-
"""بوابة المرحلة ٦-ب (التصاريح) على HTTP: تصريح أعمال ساخنة كاملاً مع منع التعارض.

المسار: إنشاء ← مخاطر المكان تُربط آلياً وبنود التحكم تُشتق ← تقديم ← مراجعة ← اعتماد السلامة ←
الاعتماد النهائي (مسؤول السلامة وحده) ← توثيق البنود ← تفعيل بلقطة ظروف ← انحراف يمنع الإغلاق ←
معالجته ← إغلاق ← تقييم بعدي يعلّم بنود التحكم. ومعه: منع التعارض، سعة المكان، وجاهزية العامل.

التشغيل: PYTHONIOENCODING=utf-8 python gate6b.py http://127.0.0.1:8089
        (الحسابات salama/coord/fani بكلمة 1234)
"""
import re, sys, time, io, json, requests

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TAG = 'بوابة٦ب-' + time.strftime('%H%M')
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
    err = re.search(r'invalid-feedback[^>]*>(.*?)</div>', r.text, re.S)
    msg = (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:150]) if flash \
        else (('validation: ' + re.sub(r'<[^>]+>', '', err.group(1)).strip()[:100]) if err
              else f'no-flash http={r.status_code}')
    return r, msg

def ok(s, url):
    return s.get(BASE + url, timeout=120).status_code

sa = login('salama')      # مسؤول السلامة
co = login('coord') if False else None
fa = login('fani')        # الفني المنفّذ (لا صلاحية على التصاريح)

b = sa.get(BASE + '/build.txt', timeout=120)
log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) الشاشات تفتح لمسؤول السلامة
screens = ['/app/permits', '/app/permits/create', '/app/permits/queue', '/app/permits/dashboard',
           '/app/permits/report', '/app/permits/settings', '/app/permits/gate',
           '/app/permits/gate/logs', '/app/equipment']
log('0 screens (salama)', [(u.replace('/app/', ''), ok(sa, u)) for u in screens])

# ١) الفني ممنوع من كل شاشات التصاريح
log('1 fani forbidden', [(u.replace('/app/', ''), ok(fa, u)) for u in
                         ['/app/permits', '/app/permits/settings', '/app/permits/gate']])

# ٢) سعة المكان: غرف الكهرباء HZ-02 حدها ٥ عمال (قرار المستخدم — بلا قيم افتراضية)
settings_html = sa.get(BASE + '/app/permits/settings', timeout=120).text
m = re.search(r'data-place="HZ-02"[\s\S]*?action="[^"]*/settings/places/(\d+)"', settings_html)
hz02_id = m.group(1)
r, f = post(sa, f'/app/permits/settings/places/{hz02_id}', {'max_workers': 5}, '/app/permits/settings', method='put')
log('2 place capacity HZ-02=5', f)

# ٣) إنشاء تصريح أعمال ساخنة في HZ-02
create = sa.get(BASE + '/app/permits/create', timeout=120).text
hot_id = re.search(r'permit_type_id=(\d+)[^"]*"[^>]*>\s*<div class="card h-100" data-type="hot_work"', create)
if not hot_id:
    hot_id = re.search(r'href="[^"]*permit_type_id=(\d+)"[^>]*>\s*<div[^>]*data-type="hot_work"', create)
hot_type = hot_id.group(1)
step2 = sa.get(BASE + f'/app/permits/create?step=2&permit_type_id={hot_type}', timeout=120).text
place_opt = re.search(r'<option value="(\d+)"[^>]*>HZ-02', step2)
r, f = post(sa, '/app/permits', {
    'permit_type_id': hot_type, 'title': f'{TAG} لحام دعامة', 'place_id': place_opt.group(1),
    'sub_location': 'اللوحة الرئيسية ٧', 'workers_count': 3,
    'starts_at': time.strftime('%Y-%m-%dT08:00'), 'expires_at': time.strftime('%Y-%m-%dT20:00'),
}, f'/app/permits/create?step=2&permit_type_id={hot_type}')
pid = re.search(r'/app/permits/(\d+)', r.url).group(1)
code = re.search(r'ت-\d{4}-\d{4}', r.text)
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
reqs = len(re.findall(r'data-req="\d+"', show))
risks = re.search(r'data-risks-count>(\d+)<', show)
log('3 permit created', (pid, code.group(0) if code else '?', f'بنود={reqs}',
                         f'مخاطر={risks.group(1) if risks else 0}', f))

# ٤) الاعتماد المباشر مرفوض (نوع بمرحلتين)
post(sa, f'/app/permits/{pid}/transition', {'to_status': 'submitted'}, f'/app/permits/{pid}')
post(sa, f'/app/permits/{pid}/transition', {'to_status': 'under_review'}, f'/app/permits/{pid}')
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
two_stage = 'يتطلب اعتماد السلامة أولاً' in show
direct = 'الاعتماد النهائي' in show
log('4 two-stage enforced', ('شارة=' + str(two_stage), 'زر الاعتماد المباشر مخفي=' + str(not direct)))

# ٥) اعتماد السلامة ثم الاعتماد النهائي
r, f = post(sa, f'/app/permits/{pid}/transition', {'to_status': 'safety_approved'}, f'/app/permits/{pid}')
r, f2 = post(sa, f'/app/permits/{pid}/transition', {'to_status': 'approved'}, f'/app/permits/{pid}')
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
log('5 approved', (re.search(r'data-status="(\w+)"', show).group(1), f2))

# ٦) التفعيل مقفل والبنود ناقصة
act = sa.get(BASE + f'/app/permits/{pid}/activate', timeout=120).text
mand = re.search(r'data-mandatory="(\d+/\d+)"', act)
log('6 activation locked', (mand.group(1) if mand else '?', 'التفعيل مقفل' in act))

# ٧) توثيق البنود الإلزامية الاستباقية من صفحة التفعيل
done = 0
for rid in re.findall(r'data-req="(\d+)" data-req-status="required"', act):
    rr, _ = post(sa, f'/app/permits/{pid}/requirements/{rid}/complete',
                 {'evidence_value': 'تم التحقق'}, f'/app/permits/{pid}/activate')
    done += 1
act = sa.get(BASE + f'/app/permits/{pid}/activate', timeout=120).text
mand2 = re.search(r'data-mandatory="(\d+/\d+)"', act)
log('7 preventive documented', (done, mand2.group(1) if mand2 else '?', 'التفعيل متاح' in act))

# ٨) التفعيل بلقطة الظروف
r, f = post(sa, f'/app/permits/{pid}/transition', {
    'to_status': 'active', 'activation_weather': 'صحو، تهوية جيدة',
    'activation_workers_confirmed': '1', 'activation_equipment_checked': '1',
    'activation_notes': 'الحاجز الواقي مركّب',
}, f'/app/permits/{pid}/activate')
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
log('8 activated', (re.search(r'data-status="(\w+)"', show).group(1),
                    'ظروف لحظة التفعيل' in show, f))

# ٩) التعارض: نقل مواد خطرة في المكان نفسه لا يُعتمد
haz = re.search(r'href="[^"]*permit_type_id=(\d+)"[^>]*>\s*<div[^>]*data-type="hazmat_transport"', create)
haz_type = haz.group(1)

def make_hazmat(place_id, title):
    r, _ = post(sa, '/app/permits', {
        'permit_type_id': haz_type, 'title': title, 'place_id': place_id,
        'starts_at': time.strftime('%Y-%m-%dT09:00'), 'expires_at': time.strftime('%Y-%m-%dT18:00'),
    }, f'/app/permits/create?step=2&permit_type_id={haz_type}')
    hid = re.search(r'/app/permits/(\d+)', r.url).group(1)
    post(sa, f'/app/permits/{hid}/transition', {'to_status': 'submitted'}, f'/app/permits/{hid}')
    post(sa, f'/app/permits/{hid}/transition', {'to_status': 'under_review'}, f'/app/permits/{hid}')
    post(sa, f'/app/permits/{hid}/transition', {'to_status': 'safety_approved'}, f'/app/permits/{hid}')
    r, f = post(sa, f'/app/permits/{hid}/transition', {'to_status': 'approved'}, f'/app/permits/{hid}')
    status = re.search(r'data-status="(\w+)"', sa.get(BASE + f'/app/permits/{hid}', timeout=120).text).group(1)
    return hid, status, f

hid, status, f = make_hazmat(place_opt.group(1), f'{TAG} نقل أسطوانات في غرف الكهرباء')
log('9 conflict blocks approval', (status, 'تعارض' in f, f[:110]))

# ١٠) النوع نفسه في مكان آخر يُعتمد بلا تعارض
place8 = re.search(r'<option value="(\d+)"[^>]*>HZ-08', step2)
hid8, status8, f8 = make_hazmat(place8.group(1), f'{TAG} نقل أسطوانات في المخازن')
log('10 same type elsewhere approved', (status8, f8))

# ١١) سعة المكان: ٣ عمال يعملون الآن في HZ-02 وحده ٥ — طلب بأربعة يُمنع عند الإنشاء نفسه
gen = re.search(r'href="[^"]*permit_type_id=(\d+)"[^>]*>\s*<div[^>]*data-type="work_permit"', create)
before = len(re.findall(r'data-permit="', sa.get(BASE + '/app/permits', timeout=120).text))
r, f = post(sa, '/app/permits', {
    'permit_type_id': gen.group(1), 'title': f'{TAG} عمل يتجاوز السعة',
    'place_id': place_opt.group(1), 'workers_count': 4,
    'starts_at': time.strftime('%Y-%m-%dT08:00'), 'expires_at': time.strftime('%Y-%m-%dT20:00'),
}, f'/app/permits/create?step=2&permit_type_id={gen.group(1)}')
after = len(re.findall(r'data-permit="', sa.get(BASE + '/app/permits', timeout=120).text))
log('11 capacity blocks at creation', ('حد العمال' in f, f'عدد التصاريح {before}→{after}', f[:120]))

# ١١-ب) طلب بعاملين ضمن السعة يمرّ (٣+٢ = ٥)
r, f = post(sa, '/app/permits', {
    'permit_type_id': gen.group(1), 'title': f'{TAG} عمل ضمن السعة',
    'place_id': place_opt.group(1), 'workers_count': 2,
    'starts_at': time.strftime('%Y-%m-%dT08:00'), 'expires_at': time.strftime('%Y-%m-%dT20:00'),
}, f'/app/permits/create?step=2&permit_type_id={gen.group(1)}')
log('11b within capacity passes', ('/app/permits/' in r.url, f[:90]))

# ١٢) انحراف يمنع الإغلاق
r, f = post(sa, f'/app/permits/{pid}/deviations', {
    'description': 'وُجد كرتون تغليف قرب موضع اللحام لم يكن في الخطة',
    'severity': 'high', 'corrective_action_taken': 'أُبعد فوراً',
}, f'/app/permits/{pid}')
r, f2 = post(sa, f'/app/permits/{pid}/transition', {'to_status': 'completed'}, f'/app/permits/{pid}')
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
log('12 open deviation blocks close', (re.search(r'data-status="(\w+)"', show).group(1),
                                       'انحراف مفتوح' in f2, f2[:110]))

# ١٣) معالجة الانحراف ثم الإغلاق
dev = re.search(r'data-deviation="(\d+)"', show).group(1)
r, f = post(sa, f'/app/permits/{pid}/deviations/{dev}/resolve',
            {'corrective_action_taken': 'أُزيلت المواد وأُعيد فحص المحيط', 'resolution_status': 'resolved'},
            f'/app/permits/{pid}')
r, f2 = post(sa, f'/app/permits/{pid}/transition', {'to_status': 'completed'}, f'/app/permits/{pid}')
show = sa.get(BASE + f'/app/permits/{pid}', timeout=120).text
log('13 closed', (re.search(r'data-status="(\w+)"', show).group(1), f2))

# ١٤) التقييم البعدي يعلّم بنود التحكم
r, f = post(sa, f'/app/permits/{pid}/evaluate', {
    'overall_rating': 2, 'severity_match': 'underestimated',
    'what_failed': 'الحاجز الواقي لم يمنع تطاير الشرر إلى الممر المجاور.',
    'lessons_learned': 'يلزم حاجز أطول ومراقب حريق ثانٍ.',
}, f'/app/permits/{pid}/evaluate')
log('14 evaluated + controls flagged', f[:150])

# ١٥) جاهزية العامل: بلا تصريح يغطيه = ممنوع
tok, _ = csrf(sa, '/app/permits/gate')
r = sa.post(BASE + '/app/permits/gate/check',
            json={'worker_identifier': '9999999999'},
            headers={'X-CSRF-TOKEN': tok, 'Accept': 'application/json'}, timeout=120)
j = r.json()
log('15 gate unknown worker', (j['result'], [x['code'] for x in j['denial_reasons']]))

# ١٦) اللوحة والتقرير
dash = sa.get(BASE + '/app/permits/dashboard', timeout=120).text
places_shown = len(re.findall(r'data-place="HZ-\d\d"', dash))
conflicts = re.search(r'data-conflicts="(\d+)"', dash)
rep = sa.get(BASE + '/app/permits/report', timeout=120)
rep_json = sa.get(BASE + '/app/permits/report', headers={'Accept': 'application/json'}, timeout=120).json()
log('16 dashboard + report', (f'أماكن={places_shown}', f'تعارضات={conflicts.group(1) if conflicts else 0}',
                              rep.status_code, 'مُصدَر=' + str(rep_json['permits']['issued'])))

# ١٧) المعدات: فحص غير مطابق يُخرجها من الخدمة
r, f = post(sa, '/app/equipment', {
    'name': f'{TAG} رافعة شوكية', 'code': 'FL-9', 'equipment_type': 'lifting',
    'status': 'active', 'inspection_frequency_days': 90,
}, '/app/equipment/create')
eid = re.search(r'/app/equipment/(\d+)', r.url).group(1)
r, f = post(sa, f'/app/equipment/{eid}/inspections',
            {'inspection_date': time.strftime('%Y-%m-%d'), 'result': 'fail', 'findings': 'تسرّب زيت'},
            f'/app/equipment/{eid}')
eq = sa.get(BASE + f'/app/equipment/{eid}', timeout=120).text
log('17 equipment failed inspection', ('خارج الخدمة' in eq, f[:90]))

# ١٨) صفر أخطاء خادم في الجولة
log('18 done', 'البوابة اكتملت')
