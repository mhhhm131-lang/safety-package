# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١١-٤ — الأبواب الثلاثة (قرار ٣٤): ما ينتظرك · بحث · الإعدادات، والقائمة خلف «المزيد».

    python tests/gates/gate11_4.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة]

يتحقق على العنوان أن:
  ١) الشريط فيه الأبواب الثلاثة، ولا عمود قائمة ثابتاً؛ القائمة كلها داخل «المزيد» (offcanvas).
  ٢) كل رابط في «المزيد» وفي «الإعدادات» يفتح لمسؤول السلامة (200/302، لا 404 ولا 500) — لا شاشة حُذفت.
  ٣) البحث يجد بلاغاً بنصّه، ورمزه يفتحه مباشرة، والفني لا يرى مجموعات لا يملكها.
"""
import re, sys, requests
from urllib.parse import urljoin
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ''
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    return None if (r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login')) else s

sa = login(*ADMIN.split(':', 1)); assert sa, 'تعذّر الدخول بحساب المركز — مرّر --admin='
b = sa.get(BASE + '/build.txt', timeout=120); log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)'))

# ١) الأبواب الثلاثة
h = sa.get(BASE + '/app', timeout=120).text
doors = all(x in h for x in ['id="navInbox"', 'id="navSearch"', 'id="navMore"', 'class="offcanvas offcanvas-end side"'])
log('1 three doors', (doors, 'no_fixed_sidebar=' + str('col-md-2 py-3 side' not in h), 'settings_link=' + str('/app/settings' in h)))
assert doors and 'col-md-2 py-3 side' not in h

# ٢) كل رابط في «المزيد» والإعدادات يفتح
more = re.search(r'id="moreNav".*?</aside>', h, re.S).group(0)
links = set(re.findall(r'href="([^"#]+)"', more))
settings = sa.get(BASE + '/app/settings', timeout=120).text
links |= set(re.findall(r'<a class="list-group-item[^"]*" href="([^"]+)"', settings))
links = {l for l in links if l.startswith('/app') or l.startswith(BASE + '/app')}
bad = []
for l in sorted(links):
    r = sa.get(urljoin(BASE, l), timeout=120, allow_redirects=False)
    if r.status_code not in (200, 302): bad.append((l, r.status_code))
log('2 every link opens', (f'links={len(links)}', f'bad={bad}'))
assert not bad and len(links) >= 30

# ٣) البحث
g = sess(); form = g.get(BASE + '/incident/normal?place=HZ-06', timeout=120).text
p06 = (re.search(r'<option value="(\d+)"[^>]*selected[^>]*>\s*HZ-06', form) or re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form)).group(1)
r = g.post(BASE + '/incident/normal', data={'_token': token(form), 'place_id': p06, 'description': 'بوابة ١١-٤ — كلمة فريدة زئبقية'}, allow_redirects=True, timeout=120)
tcode = re.search(r'code=([A-Z0-9]+)', r.url).group(1)
icode = re.search(r'ش-\d{4}', g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text).group(0)
s1 = sa.get(BASE + '/app/search', params={'q': 'زئبقية'}, timeout=120).text
found = 'data-group="بلاغات الشاغلين"' in s1 and icode in s1
r2 = sa.get(BASE + '/app/search', params={'q': icode}, timeout=120, allow_redirects=False)
shortcut = r2.status_code == 302 and '/app/incidents/' in r2.headers.get('Location', '')
r3 = sa.get(BASE + '/app/search', params={'q': 'HZ-06'}, timeout=120, allow_redirects=False)
log('3 search', (f'text_found={found}', f'code_redirect={shortcut}', 'place_redirect=' + str('#place=HZ-06' in r3.headers.get('Location', ''))))
assert found and shortcut and '#place=HZ-06' in r3.headers.get('Location', '')
te = login(*TECH.split(':', 1))
if te:
    st = te.get(BASE + '/app/search', params={'q': 'a'}, timeout=120).text
    log('3 tech scoped', 'no_risks_group=' + str('data-group="المخاطر"' not in st) + ' no_users_group=' + str('data-group="المستخدمون"' not in st))
    assert 'data-group="المستخدمون"' not in st
print('GATE 11-4 PASSED')
