# -*- coding: utf-8 -*-
"""بوابة المرحلة ٢ على الموقع المنشور: مدير إدارة يفعّل خطراً من الكتاب ويسمّي مسؤوله، ويُعتمد خطر مرجعي."""
import re, sys, json, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://ipa-safety.onrender.com'
OUT = []
def log(k, v): OUT.append((k, v)); print(k, '|', v, flush=True)

def session(user, pw):
    s = requests.Session(); s.headers['Accept-Language'] = 'ar'
    s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8'))
    h = s.get(BASE + '/login', timeout=60).text
    tok = re.search(r'name="_token" value="([^"]+)"', h).group(1)
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw}, allow_redirects=False, timeout=60)
    assert r.status_code == 302 and 'login' not in r.headers.get('Location', ''), (user, r.status_code, r.headers.get('Location'))
    s.tok = tok
    return s

def csrf(s, url):
    h = s.get(BASE + url, timeout=60).text
    m = re.search(r'name="_token" value="([^"]+)"', h) or re.search(r'csrf-token" content="([^"]+)"', h)
    return (m.group(1) if m else s.tok), h

# ١) مسؤول السلامة: يثبت وحدة مدير الإدارة (الموارد البشرية) إن لم تكن
sa = session('salama', '1234')
users_html = sa.get(BASE + '/app/users', timeout=60).text
uid = re.search(r'/app/users/(\d+)/edit[^>]*>[^<]*</a>', users_html)  # fallback below
m = None
for mm in re.finditer(r'<tr[^>]*>(.*?)</tr>', users_html, re.S):
    if '>mudir<' in mm.group(1):
        m = re.search(r'/app/users/(\d+)/edit', mm.group(1)); break
assert m, 'mudir not found in users list'
mudir_id = m.group(1)
tok, form = csrf(sa, f'/app/users/{mudir_id}/edit')
hr = re.search(r'<option value="(\d+)"\s*[^>]*>[^<]*موارد البشرية', form)
place = re.search(r'<option value="(\d+)"\s*[^>]*>[^<]*(HZ-06|المكاتب الإدارية)', form)
assert hr, 'hr unit option not found'
hr_id = hr.group(1)
r = sa.post(BASE + f'/app/users/{mudir_id}', data={'_token': tok, '_method': 'PUT', 'username': 'mudir', 'name': 'مدير إدارة',
            'role': 'department_manager', 'organization_unit_id': hr_id, 'place_id': place.group(1) if place else ''}, allow_redirects=False, timeout=60)
log('set mudir unit=hr', r.status_code)

# ٢) مدير الإدارة: يختار خطراً من السجل العام (المنسوخ من الكتاب) ويفعّله لإدارته ويسمّي المسؤول والمكان
md = session('mudir', '1234')
cats = md.get(BASE + '/app/risk/registry/tree/reference/categories', headers={'Accept': 'application/json'}, timeout=60).json()
log('reference categories seen by dept manager', len(cats))
cat = cats[0]['id']
subs = md.get(BASE + f'/app/risk/registry/tree/reference/sub-categories/{cat}', headers={'Accept': 'application/json'}, timeout=60).json()
risks = md.get(BASE + f'/app/risk/registry/tree/reference/risks-by-sub-category/{subs[0]["id"]}', headers={'Accept': 'application/json'}, timeout=60).json()
ref = risks[0]; ref_id = ref['id']
log('chosen reference risk', f"{ref_id} {ref.get('code')} {ref.get('title')}")
tok, form = csrf(md, f'/app/risk/{ref_id}/activate')
log('activate form has unit + place fields', ('organization_unit_id' in form, 'name="place_id"' in form))
pl = re.search(r'<option value="(\d+)"\s*[^>]*>[^<]*(HZ-06|المكاتب الإدارية)', form)
place_id = pl.group(1) if pl else ''
data = {'_token': tok, 'scope_type': 'org_unit', 'organization_unit_id': hr_id, 'place_id': place_id, 'severity': 4, 'likelihood': 3,
        'phases[proactive][responsible_org_unit_id]': hr_id, 'phases[proactive][responsible_user_text]': 'بوابة ٢ — مسؤول الخطر في الإدارة'}
