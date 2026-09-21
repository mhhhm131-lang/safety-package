# -*- coding: utf-8 -*-
"""جولة المرحلة ٢٢ (د) الخطوة ١: تثبيت الجرد بالتشغيل لا بالقراءة.

تفتح حالة طارئة حقيقية ثم تسأل النظام سؤالين عن كل فجوة ادّعاها الجرد:
  (أ) هل الوظيفة تعمل فعلاً من الخلفية؟   (ب) هل لها زر أو شاشة يصل إليها صاحبها؟
كل سطر يطبع: الادعاء | ما حدث فعلاً.

التشغيل: PYTHONIOENCODING=utf-8 python tour-d.py http://127.0.0.1:8089 <كلمة الحسابات التجريبية>
"""
import re, sys, json, requests

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8089'
TPW = sys.argv[2] if len(sys.argv) > 2 else 'trial-d-22'
H = {'Accept': 'application/json'}
OUT = []


def log(claim, actual):
    line = f'{claim} | {actual}'
    OUT.append(line)
    print(line, flush=True)


def sess():
    s = requests.Session()
    s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8'))
    return s


def csrf(s, url):
    h = s.get(BASE + url, timeout=120).text
    m = re.search(r'csrf-token" content="([^"]+)"', h) or re.search(r'name="_token" value="([^"]+)"', h)
    return (m.group(1) if m else ''), h


def login(user, pw):
    s = sess()
    tok, _ = csrf(s, '/login')
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw},
               allow_redirects=False, timeout=120)
    ok = r.status_code == 302 and 'login' not in r.headers.get('Location', '')
    return (s if ok else None)


def api(s, method, url, body=None, page='/app'):
    tok, _ = csrf(s, page)
    r = s.request(method, BASE + url, json=body,
                  headers={**H, 'X-CSRF-TOKEN': tok, 'Content-Type': 'application/json'}, timeout=120)
    try:
        return r.status_code, r.json()
    except Exception:
        return r.status_code, r.text[:160]


def post(s, url, data, page='/app/emergency'):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    f = re.search(r'alert alert-(success|danger)[^>]*>(.*?)</div>', r.text, re.S)
    return r, (f.group(1) + ': ' + re.sub(r'<[^>]+>', '', f.group(2)).strip()[:110]) if f else f'no-flash http={r.status_code}'


def home_text(s):
    return s.get(BASE + '/app', timeout=120).text


# ── الحسابات ──
sa = login('salama', '1234')
assert sa, 'فشل دخول مسؤول السلامة'
emp = login('tj.gm.e1', TPW)
medic = login('tj.hz00.medic', TPW)
munawib = login('tj.munawib', TPW)
log('حسابات التجربة تدخل', f'موظف={bool(emp)} مسعف={bool(medic)} مناوب={bool(munawib)}')
if not emp:
    print('!! كلمة الحسابات التجريبية غير صحيحة — مرّرها وسيطاً ثانياً'); sys.exit(1)

# ── قبل التفعيل: ماذا يرى الموظف؟ ──
h = home_text(emp)
log('الجرد: الموظف لا يرى باب الطوارئ', 'الطوارئ في قائمته؟ ' + str('مركز الطوارئ' in h))
log('الجرد: زر الاستغاثة داخل لوحة المركز فقط', 'زر الذعر في صفحة الموظف الأولى؟ ' + str('panic' in h.lower()))

# ── تفعيل حالة طارئة في مكان الموظف ──
dash = sa.get(BASE + '/app/emergency', timeout=120).text
bid = re.search(r'/app/emergency/buildings/(\d+)/control', dash).group(1)
ctl = sa.get(BASE + f'/app/emergency/buildings/{bid}/control', timeout=120).text
pid = re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', ctl)
pid = pid.group(1) if pid else re.search(r'name="place_id".*?<option value="(\d+)"', ctl, re.S).group(1)
r, f = post(sa, f'/app/emergency/buildings/{bid}/trigger',
            {'place_id': pid, 'incident_type': 'medical', 'severity': 'high',
             'description': 'جولة د: موظف مغمى عليه في المكاتب'},
            f'/app/emergency/buildings/{bid}/control')
code = re.search(r'ط-\d+', r.text)
iid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url) or re.search(r'/app/emergency/incidents/(\d+)/live', r.text)
iid = iid.group(1) if iid else None
log('تفعيل حالة طبية', f'{f} | {code.group(0) if code else "?"} | id={iid}')

# ── أثناء الحالة: الموظف ──
h = home_text(emp)
log('الجرد: الشخص لا شاشة له وقت الحالة',
    'صفحته الأولى تذكر الحالة؟ ' + str(('حالة طارئة' in h) or ('ط-' in h)))

for name, method, url in [
    ('حالتي', 'GET', '/api/emergency/my-status'),
    ('تعليماتي', 'GET', '/api/emergency/instructions'),
    ('أقرب مخرج', 'GET', '/api/emergency/nearest-exit'),
    ('رمزي', 'GET', '/api/emergency/my-qr'),
]:
    st, body = api(emp, method, url)
    has = isinstance(body, dict) and (body.get('success') or body.get('data'))
    log(f'الجرد: «{name}» مبنية بلا شاشة', f'الخلفية ترد {st} وفيها بيانات؟ {bool(has)}')

