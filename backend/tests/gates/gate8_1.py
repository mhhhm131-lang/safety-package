# -*- coding: utf-8 -*-
"""بوابة المرحلة ٨-١ (الأمن والتنظيف) على HTTP.

المسار: شاشة الإغلاق تعرض الجرد وما سيبقى ← التعطيل مرفوض بلا حساب حقيقي ← الحذف مرفوض
بلا كلمة التأكيد ← الحذف ينفَّذ ← السجلات التشغيلية اختفت والمرجعي كما هو.

**لا تُشغَّل خطوة التعطيل على المنشور إلا بعد إنشاء الحساب الحقيقي والتحقق من دخوله.**
مرّر `--disable-demo --as=اسم:كلمة` لتنفيذها بحساب حقيقي؛ بدونها تُفحص الرفضتان وحدهما.

التشغيل: PYTHONIOENCODING=utf-8 python gate8_1.py http://127.0.0.1:8089
        [--admin=اسم:كلمة] [--disable-demo --as=اسم:كلمة]

`--admin` حساب مسؤول السلامة الذي تعمل به البوابة (الافتراضي التجريبي `salama:1234`).
على المنشور بعد تغيير كلمة المرور التجريبية يلزم تمريره.
"""
import re, sys, requests
from html import unescape

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
DO_DISABLE = '--disable-demo' in sys.argv
# اعتماد الحساب الحقيقي لخطوة التعطيل: --as اسم:كلمة
REAL = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--as=')), None)
# حساب مسؤول السلامة الذي تعمل به البوابة. الافتراضي التجريبي، ويُمرَّر غيره على المنشور
# بعد تغيير كلمة المرور: --admin=اسم:كلمة
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
def log(k, v): print(k, '|', v, flush=True)


def sess():
    s = requests.Session()
    s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8'))
    return s


def csrf(s, url):
    h = s.get(BASE + url, timeout=120).text
    m = re.search(r'csrf-token" content="([^"]+)"', h) or re.search(r'name="_token" value="([^"]+)"', h)
    return (m.group(1) if m else ''), h


def login(user, pw='1234'):
    s = sess(); tok, _ = csrf(s, '/login')
    r = s.post(BASE + '/login', data={'_token': tok, 'username': user, 'password': pw},
               allow_redirects=False, timeout=120)
    return (s, r.status_code)


def post(s, url, data, page):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    msg = (flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:140]) if flash \
        else f'no-flash http={r.status_code}'
    return r, msg


def cell(html, attr, label):
    """قيمة صف في جدولَي الجرد والمرجعي."""
    m = re.search(attr + '="' + re.escape(label) + r'">(.*?)</tr>', html, re.S)
    if not m:
        return None
    n = re.findall(r'>(\d+)<', m.group(1))
    return int(n[-1]) if n else None


admin_user, admin_pw = ADMIN.split(':', 1)
sa, code = login(admin_user, admin_pw)
assert code == 302, (
    f'تعذّر الدخول بحساب «{admin_user}». على المنشور غُيّرت كلمة المرور التجريبية؛ '
    'مرّر --admin=اسم:كلمة')
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)', f'login={code}'))
fa, _ = login('fani')

# ٠) الصلاحية
log('0 permission', ('salama', sa.get(BASE + '/app/closeout', allow_redirects=False, timeout=120).status_code,
                     'fani', fa.get(BASE + '/app/closeout', allow_redirects=False, timeout=120).status_code))

page = sa.get(BASE + '/app/closeout', timeout=120).text

# ١) الجرد وما سيبقى
inc_before   = cell(page, 'data-purge', 'بلاغات الشاغل')
book_before  = cell(page, 'data-keep', 'كتاب المخاطر')
ref_before   = cell(page, 'data-keep', 'السجل العام للمعهد')
places_before = cell(page, 'data-keep', 'الأماكن')
log('1 inventory shown', (f'بلاغات={inc_before}', f'الكتاب={book_before}',
                          f'السجل العام={ref_before}', f'الأماكن={places_before}'))

# ٢) لا جدول بلا تصنيف
log('2 every table classified', 'data-unclassified' not in page)

# ٣) الحسابات التجريبية تُعرض بحالتها
demos = re.findall(r'data-demo="([^"]+)"', page)
log('3 demo accounts listed', (len(demos), demos[:4]))

