# -*- coding: utf-8 -*-
"""بوابة المرحلة ٥ (إنترنت الأشياء) على HTTP: تسجيل لوحة إنذار (webhook) ← إنذار محاكى موقّع بـ HMAC ← حالة طارئة خلال ثانية
← توقيع خاطئ يُرفض ← إنذار ثانٍ يُسجَّل في الحالة نفسها ← إنهاء ← حذف جهاز الاختبار.
التشغيل: PYTHONIOENCODING=utf-8 python gate5.py https://ipa-safety.onrender.com  (الحساب التجريبي salama بكلمة 1234)."""
import re, sys, json, time, hmac, hashlib, requests
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
def post(s, url, data, page='/app/emergency/iot'):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    return r, (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:140]) if flash else f'no-flash http={r.status_code}'
def api(s, method, url, body=None, page='/app/emergency/iot'):
    tok, _ = csrf(s, page)
    r = s.request(method, BASE + url, json=body, headers={**H, 'X-CSRF-TOKEN': tok, 'Content-Type': 'application/json'}, timeout=120)
    try: return r.status_code, r.json()
    except Exception: return r.status_code, r.text[:200]
def webhook(device_id, secret, payload, ts=None):
    body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
    sig = 'sha256=' + hmac.new(secret.encode('utf-8'), body, hashlib.sha256).hexdigest()
    h = {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-IPA-Signature': sig}
    if ts: h['X-IPA-Timestamp'] = str(ts)
    t = time.perf_counter()
    r = requests.post(BASE + f'/api/iot/webhooks/{device_id}', data=body, headers=h, timeout=120)   # بلا جلسة — الجهاز لا يملك حساباً
    dt = time.perf_counter() - t
    try: j = r.json()
    except Exception: j = r.text[:200]
    return r.status_code, j, round(dt, 3)

sa = login('salama', '1234')
b = sa.get(BASE + '/build.txt', timeout=120); log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) لوحة الأنظمة ومبنى المعهد
dash = sa.get(BASE + '/app/emergency/iot', timeout=120)
log('iot dashboard', (dash.status_code, 'أنظمة المبنى' in dash.text))
bid = re.search(r'<option value="(\d+)"', dash.text).group(1)
if 'حالة نشطة' in sa.get(BASE + f'/app/emergency/buildings/{bid}/control', timeout=120).text:
    log('WARNING', 'an incident is already open — end it first'); sys.exit(1)
st, j = api(sa, 'GET', f'/api/iot/buildings/{bid}/status')
log('status api (systems enabled)', (st, {k: v['enabled'] for k, v in j['systems'].items()}))

# ١) تسجيل لوحة إنذار محاكاة (webhook فقط) بخريطة مناطق ← أماكن، والمفتاح يظهر مرة واحدة
r, f = post(sa, '/app/emergency/iot/devices', {'kind': 'fire_panel', 'name': 'بوابة٥ — لوحة محاكاة', 'building_id': bid, 'protocol': 'webhook', 'scheme': 'http',
    'config': '{"zones": {"3": "HZ-06", "7": "HZ-01"}}', 'fire_zones': '8', 'is_enabled': '1'}, '/app/emergency/iot/devices/create')
did = re.search(r'/app/emergency/iot/devices/(\d+)', r.url).group(1)
secret = re.search(r'id="secret"[^>]*>([^<]+)<', r.text).group(1).strip()
log('device registered + secret shown once', (did, len(secret), f))
log('device page shows webhook url', f'/api/iot/webhooks/{did}' in r.text)

