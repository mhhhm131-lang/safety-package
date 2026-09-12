# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١١-٣ — بقية المحوّلات (قرار ٣٤): بلاغات الفحص والطوارئ.

    python tests/gates/gate11_3.py [BASE] [--admin=اسم:كلمة] [--fm=اسم:كلمة] [--tech=اسم:كلمة]

يتحقق على العنوان أن:
  ١) بلاغ فحص فني في نموذج المكاتب (وثيقة ipa-office-form-v10) مُصعَّد من الفني (المستوى ١ ↑) يظهر مهمةً لمدير المرافق
     لا للفني، وزره يفتح النموذج على السطر نفسه (#open=…). لا يُمس أي ملف معهدي — الخلفية تقرأ الوثيقة فقط.
  ٢) حالة حريق في HZ-06 ← خطوة «التحكم بالأنظمة الحرجة» تظهر مهمةً لمدير المرافق بزر «تم» ← بعد «تم» تختفي ← تُنهى الحالة.
يعيد وثيقة النموذج كما كانت.
"""
import re, sys, json, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
FM = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--fm=')), 'marafiq:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
KEY = 'ipa-office-form-v10'
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ''
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    if r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login'): return None
    s.csrf = token(s.get(BASE + '/app', timeout=120).text); return s
def inbox(s):
    h = s.get(BASE + '/app', timeout=120).text
    n = s.get(BASE + '/app/inbox/count', headers={'Accept': 'application/json'}, timeout=120).json().get('count')
    return h, n
def store_get(s, key):
    r = s.get(BASE + f'/api/store/{key}', headers={'Accept': 'application/json'}, timeout=120)
    return r.json() if r.ok else None
def store_put(s, key, data, version):
    return s.put(BASE + f'/api/store/{key}', json={'data': json.dumps(data, ensure_ascii=False), 'version': version},
                 headers={'Accept': 'application/json', 'X-CSRF-TOKEN': s.csrf}, timeout=120)

sa = login(*ADMIN.split(':', 1)); assert sa, 'تعذّر الدخول بحساب المركز — مرّر --admin='
fm = login(*FM.split(':', 1)); assert fm, 'تعذّر الدخول بحساب مدير المرافق — مرّر --fm='
te = login(*TECH.split(':', 1))
b = sa.get(BASE + '/build.txt', timeout=120); log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)'))
dash = sa.get(BASE + '/app/emergency', timeout=120).text
if re.search(r'incidents/\d+/live"[^>]*>[^<]*(?:نشطة|تمت السيطرة)', dash):
    log('0 open incident', 'يوجد حالة مفتوحة — أنهِها أولاً'); sys.exit(1)

# ١) بلاغ فحص مُصعَّد من الفني في وثيقة نموذج المكاتب
orig = store_get(sa, KEY)
data = json.loads(orig['data']) if orig and orig.get('data') else {}
if not isinstance(data, dict): data = {}
reports = [r for r in (data.get('reports') or []) if isinstance(r, dict) and r.get('row') != 'gate113']
import datetime
stamp = (datetime.datetime.now() - datetime.timedelta(hours=30)).strftime('%Y/%m/%d — %H:%M')
reports.append({'row': 'gate113', 'id': 'ب — ٩٩', 'sys': 'الإنذار', 'item': 'بوابة ١١-٣ — طفاية منتهية الصلاحية', 'due': '٢٤ ساعة', 'when': stamp, 'sent': stamp,
                'path': 'إداري', 'levels': {'1': {'up': True, 'back': False, 'by': 'الفني'}}})
data['reports'] = reports
r = store_put(sa, KEY, data, (orig or {}).get('version', 0))
log('1 doc written', (r.status_code, f"version={(orig or {}).get('version', 0)}→{r.json().get('version') if r.ok else '?'}"))
assert r.ok
h, n = inbox(fm); found = 'بوابة ١١-٣' in h and '#open=gate113' in h and 'متأخر' in h
log('1 fm task', (f'count={n}', f'task={found}', 'link=/HZ-06-offices/inspection-form.html#open=gate113'))
assert found
if te:
    ht, nt = inbox(te); log('1 tech does not see it', 'بوابة ١١-٣' not in ht); assert 'بوابة ١١-٣' not in ht
# إعادة الوثيقة كما كانت
cur = store_get(sa, KEY); data['reports'] = [x for x in reports if x.get('row') != 'gate113']
r = store_put(sa, KEY, data, cur.get('version', 0)); log('1 doc restored', r.status_code)
h, n2 = inbox(fm); assert 'بوابة ١١-٣' not in h

# ٢) حريق في HZ-06 ← خطوة مدير المرافق مهمةٌ بزر «تم»
ctrl = sa.get(BASE + '/app/emergency', timeout=120).text
bid = re.search(r'/app/emergency/buildings/(\d+)/control', ctrl).group(1)
form = sa.get(BASE + f'/app/emergency/buildings/{bid}/control?place=HZ-06', timeout=120).text
p06 = re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form).group(1)
r = sa.post(BASE + f'/app/emergency/buildings/{bid}/trigger', data={'_token': token(form), 'incident_type': 'fire', 'severity': 'high', 'place_id': p06}, allow_redirects=True, timeout=120)
eid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url); assert eid, 'التفعيل لم يفتح شاشة الحالة'
eid = eid.group(1); live = r.text
h, n = inbox(fm)
m = re.search(r'خطوتك «التحكم بالأنظمة الحرجة»', h); done = re.search(rf'action="[^"]*/app/emergency/incidents/{eid}/steps/(\d+)/done"', h)
log('2 fm step task', (f'count={n}', f'task={bool(m)}', f'done_button={bool(done)}'))
assert m and done
r = fm.post(BASE + f'/app/emergency/incidents/{eid}/steps/{done.group(1)}/done', data={'_token': token(h)}, allow_redirects=True, timeout=120)
h2, n3 = inbox(fm)
log('2 after done', (r.status_code, f'count {n}→{n3}', 'task_gone=' + str('خطوتك «التحكم بالأنظمة الحرجة»' not in h2)))
assert 'خطوتك «التحكم بالأنظمة الحرجة»' not in h2
hc, _ = inbox(sa); log('2 center manual calls', 'نادِ هاتفياً' in hc)
r = sa.post(BASE + f'/app/emergency/incidents/{eid}/end', data={'_token': token(live), 'final_report': 'بوابة ١١-٣ — انتهى'}, allow_redirects=True, timeout=120)
log('2 end', r.status_code)
print('GATE 11-3 PASSED')
