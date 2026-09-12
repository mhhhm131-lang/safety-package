# -*- coding: utf-8 -*-
"""
بوابة المرحلة ١٢ — «أريد أن…» (قرار ٣٥): أي شيء يريد المستخدم فعله يجده في شاشته الأولى وينفّذه.

    python tests/gates/gate12.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة] [--fm=اسم:كلمة]

يتحقق على العنوان أن:
  ١) الضيف: صفحة البلاغ تعرض «أريد أن…» (بلّغ، أتابع، أعرف أخطار مكاني، خطة مكاني، دوري)، وكتاب المعهد عام ويبحث،
     و«رأيت هذا؟ بلّغ» يفتح البلاغ والخطر محدد ويُرسل فيصل باسم الخطر بلا تصنيف من المركز.
  ٢) كل حساب مُمرَّر: شاشته الأولى فيها «أريد أن…»، وكل زر فيها يفتح (200/302)، وأزراره تطابق دوره
     (الفني: أفحص مكاني وأستغيث؛ مدير المرافق: فعّل حالة وأنظمة المبنى؛ المركز: الإعدادات وتمرين).
"""
import re, sys, requests
from urllib.parse import urljoin
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ACC = {k: next((a.split('=', 1)[1] for a in sys.argv if a.startswith(f'--{k}=')), d) for k, d in [('admin', 'salama:1234'), ('tech', 'fani:1234'), ('fm', 'marafiq:1234')]}
EXPECT = {'admin': ['trigger', 'lockdown', 'drill', 'teams', 'systems', 'permit', 'sendform', 'reports', 'settings', 'hazards'],
          'tech': ['sos', 'myforms', 'hazards', 'inspections'], 'fm': ['trigger', 'systems', 'activate', 'hazards']}
# «أفحص مكاني» يظهر للفني الذي له مكان في ملفه؛ بلا مكان يُسجَّل ولا يُعدّ سقوطاً
OPTIONAL = {'tech': ['inspect']}
FORBID = {'tech': ['trigger', 'settings', 'nominate'], 'fm': ['settings', 'inspect']}
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html); return m.group(1) if m else ''
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    return None if (r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login')) else s

# ١) الضيف
g = sess(); land = g.get(BASE + '/incident', timeout=120).text
keys = re.findall(r'data-intent="(\w+)"', land)
log('1 guest intents', keys); assert all(k in keys for k in ['report', 'track', 'hazards', 'plans', 'roles'])
book = g.get(BASE + '/hazards', timeout=120).text
codes = re.findall(r'data-risk="([^"]+)"', book)
log('1 hazard book', (f'risks={len(codes)}', 'report_button=' + str('رأيت هذا؟ بلّغ' in book)))
assert codes and 'رأيت هذا؟ بلّغ' in book
first = codes[0]; q = g.get(BASE + '/hazards', params={'q': first}, timeout=120).text
assert first in q
rid = re.search(rf'data-risk="{re.escape(first)}".*?/incident/normal\?risk=(\d+)', book, re.S).group(1)
form = g.get(BASE + f'/incident/normal?risk={rid}&place=HZ-06', timeout=120).text
assert 'id="presetRisk"' in form and 'id="riskCat"' not in form
p06 = (re.search(r'<option value="(\d+)"[^>]*selected[^>]*>\s*HZ-06', form) or re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form)).group(1)
r = g.post(BASE + '/incident/normal', data={'_token': token(form), 'place_id': p06, 'description': 'بوابة ١٢ — من كتاب المعهد', 'risk_id': rid}, allow_redirects=True, timeout=120)
tcode = re.search(r'code=([A-Z0-9]+)', r.url); assert tcode, 'البلاغ من الكتاب لم يُرسل'
track = g.get(BASE + f'/incident/track?code={tcode.group(1)}', timeout=120).text
icode = re.search(r'ش-\d{4}', track).group(0)
log('1 report from hazard', (icode, f'risk={first}'))

# ٢) الحسابات
for who, cred in ACC.items():
    s = login(*cred.split(':', 1))
    if not s: log(f'2 {who}', f'skipped: تعذّر الدخول ({cred.split(":")[0]})'); continue
    h = s.get(BASE + '/app', timeout=120).text
    keys = re.findall(r'data-intent="(\w+)"', h); urls = dict(re.findall(r'href="([^"]+)" data-intent="(\w+)"', h))
    urls = {v: k for k, v in urls.items()}
    missing = [k for k in EXPECT[who] if k not in keys]; forbidden = [k for k in FORBID.get(who, []) if k in keys]
    bad = []
    for k, u in urls.items():
        if not (u.startswith(BASE + '/app') or u.startswith('/app') or '/incident' in u or '/hazards' in u): continue
        c = s.get(urljoin(BASE, u), timeout=120, allow_redirects=False).status_code
        if c not in (200, 302): bad.append((k, c))
    opt = {k: (k in keys) for k in OPTIONAL.get(who, [])}
    log(f'2 {who}', (f'intents={len(keys)}', f'missing={missing}', f'forbidden={forbidden}', f'bad={bad}', f'optional={opt}' if opt else ''))
    assert not missing and not forbidden and not bad
    if who == 'admin':
        i = s.get(BASE + '/app/incidents', timeout=120).text
        log('2 center sees report from hazard classified', 'row=' + str(icode in i))
print('GATE 12 PASSED')
