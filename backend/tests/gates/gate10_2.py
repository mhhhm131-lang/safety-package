# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١٠-٢ — القائمة الحية والتنبيه والتجاوز (BACKEND.md قرار ٣٠، المكوّنات ج + د + هـ).

    python tests/gates/gate10_2.py [BASE] [--admin=اسم:كلمة] [--no-artisan]

يتحقق على العنوان (محلياً أو المنشور) أن:
  ١) تفعيل حريق في HZ-06 يعرض في شاشة التتبع «خطوات الخطة» بـ١٢ صفاً (٣ طبي + ٩ حريق) بعدّاد لكل خطوة.
  ٢) وصول عضو من الفريق الأولي (تسجيل يدوي) يعلّم خطوتَي «التدخل الأولي» آلياً.
  ٣) «تم» على خطوة يسجّلها بالثانية ومن، والسجل الزمني يذكر الفارق عن المستهدف.
  ٤) الأمر المجدول يرصد الخطوات المتجاوزة (سطر أحمر «تجاوز» في السجل) — محلياً بتشغيل الأمر؛ على المنشور يعمل كل دقيقة (يُنتظر).
  ٥) السيطرة تعلّم «تقييم الحريق» آلياً، والإنهاء يعلّم «استعادة التشغيل» آلياً، وتقرير الحالة يفتح.
