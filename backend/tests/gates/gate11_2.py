# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١١-٢ — الشاشة الواحدة «ما ينتظرك الآن» (قرار ٣٤).

    python tests/gates/gate11_2.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة]

يتحقق على العنوان أن:
  ١) بعد الدخول الصفحة الأولى `/app` هي «ما ينتظرك» — ٠ قفزات (لا بطاقات روابط).
  ٢) بلاغ شاغل يظهر مهمةً لصاحبها وحده ثم يختفي بعد الفعل، عبر الدورة كاملة:
     المركز «صنّف/أحِله» ← الفني «افتحه» ← «عولج؟» ← المركز «تحققتُ ميدانياً» ← «أغلق» ← لا شيء.
  ٣) `/app/inbox/count` يعطي العدد نفسه الذي تعرضه الشاشة.
يترك البلاغ مغلقاً.
"""
import re, sys, base64, json, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ''
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    if r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login'): return s, None
    return s, r.headers.get('Location', '')
def post(s, url, data, html, files=None):
    d = dict(data); d['_token'] = token(html)
    r = s.post(BASE + url, data=d, files=files, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    return r, ((flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:120]) if flash else f'no-flash http={r.status_code}')
def inbox(s):
    h = s.get(BASE + '/app', timeout=120).text
    n = s.get(BASE + '/app/inbox/count', headers={'Accept': 'application/json'}, timeout=120).json().get('count')
    tasks = re.findall(r'data-task="([^"]+)"', h)
    return h, n, tasks
def task_of(h, code):
    m = re.search(r'<div class="card[^"]*" data-task="([^"]+)">(.*?)</div>\s*</div>\s*</div>', h, re.S)
    for card in re.finditer(r'data-task="(incident:\d+:\w+)">(.*?)<div class="d-flex gap-2 flex-wrap">(.*?)</div>\s*</div>\s*</div>', h, re.S):
        if code in card.group(2):
            btn = re.search(r'<(?:button|a)[^>]*class="btn btn-g"[^>]*>([^<]+)<', card.group(3))
            return card.group(1), (btn.group(1).strip() if btn else '?')
    return None, None

au, ap = ADMIN.split(':', 1); sa, loc = login(au, ap)
assert loc is not None, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)'))

# ١) الصفحة الأولى بعد الدخول = الصندوق (٠ قفزات)
h, n0, t0 = inbox(sa)
first = ('ما ينتظرك' in h or 'لا شيء ينتظرك' in h or 'ينتظرك' in h) and 'كل ما يحتاجك يظهر هنا' in h
log('1 first screen is inbox', (f'login_redirect={loc}', f'hint={first}', f'count={n0}', f'cards={len(t0)}', 'no_link_cards=' + str('مرحباً' not in h)))
assert first and loc.endswith('/app') and len(t0) == n0

# ٢) بلاغ بلا خطر في HZ-06
g = sess(); form = g.get(BASE + '/incident/normal?place=HZ-06', timeout=120).text
p06 = (re.search(r'<option value="(\d+)"[^>]*selected[^>]*>\s*HZ-06', form) or re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form)).group(1)
r = g.post(BASE + '/incident/normal', data={'_token': token(form), 'place_id': p06, 'description': 'بوابة ١١-٢ — مقبض باب مكسور'}, allow_redirects=True, timeout=120)
tcode = re.search(r'code=([A-Z0-9]+)', r.url).group(1)
track = g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text
icode = re.search(r'ش-\d{4}', track).group(0)
h, n1, t1 = inbox(sa); key, btn = task_of(h, icode)
log('2 center task', (icode, f'count {n0}→{n1}', f'task={key}', f'button={btn}'))
assert key and key.startswith('incident:') and n1 == n0 + 1
iid = key.split(':')[1]
if 'حُوّل إلى الفني' not in track:
    tu = TECH.split(':', 1)[0]
    users = sa.get(BASE + '/app/users', timeout=120).text
    pos = users.find(f'>{tu}<'); fid = re.search(r'/app/users/(\d+)/edit', users[pos if pos > 0 else 0:]).group(1)
    show = sa.get(BASE + f'/app/incidents/{iid}', timeout=120).text
    rr, fl = post(sa, f'/app/incidents/{iid}/refer', {'field_worker_id': fid, 'note': 'بوابة ١١-٢'}, show)
    log('2 center refers', fl[:50])

# الفني: «افتحه» ← يفتح ← «عولج؟» ← يعالج بصورة ← لا مهمة
tu, tp = TECH.split(':', 1); st, tloc = login(tu, tp)
assert tloc is not None, f'تعذّر الدخول بحساب الفني «{tu}» — مرّر --tech=اسم:كلمة'
h, nt, tt = inbox(st); key, btn = task_of(h, icode)
log('2 tech task before open', (f'count={nt}', f'task={key}', f'button={btn}'))
assert key and btn == 'افتحه'
d1 = st.get(BASE + f'/app/incidents/{iid}', timeout=120).text
h, nt2, _ = inbox(st); key, btn = task_of(h, icode)
log('2 tech task after open', (f'task={key}', f'button={btn}'))
assert btn == 'عولج'
r2, fl2 = post(st, f'/app/incidents/{iid}/resolve', {'resolution_summary': 'بوابة ١١-٢: بُدّل المقبض وأُعيد الباب إلى العمل'}, d1, files={'evidence': ('after.png', PNG, 'image/png')})
track = g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text
h, nt3, _ = inbox(st); key, _ = task_of(h, icode)
log('2 tech resolved', (fl2[:40], 'resolved=' + str('عولج' in track), f'count={nt3}', f'task_gone={key is None}'))
assert 'عولج' in track and key is None

# المركز: «تحققتُ ميدانياً» ← «أغلق» ← لا شيء
h, nc, _ = inbox(sa); key, btn = task_of(h, icode)
log('2 center verify task', (f'task={key}', f'button={btn}'))
assert btn == 'تحققتُ ميدانياً'
rv, flv = post(sa, f'/app/incidents/{iid}/verify', {}, h)
h, nc2, _ = inbox(sa); key, btn = task_of(h, icode)
log('2 center close task', (flv[:30], f'task={key}', f'button={btn}'))
assert btn == 'أغلق'
rc, flc = post(sa, f'/app/incidents/{iid}/close', {}, h)
track = g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text
h, nc3, t3 = inbox(sa); key, _ = task_of(h, icode)
log('2 closed', (flc[:30], 'closed=' + str('أُغلق' in track), f'count {nc}→{nc3}', f'task_gone={key is None}', f'cards={len(t3)}'))
assert 'أُغلق' in track and key is None and nc3 == len(t3)

print('GATE 11-2 PASSED')