r = md.post(BASE + f'/app/risk/{ref_id}/activate', data=data, allow_redirects=False, timeout=60)
log('activate POST', f"{r.status_code} -> {r.headers.get('Location')}")
active_page = md.get(BASE + '/app/risk/active?place=HZ-06', timeout=60).text
c06 = md.get(BASE + '/app/risk/registry/tree/active/categories?place=HZ-06', headers={'Accept': 'application/json'}, timeout=60).json()
c01 = md.get(BASE + '/app/risk/registry/tree/active/categories?place=HZ-01', headers={'Accept': 'application/json'}, timeout=60).json()
log('active screen carries HZ-06 filter badge + tree filtered (HZ-06 cats, HZ-01 cats)', (('كل الأماكن' in active_page), len(c06), len(c01)))
# خطر آخر في إدارة أخرى: مرفوض
def active_count(s):
    n = 0
    for c in s.get(BASE + '/app/risk/registry/tree/active/categories', headers={'Accept': 'application/json'}, timeout=60).json():
        for sc in s.get(BASE + f"/app/risk/registry/tree/active/sub-categories/{c['id']}", headers={'Accept': 'application/json'}, timeout=60).json():
            n += len(s.get(BASE + f"/app/risk/registry/tree/active/risks-by-sub-category/{sc['id']}", headers={'Accept': 'application/json'}, timeout=60).json())
    return n
before = active_count(sa)
r2 = md.post(BASE + f'/app/risk/{ref_id}/activate', data=dict(data, organization_unit_id=str(int(hr_id) - 1)), allow_redirects=False, timeout=60)
log('activate for another unit rejected (active count unchanged)', f"{r2.status_code}; before={before} after={active_count(sa)}")

# ٣) مسؤول السلامة يرى الخطر الفعلي بوحدته ومكانه ومسؤوله
acts = sa.get(BASE + '/app/risk/registry/tree/active/categories', headers={'Accept': 'application/json'}, timeout=60).json()
subs = sa.get(BASE + f'/app/risk/registry/tree/active/sub-categories/{acts[0]["id"]}', headers={'Accept': 'application/json'}, timeout=60).json()
ar = sa.get(BASE + f'/app/risk/registry/tree/active/risks-by-sub-category/{subs[0]["id"]}', headers={'Accept': 'application/json'}, timeout=60).json()
active_id = ar[-1]['id']
det = sa.get(BASE + f'/app/risk/registry/tree/active/risk/{active_id}', headers={'Accept': 'application/json'}, timeout=60).json()
log('active risk detail', json.dumps({k: det.get(k) for k in ('code', 'organization_unit', 'place', 'status', 'risk_score')}, ensure_ascii=False))
log('owner named', any('بوابة ٢' in json.dumps(p, ensure_ascii=False) for p in det.get('phases', [])))

# ٤) الاعتماد: مسؤول السلامة ينشئ خطراً مرجعياً ويقدّمه، الإدارة العليا تعتمده
tok, form = csrf(sa, '/app/risk/reference/create')
cat_id = re.search(r'name="category_id".*?<option value="(\d+)"', form, re.S).group(1)
r = sa.post(BASE + '/app/risk/reference/create', data={'_token': tok, 'category_id': cat_id, 'severity': 2, 'likelihood': 2, 'scope_type': 'general'}, allow_redirects=False, timeout=60)
log('reference create', f"{r.status_code} -> {r.headers.get('Location')}")
refs = sa.get(BASE + '/app/risk/reference', timeout=60).text
new_ids = [int(x) for x in re.findall(r'/app/risk/(\d+)/submit', refs)]
assert new_ids, 'no draft with submit button found'
draft = max(new_ids)
r = sa.post(BASE + f'/app/risk/{draft}/submit', data={'_token': tok}, allow_redirects=False, timeout=60)
log('submit draft', f"{draft} {r.status_code}")
ex = session('idara', '1234')
tok2, q = csrf(ex, '/app/risk/approval/queue')
log('draft in exec approval queue', f'/app/risk/{draft}/approve' in q)
r = ex.post(BASE + f'/app/risk/{draft}/approve', data={'_token': tok2, 'note': 'معتمد — بوابة ٢'}, allow_redirects=False, timeout=60)
log('approve', r.status_code)
d = ex.get(BASE + f'/app/risk/{draft}/detail', timeout=60).text
log('status approved on detail', 'معتمد' in d)
# الفني لا يرى المخاطر
fani = session('fani', '1234')
log('field worker /app/risk/reference', fani.get(BASE + '/app/risk/reference', allow_redirects=False, timeout=60).status_code)
print('\nGATE2 DONE', BASE)
