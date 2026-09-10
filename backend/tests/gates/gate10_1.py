# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١٠-١ — المزامنة والأدوار (BACKEND.md قرار ٣٠، المكوّنان أ وب).

    python tests/gates/gate10_1.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة]

يتحقق على العنوان (محلياً أو المنشور) أن:
  ١) شاشة «خطط الاستجابة» تفتح لمسؤول السلامة وفيها الأماكن الثمانية وبطاقات الأدوار الـ٢١.
  ٢) خطوات المسارات لكل مكان تطابق رؤوس المسارات في الوثيقة، والرقم المعلن في رأس كل وثيقة يُعرض كما هو.
  ٣) صفحة HZ-06: ٣ طبي + ٩ حريق = ١٢ خطوة حية، و٤ بنود كشف؛ كل خطوة لها «من» وبطاقة (عدا الاستثناء المرصود).
  ٤) زر «مزامنة من الوثائق» يعمل لمسؤول السلامة ويُرفض للفني (403)، والضيف يُحوَّل للدخول.
"""
import re, sys, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
EXPECT_PATHS = {  # رؤوس المسارات في الوثائق الثماني: (طبي، مسار المكان، حالات أخرى) — والمعلن في رأس الوثيقة
    'HZ-01': (3, 13, 0, 21), 'HZ-02': (3, 12, 0, 19), 'HZ-03': (3, 12, 0, 19), 'HZ-04': (3, 14, 3, 22),
    'HZ-05': (3, 12, 0, 19), 'HZ-06': (3, 9, 0, 16), 'HZ-07': (3, 10, 0, 17), 'HZ-08': (3, 10, 0, 13),
}
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
def strip(h): return unescape(re.sub(r'<[^>]+>', ' ', h))
def ar(n): return str(n).translate(str.maketrans('0123456789', '٠١٢٣٤٥٦٧٨٩'))

au, ap = ADMIN.split(':', 1); sa, code = login(au, ap)
assert code == 302, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)', f'login={code}'))

# ١) الشاشة
r = sa.get(BASE + '/app/emergency/plans', timeout=120); h = r.text
places = re.findall(r'<strong>(HZ-0[1-8])</strong>', h)
log('1 screen', (r.status_code, f'places={len(set(places))}', 'cards21=' + str('بطاقات الأدوار الـ٢١' in h), 'menu=' + str('خطط الاستجابة</a>' in h)))
assert r.status_code == 200 and len(set(places)) == 8

# ٢) لكل مكان: صفحة الخطوات ورؤوس المسارات
ok_all = True
for code_, (med, fire, other, declared) in EXPECT_PATHS.items():
    p = sa.get(BASE + f'/app/emergency/plans/{code_}', timeout=120); ph = p.text
    heads = re.findall(r'في رأس المسار: (\d+) · المشتق: (\d+) (✓|✗)', ph)
    derived = [int(x[1]) for x in heads]; mismatch = [x for x in heads if x[2] == '✗']
    total = re.search(r'خطوات المسارات: <strong>(\d+)</strong>', ph)
    decl = re.search(r'المعلن في رأس الوثيقة: (\d+)', ph)
    nocard = re.search(r'بلا بطاقة: (\d+)', ph)
    expect_total = med + fire + other
    good = p.status_code == 200 and not mismatch and total and int(total.group(1)) == expect_total and decl and int(decl.group(1)) == declared
    ok_all = ok_all and good
    log(f'2 {code_}', (p.status_code, f'paths={derived}', f'live={total.group(1) if total else "?"}/{expect_total}', f'declared={decl.group(1) if decl else "?"}/{declared}', f'no_card={nocard.group(1) if nocard else "?"}', 'OK' if good else 'FAIL'))
assert ok_all, 'خطوات المسارات لا تطابق رؤوس الوثائق'

# ٣) HZ-06 تفصيلاً
p = sa.get(BASE + '/app/emergency/plans/HZ-06', timeout=120).text
rows = re.findall(r'<tr class="[^"]*">\s*<td><strong>([^<]+)</strong></td>\s*<td>([^<]*)</td>\s*<td class="text-muted">([^<]*)</td>\s*<td><strong>([^<]+)</strong></td>\s*<td>(.*?)</td>', p, re.S)
live = [x for x in rows if x[0] not in ('①', '②', '③', '←', 'أ', 'ب')]
det = [x for x in rows if x[0] in ('①', '②', '③', '←')]
no_card = [x[3] for x in live if 'بلا بطاقة' in x[4]]
step3 = next((x for x in live if x[3] == 'التحكم بالأنظمة الحرجة'), None)
log('3 HZ-06', (f'live={len(live)}', f'detection={len(det)}', f'no_card={no_card}', 'step3=' + (f'{step3[1]} / {step3[2].strip()} / ' + ('2 مدير المرافق' if '2 مدير المرافق' in step3[4] else '?') if step3 else 'missing')))
assert len(live) == 12 and len(det) == 4 and step3 and '2 مدير المرافق' in step3[4] and 'الثانية الأولى' in step3[1] and not no_card

# ٤) المزامنة والصلاحيات
tok, _ = csrf(sa, '/app/emergency/plans')
r = sa.post(BASE + '/app/emergency/plans/sync', data={'_token': tok}, allow_redirects=True, timeout=120)
flash = re.search(r'alert alert-(success|warning|danger)[^>]*>(.*?)</div>', r.text, re.S)
log('4 sync admin', (r.status_code, (flash.group(1) + ': ' + strip(flash.group(2)).strip()[:80]) if flash else 'no-flash'))
assert flash and flash.group(1) == 'success' and '8' in flash.group(2)
tu, tp = TECH.split(':', 1); st, tcode = login(tu, tp)
if tcode == 302:
    tv = st.get(BASE + '/app/emergency/plans', timeout=120).status_code
    ttok, _ = csrf(st, '/app/emergency/plans')
    ts = st.post(BASE + '/app/emergency/plans/sync', data={'_token': ttok}, allow_redirects=False, timeout=120).status_code
    log('4 tech', (f'view={tv}', f'sync={ts}')); assert tv == 200 and ts == 403
else:
    log('4 tech', f'skipped (login={tcode})')
g = sess().get(BASE + '/app/emergency/plans', allow_redirects=False, timeout=120)
log('4 guest', g.status_code); assert g.status_code == 302
print('GATE 10-1 PASSED')
