# -*- coding: utf-8 -*-
"""بوابة المرحلة ٤ (الطوارئ) على HTTP: تفعيل ← تنبيه الفريق الأولي (من ملف المكان) ← تسجيل الوصول ← انتهاء ← تقرير، ثم تمرين كامل.
التشغيل: PYTHONIOENCODING=utf-8 python gate4.py https://ipa-safety.onrender.com  (الحسابات التجريبية salama/fani/idara بكلمة 1234)."""
import re, sys, json, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://ipa-safety.onrender.com'
H = {'Accept': 'application/json'}
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
    assert r.status_code == 302 and 'login' not in r.headers.get('Location', ''), (user, r.status_code)
    return s
def post(s, url, data, page='/app/emergency'):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger)[^>]*>(.*?)</div>', r.text, re.S)
    return r, (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:140]) if flash else f'no-flash http={r.status_code}'
def api(s, method, url, body=None, page='/app/emergency'):
    tok, _ = csrf(s, page)
    r = s.request(method, BASE + url, json=body, headers={**H, 'X-CSRF-TOKEN': tok, 'Content-Type': 'application/json'}, timeout=120)
    try: return r.status_code, r.json()
    except Exception: return r.status_code, r.text[:200]

sa = login('salama', '1234')
b = sa.get(BASE + '/build.txt', timeout=120); log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) المبنى ونقطة تجمع (إن لم توجد) — الطوابق والمخارج بيد المستخدم (الفجوتان ٣ و٦)
dash = sa.get(BASE + '/app/emergency', timeout=120).text
bid = re.search(r'/app/emergency/buildings/(\d+)/control', dash).group(1)
log('emergency dashboard + main building', (200, bid))
show = sa.get(BASE + f'/app/emergency/buildings/{bid}', timeout=120).text
if 'لا نقاط تجمع بعد' in show:
    r, f = post(sa, f'/app/emergency/buildings/{bid}/assembly-points', {'code': 'A1', 'name': 'الساحة الأمامية', 'is_primary': '1', 'capacity': '300'}, f'/app/emergency/buildings/{bid}')
    log('assembly point A1 created', f)

# ١) الفريق الأولي من ملف المكان (اللوحة): نضيف ترشيحاً للمكاتب الإدارية إن لم يوجد، عبر /api/store كما تفعل اللوحة
st = sa.get(BASE + '/api/store?keys=ipa-place,ipa-depts', headers=H, timeout=120).json()
docs = st.get('docs') or {}; docs = docs if isinstance(docs, dict) else {}
place_doc = json.loads(docs.get('ipa-place', {}).get('data') or '{}'); ver = docs.get('ipa-place', {}).get('version', 0)
depts = json.loads(docs.get('ipa-depts', {}).get('data') or '[]')
unit = next((d['id'] for d in depts if d.get('place') == 'HZ-06'), '_')
p6 = place_doc.setdefault('HZ-06', {'plans': {}, 'units': {}}); p6.setdefault('units', {})
if not any(t.get('name') for t in (p6['units'].get(unit, {}).get('team') or [])):
    p6['units'][unit] = {'team': [
        {'role': 'المنسق', 'name': 'بوابة٤ — المنسق', 'dept': 'اختبار', 'phone': '0500000001', 'trained': '2026-02-01', 'trainer': 'الدفاع المدني'},
        {'role': 'المسعف', 'name': 'بوابة٤ — المسعف', 'dept': 'اختبار', 'phone': '0500000002', 'trained': '', 'trainer': ''},
        {'role': 'المنقذ', 'name': 'بوابة٤ — المنقذ', 'dept': 'اختبار', 'phone': '0500000003', 'trained': '', 'trainer': ''},
        {'role': 'الإطفائي', 'name': 'بوابة٤ — الإطفائي', 'dept': 'اختبار', 'phone': '', 'trained': '', 'trainer': ''}],
        'nom': {'by': 'مدير الإدارة', 'dept': unit, 'date': '2026-09-08'}, 'appr': {'by': 'مدير الشؤون الإدارية والهندسية', 'date': '2026-09-08'}, 'hr': {}}
    tok, _ = csrf(sa, '/app/emergency')
    r = sa.put(BASE + '/api/store/ipa-place', json={'data': json.dumps(place_doc, ensure_ascii=False), 'version': ver}, headers={**H, 'X-CSRF-TOKEN': tok}, timeout=120)
    log('ipa-place team nominated via store', r.status_code)
teams = sa.get(BASE + '/app/emergency/teams?place=HZ-06', timeout=120).text
medic = re.search(r'المسعف: ([^<،]+)', teams)
log('teams screen shows derived initial team for HZ-06', (('الفريق الأولي' in teams), medic.group(1).strip() if medic else None))

# ٢) التفعيل: حريق في المكاتب الإدارية
ctrl = sa.get(BASE + f'/app/emergency/buildings/{bid}/control', timeout=120).text
p06 = re.search(r'<option value="(\d+)"[^>]*>HZ-06', ctrl).group(1)
if 'حالة نشطة' in ctrl or 'إنهاء' in ctrl and '/end' in ctrl:
    log('WARNING', 'an incident is already open — end it first'); sys.exit(1)