st, body = api(emp, 'POST', '/api/emergency/check-in', {'assembly_point_id': None, 'status': 'safe'})
log('الجرد: «أسجّل وصولي» مبنية بلا زر', f'الخلفية ترد {st} | {str(body)[:110]}')

st, body = api(emp, 'POST', '/api/emergency/request-help', {'message': 'جولة د: أحتاج مساعدة', 'help_type': 'medical'})
log('الجرد: «أطلب مساعدة» مبنية بلا زر', f'الخلفية ترد {st} | {str(body)[:110]}')

st, body = api(emp, 'POST', '/api/emergency/panic/trigger', {'alert_type': 'medical', 'message': 'جولة د'})
log('الجرد: الاستغاثة مبنية لكل حساب', f'الخلفية ترد {st} | {str(body)[:110]}')

# ── المسعف: هل يرى من طلب المساعدة؟ ──
if medic and iid:
    live = medic.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120)
    txt = live.text if live.ok else ''
    log('الجرد: المسعف لا يرى من طلب مساعدة',
        f'شاشة الحالة {live.status_code} | تذكر «أحتاج مساعدة»؟ {"أحتاج مساعدة" in txt} | تذكر طلبي؟ {"جولة د: أحتاج مساعدة" in txt}')
    st, body = api(medic, 'GET', f'/api/emergency/incidents/{iid}/need-help')
    n = len(body.get('data', [])) if isinstance(body, dict) and isinstance(body.get('data'), list) else '?'
    log('الجرد: «من يحتاج مساعدة» بلا زر', f'الخلفية ترد {st} وعددهم {n}')

# ── الذعر: هل يستطيع المستجيب أن يقول «استلمتُ»؟ ──
st, body = api(munawib or sa, 'GET', '/api/emergency/panic/active')
alert_id = None
if isinstance(body, dict):
    d = body.get('data') or []
    if isinstance(d, list) and d:
        alert_id = d[0].get('id')
log('تنبيه الذعر وصل المركز', f'الخلفية ترد {st} | أول تنبيه id={alert_id}')
if alert_id:
    pdash = (munawib or sa).get(BASE + '/app/emergency/panic', timeout=120).text
    log('الجرد: لا زر «استلمتُ» ولا «أنا في الطريق»',
        f'شاشة الذعر تحمل الزرين؟ استلمتُ={"استلم" in pdash} · في الطريق={"في الطريق" in pdash}')
    st, body = api(munawib or sa, 'POST', f'/api/emergency/panic/{alert_id}/acknowledge', {})
    log('«استلمتُ» من الخلفية', f'{st} | {str(body)[:90]}')

# ── الرسائل الجماعية: الإرسال بلا زر ──
if iid:
    live_sa = sa.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120).text
    log('الجرد: لا زر إرسال رسالة جماعية',
        f'شاشة الحالة تحمل زر إرسال؟ {("messages/send" in live_sa) or ("رسالة للجميع" in live_sa)}')
    st, body = api(sa, 'POST', '/api/emergency/messages/send',
                   {'incident_id': int(iid), 'title': 'جولة د', 'message': 'هل أنت بخير؟',
                    'recipient_type': 'all', 'channels': ['app'], 'requires_response': True})
    log('إرسال رسالة جماعية من الخلفية', f'{st} | {str(body)[:110]}')

# ── النداءات الهاتفية: زر «نوديَ» ──
inbox = (munawib or sa).get(BASE + '/app', timeout=120).text
log('الجرد: «نادِ فلاناً» بلا زر يغلقه',
    f'البطاقة تظهر؟ {"نادِ هاتفياً" in inbox} · فيها زر «نوديَ»؟ {"نودي" in inbox}')

# ── التقرير بعد الحادث ──
if iid:
    post(sa, f'/app/emergency/incidents/{iid}/contain', {'note': 'جولة د'}, f'/app/emergency/incidents/{iid}/live')
    r, f = post(sa, f'/app/emergency/incidents/{iid}/end',
                {'final_report': 'جولة د: انتهت'}, f'/app/emergency/incidents/{iid}/live')
    log('إنهاء الحالة', f)
    rep = sa.get(BASE + f'/app/emergency/incidents/{iid}/report', timeout=120).text
    log('الجرد: لا زر لتقرير ما بعد الحادث',
        f'صفحة التقرير تحمل زر إنشاء تقرير؟ {("aar" in rep.lower()) or ("ما بعد الحادث" in rep)}')
    st, body = api(sa, 'POST', f'/api/emergency/aar/incidents/{iid}', {})
    log('إنشاء تقرير ما بعد الحادث من الخلفية', f'{st} | {str(body)[:110]}')

# ── الملف الطبي ──
st, body = api(emp, 'GET', '/api/emergency/medical/my-profile')
log('الجرد: «ملفي الطبي» لا يصله صاحبه', f'الخلفية ترد {st} | رابطه في صفحته؟ {"medical" in home_text(emp)}')
st, body = api(medic, 'GET', '/api/emergency/medical/critical-info') if medic else (None, None)
log('الجرد: الملف الطبي لا يُقرأ في أي خطوة', f'المسعف يصل للمعلومات الحرجة؟ {st}')

print('\nTOUR-D DONE —', len(OUT), 'سطراً')
