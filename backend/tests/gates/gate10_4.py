# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١٠-٤ — التقرير والمؤشرات والتمارين والجاهزية (قرار ٣٠، المكوّن ز).

    python tests/gates/gate10_4.py [BASE] [--admin=اسم:كلمة]

يتحقق على العنوان (محلياً أو المنشور) أن:
  ١) تمرين إخلاء في القاعات HZ-07 يبدأ فتظهر خطوات الخطة (٣ طبي + ١٠)؛ «تم» على خطوتين ثم الإنهاء.
  ٢) تقرير الحالة يعرض جدول «الالتزام بالخطة»: الخطوة، المستهدف، الفعلي، الفارق، من، ملاحظة — بصف لكل خطوة وملخصاً.
  ٣) شاشة التمارين تعرض شارة الالتزام بالخطة برابط التقرير.
  ٤) لوحة التقارير تعرض أزمنة الخطوات الثلاث الأولى لكل مكان (HZ-07 بأرقام، والبقية «لا بيانات» إن لم تكن لها حالات).
  ٥) مركز الطوارئ يعرض بند «خطة الاستجابة» في جاهزية كل مكان (مزامَنة من الوثيقة: N خطوة، أدوار بلا شاغل).
يترك المبنى فارغاً (التمرين يُنهى).
"""
import re, sys, time, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
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
    return s, r.status_code
def post(s, url, data, page):
    tok, _ = csrf(s, page); d = dict(data); d['_token'] = tok
    r = s.post(BASE + url, data=d, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    return r, ((flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:160]) if flash else f'no-flash http={r.status_code}')
def strip(h): return unescape(re.sub(r'<[^>]+>', ' ', h))

au, ap = ADMIN.split(':', 1); sa, code = login(au, ap)
assert code == 302, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)', f'login={code}'))
dash = sa.get(BASE + '/app/emergency', timeout=120).text
bid = re.search(r'/app/emergency/buildings/(\d+)/control', dash).group(1)
ctrl = sa.get(BASE + f'/app/emergency/buildings/{bid}/control', timeout=120).text
if 'حالة نشطة' in ctrl or ('إنهاء' in ctrl and '/end' in ctrl):
    log('0 open incident', 'يوجد حالة مفتوحة — أنهِها أولاً'); sys.exit(1)
p07 = re.search(r'<option value="(\d+)"[^>]*>\s*HZ-07', ctrl).group(1)

# ٥ أولاً (قبل التمرين): بند الخطة في الجاهزية
cell = re.search(r'data-plan="HZ-07">(.*?)</td>', dash, re.S)
log('5 readiness plan cell', ('HZ-07: ' + strip(cell.group(1)).strip()[:90]) if cell else 'missing')
assert cell and 'مزامَنة من الوثيقة: 13 خطوة' in cell.group(1) and ('أدوار بلا شاغل' in cell.group(1) or 'كل الأدوار لها شاغل' in cell.group(1))
assert 'مركز القيادة — بلا خطة استجابة' in dash

# ١) تمرين HZ-07
r, fl = post(sa, '/app/emergency/drills', {'building_id': bid, 'place_id': p07, 'drill_type': 'fire', 'scheduled_at': '2026-12-01 09:00', 'scenario': 'بوابة ١٠-٤ — تمرين القاعات', 'target_time_sec': '300'}, '/app/emergency/drills/create')
drills = sa.get(BASE + '/app/emergency/drills', timeout=120).text
did = re.findall(r'/app/emergency/drills/(\d+)/start', drills)
assert did, 'لا تمرين مجدول بزر بدء'
did = did[0]
r, fl = post(sa, f'/app/emergency/drills/{did}/start', {}, '/app/emergency/drills')
eid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url)
assert eid, fl
eid = eid.group(1); live = r.text
rows = re.findall(r'<tr data-step="(\d+)" data-status="(\w+)"', live)
titles = re.findall(r'<tr data-step="(\d+)"[^>]*>.*?<td><strong>(?:[^<]*)</strong></td>\s*<td[^>]*>[^<]*</td>\s*<td><strong>([^<]+)</strong>', live, re.S)
log('1 drill started', (fl[:40], f'steps={len(rows)}', 'drill_badge=' + str('تمرين' in live)))
assert len(rows) == 13
call = next((sid for sid, t in titles if t == 'استدعاء الطبيب'), None)
arrive = next((sid for sid, t in titles if t.startswith('وصول الطبيب')), None)
time.sleep(12)  # ليتجاوز الاستدعاء نافذته (٥–١٠ ث)
post(sa, f'/app/emergency/incidents/{eid}/steps/{call}/done', {}, f'/app/emergency/incidents/{eid}/live')
post(sa, f'/app/emergency/incidents/{eid}/steps/{arrive}/done', {}, f'/app/emergency/incidents/{eid}/live')
r, fl = post(sa, f'/app/emergency/drills/{did}/end', {'observations': 'بوابة ١٠-٤'}, '/app/emergency/drills')
log('1 drill ended', fl[:60])

# ٢) تقرير الحالة: الالتزام بالخطة
rep = sa.get(BASE + f'/app/emergency/incidents/{eid}/report', timeout=120).text
sec = rep[rep.find('id="planCompliance"'):]
states = re.findall(r'data-state="(\w+)"', sec)
summary = re.search(r'ضمن النافذة (\d+).*?تأخرت (\d+).*?لم تُعلَّم (\d+)', strip(sec), re.S)
ratio = re.search(r'data-plan-ratio="(\d*)"', sec)
log('2 report table', ('rows=' + str(len(states)), 'states=' + str({s: states.count(s) for s in set(states)}), 'summary=' + (str(summary.groups()) if summary else '?'), 'ratio=' + (ratio.group(1) if ratio else '?'), 'cols=' + str(all(c in sec for c in ['المستهدف', 'الفعلي', 'الفارق', 'من', 'ملاحظة']))))
assert len(states) == 13 and states.count('late') >= 1 and states.count('on_time') >= 1 and 'تأخر' in sec

# ٣) شاشة التمارين
drills = sa.get(BASE + '/app/emergency/drills', timeout=120).text
m = re.search(r'>الخطة: ([^<]+)</a>', drills)
log('3 drills badge', (m.group(1).strip() if m else 'missing', '#planCompliance' in drills))
assert m and '#planCompliance' in drills

# ٤) لوحة التقارير
rp = sa.get(BASE + '/app/reports', timeout=120).text
row = re.search(r'data-plan-place="HZ-07">(.*?)</tr>', rp, re.S)
log('4 reports HZ-07', strip(row.group(1)).replace('\n', ' ').strip()[:160] if row else 'missing')
txt = strip(row.group(1)) if row else ''
# الخطوة ١ قد تكون «لا بيانات» إن لم يُسجَّل وصول فريق (لا فريق للمكان في هذه القاعدة)؛ الاستدعاء والوصول عُلّما هنا فلهما أرقام
assert row and 'خطوات الاستجابة الثلاث الأولى لكل مكان' in rp and txt.count('لا بيانات') <= 1 and '10 ث' in txt and '3:00 د' in txt
print('GATE 10-4 PASSED')