r, f = post(sa, f'/app/emergency/buildings/{bid}/trigger', {'incident_type': 'fire', 'severity': 'high', 'place_id': p06, 'description': 'بوابة ٤ — تفعيل تجريبي على المنشور'}, f'/app/emergency/buildings/{bid}/control')
iid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url or '').group(1)
live = r.text
code = re.search(r'ط-\d{4}', live).group(0)
log('trigger → live page', (code, f))
log('team notified + manual phone calls listed', ('الفريق الأولي — الوصول' in live, 'يُنادى هاتفياً' in live, 'المسعف' in live))
log('escalation/ack state', ('لم يُقرّ أحد بعد' in live))

# ٣) الوصول: الفني يرى؛ المدير العام يرى ولا يفعّل؛ الإقرار؛ وصول المسعف يدوياً؛ مسح رمز الفني
fa = login('fani', '1234'); ex = login('idara', '1234')
log('tech sees live page', fa.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120).status_code)
log('exec sees live page', ex.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120).status_code)
tok, _ = csrf(ex, '/app/emergency')
log('exec cannot trigger (403)', ex.post(BASE + f'/app/emergency/buildings/{bid}/trigger', data={'_token': tok, 'incident_type': 'fire', 'severity': 'high', 'place_id': p06}, allow_redirects=False, timeout=120).status_code)
r, f = post(sa, f'/app/emergency/incidents/{iid}/acknowledge', {}, f'/app/emergency/incidents/{iid}/live'); log('acknowledge', f)
live = sa.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120).text
m = re.search(r'data-member="medic" data-checkin="(\d+)"', live)
if m:
    r, f = post(sa, f'/app/emergency/incidents/{iid}/check-in', {'check_in_id': m.group(1)}, f'/app/emergency/incidents/{iid}/live'); log('medic arrival recorded (manual)', f)
st, j = api(fa, 'GET', '/api/emergency/my-qr'); log('tech my-qr (content, no server image)', (st, j.get('has_active_incident'), bool(j.get('data', {}).get('qr_content'))))
st, j = api(sa, 'POST', '/api/emergency/verify-qr', {'qr_token': j['data']['qr_token']}); log('center scans tech QR', (st, j.get('message'), j.get('data', {}).get('person_name')))
st, j = api(sa, 'GET', f'/api/emergency/incidents/{iid}/stats'); log('live stats', (st, {k: j['data'][k] for k in ('total', 'safe', 'team_total', 'team_arrived')}))
ev = sa.get(BASE + f'/api/emergency/incidents/{iid}/events?after=0', headers=H, timeout=120).json()
log('event types so far', [e['type'] for e in ev['data']])

# ٤) السيطرة ثم الانتهاء بتقرير — الفني لا يُنهي
tok, _ = csrf(fa, '/app/emergency')
log('tech cannot end (403)', fa.post(BASE + f'/app/emergency/incidents/{iid}/end', data={'_token': tok, 'final_report': 'x'}, allow_redirects=False, timeout=120).status_code)
r, f = post(sa, f'/app/emergency/incidents/{iid}/contain', {'note': 'أُطفئ الحريق'}, f'/app/emergency/incidents/{iid}/live'); log('contain', f)
r, f = post(sa, f'/app/emergency/incidents/{iid}/end', {'final_report': 'بوابة ٤: حريق تجريبي، أُخمد، لا إصابات.'}, f'/app/emergency/incidents/{iid}/live'); log('end → report page', (f, '/report' in r.url))
rep = r.text
log('report shows timeline + team + final report', ('تشغيل الإنذار' in rep, 'انتهى الخطر' in rep, 'المسعف' in rep, 'أُخمد' in rep))
log('building back to all-clear', 'انتهى الخطر' in sa.get(BASE + f'/app/emergency/buildings/{bid}', timeout=120).text)

# ٥) تمرين كامل بالمسار نفسه
r, f = post(sa, '/app/emergency/drills', {'building_id': bid, 'place_id': p06, 'drill_type': 'fire', 'scheduled_at': '2026-12-01 09:00', 'scenario': 'بوابة ٤ — تمرين', 'target_time_sec': '300'}, '/app/emergency/drills/create')
drills = sa.get(BASE + '/app/emergency/drills', timeout=120).text
did = re.findall(r'/app/emergency/drills/(\d+)/start', drills)[-1]
r, f = post(sa, f'/app/emergency/drills/{did}/start', {}, '/app/emergency/drills'); log('drill start → live (is_drill)', ('تمرين' in r.text, f))
diid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url).group(1)
r, f = post(sa, f'/app/emergency/drills/{did}/end', {'observations': 'بوابة ٤'}, '/app/emergency/drills'); log('drill end + score', f)
log('drill incident ended', 'انتهت' in sa.get(BASE + f'/app/emergency/incidents/{diid}/report', timeout=120).text)
print('GATE4 DONE')
