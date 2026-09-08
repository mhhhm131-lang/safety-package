# -*- coding: utf-8 -*-
"""بوابة المرحلة ٣: سيناريو البلاغ السري كاملاً على HTTP + بلاغ عادي بلا فني يحيله المركز ويظهر في شريط النموذج."""
import re, sys, json, base64, io, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://ipa-safety.onrender.com'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
H = {'Accept': 'application/json'}
def log(k, v): print(k, '|', v, flush=True)

def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def csrf(s, url):
    h = s.get(BASE + url, timeout=90).text
    m = re.search(r'name="_token" value="([^"]+)"', h) or re.search(r'csrf-token" content="([^"]+)"', h)
    return (m.group(1) if m else ''), h
def login(user, pw):
    s = sess(); tok, _ = csrf(s, '/login')
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw}, allow_redirects=False, timeout=90)
    assert r.status_code == 302 and 'login' not in r.headers.get('Location', ''), (user, r.status_code)
    s.tok = tok; return s
def post(s, url, data, files=None):
    tok, _ = csrf(s, url.rsplit('/', 1)[0] if '/app/incidents/' in url else '/app/incidents')
    d = dict(data); d['_token'] = tok
    if not tok: raise SystemExit('no csrf token for ' + url)
    r = s.post(BASE + url, data=d, files=files, allow_redirects=True, timeout=90)
    flash = re.search(r'alert alert-(success|danger)[^>]*>(.*?)</div>', r.text, re.S)
    return r, (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:120]) if flash else f'no-flash http={r.status_code}'

# ٠) مسؤول السلامة يثبّت مكان الفني fani = المكاتب الإدارية (HZ-06) — كما يفعل عند إعداد الحسابات
sa = login('salama', '1234')
users = sa.get(BASE + '/app/users', timeout=90).text
fid = None
for mm in re.finditer(r'<tr[^>]*>(.*?)</tr>', users, re.S):
    if '>fani<' in mm.group(1): fid = re.search(r'/app/users/(\d+)/edit', mm.group(1)).group(1)
tok, form = csrf(sa, f'/app/users/{fid}/edit')
place06 = re.search(r'<option value="(\d+)"\s*[^>]*>[^<]*(HZ-06|المكاتب الإدارية)', form).group(1)
r = sa.post(BASE + f'/app/users/{fid}', data={'_token': tok, '_method': 'PUT', 'username': 'fani', 'name': 'الفني المنفّذ', 'role': 'field_worker', 'place_id': place06}, allow_redirects=False, timeout=90)
log('fani place=HZ-06', r.status_code)

# ١) شاغل بلا حساب: صفحة البلاغ العامة → سري
g = sess()
landing = g.get(BASE + '/incident?place=HZ-06', timeout=90).text
log('public landing (3 types + place preset)', ('عاجل' in landing, 'سري' in landing, '?place=HZ-06' in landing))
tok, form = csrf(g, '/incident/secret')
cats = re.findall(r'<option value="(\d+)">([^<]+)</option>', form.split('id="riskCat"')[1].split('</select>')[0])
cat = cats[0][0]
subs = g.get(BASE + f'/incident/api/sub-categories?category_id={cat}', headers=H, timeout=90).json()
risks = []
for sc in subs:
    risks = g.get(BASE + f"/incident/api/risks?sub_category_id={sc['id']}", headers=H, timeout=90).json()
    if risks: break
log('risk picker (category → sub → reference risk)', f"{cats[0][1]} → {sc['name']} → {risks[0]['code']} {risks[0]['title'][:40]}")
photo = 'data:image/png;base64,' + base64.b64encode(PNG).decode()
r = g.post(BASE + '/incident/secret', data={'_token': tok, 'place_id': place06, 'location_text': 'الدور الثاني قرب المصعد', 'description': 'بوابة ٣ — سلك كهربائي مكشوف يلمسه المارة', 'risk_id': risks[0]['id'], 'photo': photo}, allow_redirects=False, timeout=90)
loc = r.headers.get('Location', '')
code = re.search(r'code=([A-Z0-9]+)', loc).group(1)
log('secret report sent', f"{r.status_code} code={code}")
succ = g.get(loc, timeout=90).text
log('success page shows code + auto-forward to place tech', (code in succ, 'حُوّل تلقائياً إلى فني المكان' in succ))
track = g.get(BASE + f'/incident/track?code={code}', timeout=90).text
inc_code = re.search(r'ش-\d{4}', track).group(0)
log('track by code (timeline, no identity)', (inc_code, 'استلمه الفني' in track, 'الفني المنفّذ' not in track))