# ٢) البوابة: إنذار موقّع ← حالة طارئة خلال ثانية (الجهاز يرسل بلا جلسة)
t = time.perf_counter(); requests.get(BASE + '/up', timeout=120); net = round(time.perf_counter() - t, 3)
st, j, dt = webhook(did, secret, {'event_type': 'alarm', 'zone_id': '3', 'severity': 'critical', 'location': 'بوابة ٥ — محاكاة'}, ts=int(time.time()))
srv = j.get('processing_ms') if isinstance(j, dict) else None
# المعيار: زمن المعالجة في الخادم (من وصول الطلب حتى الرد بالحالة المنشأة) < ثانية؛ الرحلة الكاملة تشمل الشبكة والطبقة الوسيطة
log('GATE signed alarm → incident', (st, j.get('action'), j.get('incident_code'), f'server {srv}ms', f'round-trip {dt}s (network baseline /up {net}s)',
    'PASS' if (st == 200 and j.get('action') == 'incident_created' and srv is not None and srv < 1000) else 'FAIL'))
iid = j.get('incident_id')
live = sa.get(BASE + f'/app/emergency/incidents/{iid}/live', timeout=120).text
log('live page: device-originated + place HZ-06 + team notified', ('إنذار آلي' in live, 'HZ-06' in live or 'المكاتب' in live, 'الفريق الأولي' in live))
st, j = api(sa, 'GET', f'/api/iot/fire-panel/buildings/{bid}/status'); log('panel status from events', (st, j.get('online'), j.get('state')))
st, zones = api(sa, 'GET', f'/api/iot/fire-panel/buildings/{bid}/zones'); z3 = next((z for z in zones if z.get('zone_id') == 3), {})
log('zone 3 in alarm mapped to HZ-06', (len(zones), z3.get('state'), z3.get('place')))

# ٣) الحماية: توقيع خاطئ / بلا توقيع / طابع قديم — كلها ٤٠١ بلا حالة جديدة
st1, _, _ = webhook(did, 'wrong-secret', {'event_type': 'alarm', 'zone_id': '7'})
st2 = requests.post(BASE + f'/api/iot/webhooks/{did}', json={'event_type': 'alarm'}, headers=H, timeout=120).status_code
st3, _, _ = webhook(did, secret, {'event_type': 'alarm', 'zone_id': '7'}, ts=int(time.time()) - 3600)
log('bad / missing / stale signature → 401', (st1, st2, st3))

# ٤) إنذار ثانٍ أثناء الحالة المفتوحة يُسجَّل فيها لا حالة ثانية؛ restore يُنبّه
st, j, _ = webhook(did, secret, {'event_type': 'alarm', 'zone_id': '7'}); log('second alarm → logged to same incident', (st, j.get('action'), j.get('incident_id') == iid))
st, j, _ = webhook(did, secret, {'event_type': 'restore', 'zone_id': '3'}); log('restore → logged/notified', (st, j.get('action')))
ev = sa.get(BASE + f'/api/emergency/incidents/{iid}/events?after=0', headers=H, timeout=120).json()
log('incident timeline types', [e['type'] for e in ev['data']][:8])
events_page = sa.get(BASE + f'/app/emergency/iot/devices/{did}', timeout=120).text
log('device page lists events incl. rejected', ('rejected' in events_page or 'رُفض' in events_page, events_page.count('data-event=')))

# ٥) الإنهاء + القائمة المسموحة + حذف جهاز الاختبار
r, f = post(sa, f'/app/emergency/incidents/{iid}/end', {'final_report': 'بوابة ٥: إنذار محاكى من لوحة webhook، لا حريق.'}, f'/app/emergency/incidents/{iid}/live'); log('end incident', f)
st, j = api(sa, 'POST', '/api/iot/protocols/modbus/test', {'host': '10.9.9.9'}); log('protocol test to unlisted host → 422', st)
r, f = post(sa, f'/app/emergency/iot/devices/{did}/test', {}, f'/app/emergency/iot/devices/{did}'); log('device self-test (webhook: receive-only)', f)
tok, _ = csrf(sa, '/app/emergency/iot/devices')
r = sa.post(BASE + f'/app/emergency/iot/devices/{did}', data={'_token': tok, '_method': 'DELETE'}, allow_redirects=True, timeout=120)
log('test device deleted', (r.status_code, f'/devices/{did}"' not in r.text))
print('GATE5 DONE')