# ٤) التعطيل مرفوض بلا حساب حقيقي نشط
has_real = 'data-real-admin="1"' in page
if not has_real:
    r, f = post(sa, '/app/closeout/demo-off', {}, '/app/closeout')
    still = sa.get(BASE + '/app/closeout', timeout=120).text
    log('4 demo-off refused (guard)', (f[:90], 'salama ما زال نشطاً',
                                       'data-state="salama">\n                  نشط' in still or 'نشط' in still))
else:
    log('4 real admin exists', 'الحارس يسمح بالتعطيل')

# ٥) الحذف مرفوض بلا كلمة التأكيد
r, f = post(sa, '/app/closeout/purge', {'confirm': 'نعم'}, '/app/closeout')
after_bad = sa.get(BASE + '/app/closeout', timeout=120).text
log('5 purge needs confirm word', (f[:80], f'بلاغات={cell(after_bad, "data-purge", "بلاغات الشاغل")}',
                                   cell(after_bad, 'data-purge', 'بلاغات الشاغل') == inc_before))

# ٦) الحذف بكلمة التأكيد
r, f = post(sa, '/app/closeout/purge', {'confirm': 'احذف'}, '/app/closeout')
page2 = sa.get(BASE + '/app/closeout', timeout=120).text
log('6 purge executed', (f[:100],))

# ٧) التشغيلي صفر
zeros = {label: cell(page2, 'data-purge', label) for label in
         ['بلاغات الشاغل', 'الحالات الطارئة', 'التصاريح', 'النماذج الرقمية', 'العمال',
          'الأطراف الخارجية', 'المشاريع', 'المعدات', 'الأجهزة الموصولة',
          'المخاطر الفعّالة (الإدارات والأماكن)']}
log('7 operational cleared', (zeros, all(v == 0 for v in zeros.values())))

# ٨) المرجعي كما هو
keeps = {'كتاب المخاطر': book_before, 'السجل العام للمعهد': ref_before, 'الأماكن': places_before}
now = {k: cell(page2, 'data-keep', k) for k in keeps}
log('8 reference untouched', (now, all(now[k] == keeps[k] for k in keeps)))

# ٩) الشاشات ما زالت تعمل بعد الحذف
pages = ['/app', '/app/incidents', '/app/emergency', '/app/permits', '/app/forms',
         '/app/risk', '/app/risk/master', '/app/projects', '/app/workers', '/app/reports',
         '/app/reports/incidents', '/app/reports/risks', '/app/places', '/app/users']
codes = [(p, sa.get(BASE + p, timeout=120).status_code) for p in pages]
log('9 screens after purge', (len(codes), 'أخطاء خادم=' + str(len([c for _, c in codes if c >= 500])),
                              [pc for pc in codes if pc[1] != 200]))

# ١٠) الحارس الثاني: من يعطّل وهو داخل بحساب تجريبي يُمنع (وإلا أقفل الباب على نفسه)
if admin_user in re.findall(r'data-demo="([^"]+)"', page):
    r, f = post(sa, '/app/closeout/demo-off', {}, '/app/closeout')
    log('10 self-lockout guard', (f[:110], 'داخل بحساب تجريبي' in f))
else:
    log('10 self-lockout guard', f'يُتخطّى: «{admin_user}» ليس حساباً تجريبياً')

# ١١) التعطيل بحساب حقيقي، ثم دخول الحساب التجريبي يُرفض
if DO_DISABLE and REAL:
    user, pw = REAL.split(':', 1)
    ra, rc = login(user, pw)
    log('11 real admin login', (user, rc, rc == 302))
    r, f = post(ra, '/app/closeout/demo-off', {}, '/app/closeout')
    log('12 demo accounts disabled', f[:110])
    s2, code2 = login('salama')
    loc = s2.get(BASE + '/app', allow_redirects=False, timeout=120)
    log('13 demo login rejected', (f'login={code2}', f'/app={loc.status_code}', loc.status_code != 200))
else:
    log('11 demo-off skipped', 'مرّر --disable-demo --as=اسم:كلمة بعد إنشاء الحساب الحقيقي')

log('14 done', 'البوابة اكتملت')