يترك المبنى فارغاً (الحالة تُنهى).
"""
import re, sys, time, json, subprocess, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
LOCAL = BASE.startswith('http://127.0.0.1') and '--no-artisan' not in sys.argv
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def csrf(s, url):
    h = s.get(BASE + url, timeout=120).text
    m = re.search(r'csrf-token" content="([^"]+)"', h) or re.search(r'name="_token" value="([^"]+)"', h)
    return (m.group(1) if m else ''), h
def login(user, pw):
    s = sess(); tok, _ = csrf(s, '/login')
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    # 302 إلى /login = كلمة خاطئة أو حساب معطَّل (ليس دخولاً) — يُعاد 401 ليُتخطّى دور الحساب بصدق
    if r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login'): return s, 401
    return s, r.status_code
def post(s, url, data, page):
    tok, _ = csrf(s, page); d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    return r, ((flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:160]) if flash else f'no-flash http={r.status_code}')
def strip(h): return unescape(re.sub(r'<[^>]+>', ' ', h))
def rows(h):
    out = []
    for m in re.finditer(r'<tr data-step="(\d+)" data-status="(\w+)"[^>]*>(.*?)</tr>', h, re.S):
        t = re.search(r'<td><strong>(.*?)</strong>(?:<br>|</td>)', m.group(3), re.S)
        title = re.search(r'</td>\s*<td class="text-muted text-nowrap">[^<]*</td>\s*<td><strong>([^<]+)</strong>', m.group(3), re.S)
        out.append({'id': int(m.group(1)), 'status': m.group(2), 'title': title.group(1) if title else '?', 'html': m.group(3)})
    return out

au, ap = ADMIN.split(':', 1); sa, code = login(au, ap)
assert code == 302, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)', f'login={code}', 'artisan=' + str(LOCAL)))

# مبنى المعهد ومكان HZ-06
dash = sa.get(BASE + '/app/emergency', timeout=120).text
bid = re.search(r'/app/emergency/buildings/(\d+)/control', dash)
assert bid, 'لا مبنى في مركز الطوارئ'
bid = bid.group(1); ctrl_url = f'/app/emergency/buildings/{bid}/control'
ctrl = sa.get(BASE + ctrl_url, timeout=120).text
pid = re.search(r'<option value="(\d+)"[^>]*>HZ-06', ctrl)
assert pid, 'لم أجد HZ-06 في قائمة الأماكن'
if 'حالة نشطة' in ctrl or ('إنهاء' in ctrl and '/end' in ctrl):
    log('0 open incident', 'يوجد حالة مفتوحة — أنهِها أولاً'); sys.exit(1)

# ٠) الفريق الأولي من ملف المكان (اللوحة): ترشيح للمكاتب الإدارية إن لم يوجد — عبر /api/store كما تفعل اللوحة (كما بوابة ٤)
H = {'Accept': 'application/json'}
st = sa.get(BASE + '/api/store?keys=ipa-place,ipa-depts', headers=H, timeout=120).json()
docs = st.get('docs') or {}; docs = docs if isinstance(docs, dict) else {}
place_doc = json.loads(docs.get('ipa-place', {}).get('data') or '{}'); ver = docs.get('ipa-place', {}).get('version', 0)
depts = json.loads(docs.get('ipa-depts', {}).get('data') or '[]')
unit = next((d['id'] for d in depts if d.get('place') == 'HZ-06'), '_')
p6 = place_doc.setdefault('HZ-06', {'plans': {}, 'units': {}}); p6.setdefault('units', {})
if not any(any(t.get('name') for t in (u.get('team') or [])) for u in p6['units'].values()):
    p6['units'][unit] = {'team': [
        {'role': 'المنسق', 'name': 'بوابة١٠ — المنسق', 'dept': 'اختبار', 'phone': '0500000001', 'trained': '', 'trainer': ''},
        {'role': 'المسعف', 'name': 'بوابة١٠ — المسعف', 'dept': 'اختبار', 'phone': '0500000002', 'trained': '', 'trainer': ''},
        {'role': 'المنقذ', 'name': 'بوابة١٠ — المنقذ', 'dept': 'اختبار', 'phone': '0500000003', 'trained': '', 'trainer': ''},
        {'role': 'الإطفائي', 'name': 'بوابة١٠ — الإطفائي', 'dept': 'اختبار', 'phone': '', 'trained': '', 'trainer': ''}],
        'nom': {'by': 'مدير الإدارة', 'dept': unit, 'date': '2026-09-10'}, 'appr': {'by': 'مدير الشؤون الإدارية والهندسية', 'date': '2026-09-10'}, 'hr': {}}
    tok, _ = csrf(sa, '/app/emergency')
    r0 = sa.put(BASE + '/api/store/ipa-place', json={'data': json.dumps(place_doc, ensure_ascii=False), 'version': ver}, headers={**H, 'X-CSRF-TOKEN': tok}, timeout=120)
    log('0 team nominated via store', r0.status_code)
    sa.get(BASE + '/app/emergency/teams?place=HZ-06', timeout=120)  # يزامن الفريق المشتق

# ١) التفعيل
r, fl = post(sa, f'/app/emergency/buildings/{bid}/trigger', {'incident_type': 'fire', 'severity': 'high', 'place_id': pid.group(1), 'description': 'بوابة ١٠-٢'}, ctrl_url)
iid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url)
assert iid, fl
iid = iid.group(1); live = f'/app/emergency/incidents/{iid}/live'
h = r.text; rs = rows(h)
paths = re.findall(r'<td class="text-muted text-nowrap">([^<]+)</td>', h)
code_ = re.search(r'(ط-\d+)', h).group(1)
log('1 trigger', (code_, fl[:40], f'steps={len(rs)}', f'medical={paths.count("المسار الطبي")}', f'place={paths.count("مسار المكان")}', 'timers=' + str(h.count('step-timer')), 'pending=' + str(sum(1 for x in rs if x['status'] == 'pending'))))
assert len(rs) == 12 and paths.count('المسار الطبي') == 3 and paths.count('مسار المكان') == 9
api = sa.get(BASE + f'/api/emergency/incidents/{iid}/steps', headers={'Accept': 'application/json'}, timeout=120).json()
log('1 api steps', (len(api['data']), api['data'][0]['status'], 'due=' + str(api['data'][0]['due_at'] is not None)))

# ٢) وصول المسعف (يدوياً) → التدخل الأولي ×٢ آلياً
mem = re.search(r'data-member="medic" data-checkin="(\d+)"', h)
if mem:
    r, fl = post(sa, f'/app/emergency/incidents/{iid}/check-in', {'check_in_id': mem.group(1)}, live)
    rs = rows(r.text)
    done1 = [x for x in rs if x['title'].startswith('التدخل الأولي') and x['status'] == 'done']
    auto = sum(1 for x in done1 if 'آلياً' in x['html'])
    log('2 team arrival', (fl[:40], f'initial_done={len(done1)}/2', f'auto={auto}'))
    assert len(done1) == 2 and auto == 2
else:
    log('2 team arrival', 'skipped: لا فريق أولي مرشَّح لـ HZ-06 في ملف المكان (رشّحه من اللوحة)')

# ٣) «تم» على خطوة التحكم بالأنظمة الحرجة
h = sa.get(BASE + live, timeout=120).text; rs = rows(h)
s3 = next(x for x in rs if x['title'] == 'التحكم بالأنظمة الحرجة')
time.sleep(6)  # لتتجاوز الثانية الأولى (٠–٥ ث) فيظهر الفارق
r, fl = post(sa, f'/app/emergency/incidents/{iid}/steps/{s3["id"]}/done', {}, live)
ev = sa.get(BASE + f'/api/emergency/incidents/{iid}/events?after=0', headers={'Accept': 'application/json'}, timeout=120).json()['data']
line = next((e['message'] for e in ev if 'تمت الخطوة ٣' in e['message']), '')
log('3 done', (fl[:70], 'log=' + line[:90]))
assert 'تمت الخطوة ٣' in fl and 'من التفعيل' in line and ('تأخر' in line or 'ضمن النافذة' in line)
r2, fl2 = post(sa, f'/app/emergency/incidents/{iid}/steps/{s3["id"]}/done', {}, live)
log('3 done twice', fl2[:60]); assert fl2.startswith('danger')

# ٤) التجاوز
if LOCAL:
    out = subprocess.run(['php', 'artisan', 'emergency:check-escalation'], capture_output=True, text=True, encoding='utf-8')
    log('4 artisan', out.stdout.strip()[-60:])
else:
    log('4 wait', 'انتظار دقيقة للمجدول على المنشور'); time.sleep(65)
ev = sa.get(BASE + f'/api/emergency/incidents/{iid}/events?after=0', headers={'Accept': 'application/json'}, timeout=120).json()['data']
over = [e for e in ev if e['message'].startswith('تجاوز:')]
log('4 overdue', (len(over), 'critical=' + str(all(e['severity'] == 'critical' for e in over)), over[0]['message'][:100] if over else '—'))
assert over and all(e['severity'] == 'critical' for e in over)
h = sa.get(BASE + live, timeout=120).text
log('4 screen', ('overdue_badge=' + str('متجاوزة' in h and 'hidden' not in h.split('steps-overdue-badge')[1][:40]), 'red_rows=' + str(h.count('table-danger'))))

# ٥) السيطرة ← تقييم آلياً؛ الإنهاء ← استعادة آلياً؛ التقرير
r, fl = post(sa, f'/app/emergency/incidents/{iid}/contain', {'note': 'أُخمد'}, live)
rs = rows(r.text); ev_ = next(x for x in rs if x['title'] == 'تقييم الحريق')
log('5 contain', (fl[:40], ev_['status'], 'auto=' + str('آلياً' in ev_['html'])))
assert ev_['status'] == 'done' and 'آلياً' in ev_['html']
r, fl = post(sa, f'/app/emergency/incidents/{iid}/end', {'final_report': 'بوابة ١٠-٢ — انتهى'}, live)
rep = sa.get(BASE + f'/app/emergency/incidents/{iid}/report', timeout=120)
h = sa.get(BASE + live, timeout=120).text; rs = rows(h)
rest = next(x for x in rs if 'استعادة' in x['title'])
log('5 end', (r.status_code, f'report={rep.status_code}', rest['status'], 'auto=' + str('آلياً' in rest['html']), 'done=' + str(sum(1 for x in rs if x['status'] == 'done')), 'pending=' + str(sum(1 for x in rs if x['status'] == 'pending')), 'buttons=' + str(h.count('/done"'))))
assert rep.status_code == 200 and rest['status'] == 'done' and h.count('/done"') == 0
print('GATE 10-2 PASSED')