# ٢) المركز يراه؛ الفني المعيَّن يفتحه ويبدأ ويرفع دليلاً ويعالج
lst = sa.get(BASE + '/app/incidents', timeout=90).text
iid = re.search(rf'/app/incidents/(\d+)"[^>]*>[^<]*</a>', lst.split(inc_code)[1]).group(1)
log('center list shows it', (inc_code in lst, 'مخفي (بلاغ سري)' in lst))
fa = login('fani', '1234')
d = fa.get(BASE + f'/app/incidents/{iid}', timeout=90).text
log('tech sees begin button', 'بدء المعالجة' in d)
r, f = post(fa, f'/app/incidents/{iid}/begin-work', {}); log('tech begin-work', f)
r, f = post(fa, f'/app/incidents/{iid}/resolve', {'resolution_summary': 'فُصل التيار وعُزل السلك وأُعيد الغطاء وفُحص الخط كاملاً'}); log('resolve without evidence (should fail)', f)
r, f = post(fa, f'/app/incidents/{iid}/upload', {}, files={'file': ('proof.png', PNG, 'image/png')}); log('tech uploads evidence', f)
r, f = post(fa, f'/app/incidents/{iid}/resolve', {'resolution_summary': 'فُصل التيار وعُزل السلك وأُعيد الغطاء وفُحص الخط كاملاً'}); log('tech resolve', f)
# ٣) الإغلاق: يحتاج تحقق شخص غير المنفّذ (سري)
r, f = post(sa, f'/app/incidents/{iid}/close', {}); log('close before verify (should fail)', f)
r, f = post(fa, f'/app/incidents/{iid}/verify', {}); log('tech verifies own work (should fail)', f)
r, f = post(sa, f'/app/incidents/{iid}/verify', {}); log('center verifies', f)
r, f = post(sa, f'/app/incidents/{iid}/close', {}); log('center closes', f)
track = g.get(BASE + f'/incident/track?code={code}', timeout=90).text
log('track shows closed + what was done', ('مغلق' in track, 'فُصل التيار' in track))

# ٤) بلاغ عادي في مكان بلا فني (القبو HZ-01) → ينتظر المركز → إحالة → يظهر في شريط النموذج → ربط من النموذج
tok, form = csrf(g, '/incident/normal')
place01 = re.search(r'<option value="(\d+)"\s*[^>]*>[^<]*HZ-01', form).group(1)
r = g.post(BASE + '/incident/normal', data={'_token': tok, 'place_id': place01, 'description': 'بوابة ٣ — طفاية حريق مفقودة عند مدخل المواقف', 'risk_id': risks[0]['id'], 'reporter_name': 'سعد'}, allow_redirects=False, timeout=90)
code2 = re.search(r'code=([A-Z0-9]+)', r.headers.get('Location', '')).group(1)
track2 = g.get(BASE + f'/incident/track?code={code2}', timeout=90).text
inc2 = re.search(r'ش-\d{4}', track2).group(0)
log('normal report at HZ-01 waits for center', (inc2, 'وصل المركز' in track2, 'استلمه الفني' not in track2))
lst = sa.get(BASE + '/app/incidents?status=received', timeout=90).text
iid2 = re.search(rf'/app/incidents/(\d+)"[^>]*>[^<]*</a>', lst.split(inc2)[1]).group(1)
r, f = post(sa, f'/app/incidents/{iid2}/refer', {'field_worker_id': fid, 'note': 'الطفاية عند المدخل الغربي'}); log('center refers to fani', f)
occ = fa.get(BASE + '/api/store?all=1&keys=ipa-occ', headers=H, timeout=90).json()
doc = json.loads(occ['docs']['ipa-occ']['data'])
mine = [x for x in doc['reports'] if x['id'] == inc2]
log('ipa-occ document for inspection forms carries it', (len(mine), mine[0]['hz'] if mine else None, mine[0]['status'] if mine else None))
mine[0]['status'] = 'linked'; mine[0]['link'] = {'key': 'ipa-hz01-form-v10', 'row': 'أ — ٠١'}
r = fa.put(BASE + '/api/store/ipa-occ', json={'data': json.dumps(doc, ensure_ascii=False), 'version': occ['docs']['ipa-occ']['version']}, headers={**H, 'X-XSRF-TOKEN': requests.utils.unquote(fa.cookies.get('XSRF-TOKEN', ''))}, timeout=90)
log('form links it (PUT ipa-occ)', r.status_code)
d2 = sa.get(BASE + f'/app/incidents/{iid2}', timeout=90).text
log('detail shows inspection link + status', ('فُتح عليه بلاغ فحص' in d2, 'أ — ٠١' in d2, 'جارٍ' in d2))
# ٥) الصلاحيات
log('guest /app/incidents', g.get(BASE + '/app/incidents', allow_redirects=False, timeout=90).status_code)
log('tech settings (403)', fa.get(BASE + '/app/incidents/settings', allow_redirects=False, timeout=90).status_code)
log('QR page', sa.get(BASE + '/app/places/HZ-06/qr', timeout=90).status_code)
log('report.html redirects', 'url=/incident' in g.get(BASE + '/report.html', timeout=90).text)
print('\nGATE3 DONE', BASE, 'incidents:', inc_code, inc2)
