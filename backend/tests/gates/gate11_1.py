# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١١-١ — إزالة الضغطات (قرار ٣٤: «النظام يسأل»).

    python tests/gates/gate11_1.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة]

تعدّ الضغطات فعلاً: الضغطة = صفحة يفتحها الإنسان (GET) أو زر يضغطه (POST). لا يُعدّ الاستطلاع ولا /api ولا الكتابة.
  الشاغل:  رمز QR ← نوع البلاغ ← إرسال (بلا تصنيف)                     الهدف ٣
  الفني:   يفتح البلاغ (= استلمه) ← «عولج» بصورة وسطر ← إرسال            الهدف ٣ (فتح + إرسال = ٢)
  المناوب: يفتح البلاغ العاجل ← «فعّل حالة … الآن» بالنوع والخطورة المقترحين  الهدف ١ تأكيد بعد الفتح
  الشاشة الحية: بلا location.reload
يترك المبنى فارغاً (الحالة تُنهى) ويترك البلاغين مفتوحين للمركز.
"""
import re, sys, base64, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); s.presses = 0; return s
def page(s, url):
    """صفحة يفتحها الإنسان = ضغطة."""
    s.presses += 1
    return s.get(BASE + url, timeout=120).text
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html)
    return m.group(1) if m else ''
def press(s, url, data, html, files=None):
    """زر يضغطه الإنسان على صفحة مفتوحة أمامه = ضغطة."""
    s.presses += 1
    d = dict(data); d['_token'] = token(html)
    r = s.post(BASE + url, data=d, files=files, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    return r, ((flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:120]) if flash else f'no-flash http={r.status_code}')
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    if r.status_code == 302 and r.headers.get('Location', '').rstrip('/').endswith('/login'): return s, 401
    return s, r.status_code
def find_incident(sa, icode):
    lst = sa.get(BASE + '/app/incidents', timeout=120).text
    m = re.search(r'/app/incidents/(\d+)"[^>]*>[^<]*' + icode, lst) or re.search(icode + r'.*?/app/incidents/(\d+)', lst, re.S)
    assert m, 'لم أجد البلاغ في قائمة المركز'
    return m.group(1)

results = []
au, ap = ADMIN.split(':', 1); sa, code = login(au, ap)
assert code == 302, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)'))
dash = sa.get(BASE + '/app/emergency', timeout=120).text
if re.search(r'incidents/\d+/live"[^>]*>[^<]*(?:نشطة|تمت السيطرة)', dash):
    log('0 open incident', 'يوجد حالة مفتوحة — أنهِها أولاً'); sys.exit(1)

# ── الشاغل: ٣ ضغطات بلا تصنيف ──
g = sess()
landing = page(g, '/incident?place=HZ-06')                       # ١ مسح الرمز
form = page(g, '/incident/normal?place=HZ-06')                   # ٢ نوع البلاغ
assert not re.search(r'id="riskId"[^>]*required', form) and not re.search(r'id="riskCat"[^>]*required', form), 'التصنيف ما زال إلزامياً'
p06 = re.search(r'<option value="(\d+)"[^>]*selected[^>]*>\s*HZ-06', form) or re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form)
r, _ = press(g, '/incident/normal', {'place_id': p06.group(1), 'description': 'بوابة ١١-١ — بلاط مكسور قرب المصعد'}, form)  # ٣ إرسال
tcode = re.search(r'code=([A-Z0-9]+)', r.url)
assert tcode, 'لم يصل رمز التتبع — الإرسال بلا خطر رُفض'
track = g.get(BASE + f'/incident/track?code={tcode.group(1)}', timeout=120).text
icode = re.search(r'ش-\d{4}', track).group(0)
results.append(('الشاغل', g.presses, 3))
log('1 occupant', (icode, f'presses={g.presses}', 'no_risk_required=True'))

# المركز يرى «لم يُصنَّف بعد» ويصنّف (ليس من رحلة الشاغل)
iid = find_incident(sa, icode)
show = sa.get(BASE + f'/app/incidents/{iid}', timeout=120).text
log('1 center sees unclassified', 'لم يُصنَّف بعد' in show)
assert 'لم يُصنَّف بعد' in show

# ── الفني: فتح = استلام؛ «عولج» بصورة = بدء + عولج ──
tu, tp = TECH.split(':', 1); st, tcode2 = login(tu, tp)
if tcode2 != 302:
    log('2 technician', f'skipped: تعذّر الدخول بحساب الفني «{tu}» — مرّر --tech=اسم:كلمة')
else:
    if 'حُوّل إلى الفني' not in track:
        # لا فني للمكان في هذه القاعدة: المركز يحيله (ضغطة المركز لا الفني)
        users = sa.get(BASE + '/app/users', timeout=120).text
        pos = users.find(f'>{tu}<'); fid = re.search(r'/app/users/(\d+)/edit', users[pos if pos > 0 else 0:])
        assert fid, f'لم أجد حساب {tu} في المستخدمين'
        rr, fl = press(sa, f'/app/incidents/{iid}/refer', {'field_worker_id': fid.group(1), 'note': 'بوابة ١١-١'}, show)
        log('2 center refers (not tech)', fl[:60])
    d1 = page(st, f'/app/incidents/{iid}')                         # ١ فتح = استلم
    track = g.get(BASE + f'/incident/track?code={tcode.group(1)}', timeout=120).text
    received = 'استلمه الفني' in track
    log('2 open = received', (received, 'button_hidden=' + str('استلمتُ البلاغ' not in d1)))
    assert received and 'استلمتُ البلاغ' not in d1
    r2, fl2 = press(st, f'/app/incidents/{iid}/resolve', {'resolution_summary': 'بوابة ١١-١: بُدّل البلاط المكسور ونُظّف الممر وأُعيد فتحه'}, d1,
                    files={'evidence': ('after.png', PNG, 'image/png')})   # ٢ إرسال
    track = g.get(BASE + f'/incident/track?code={tcode.group(1)}', timeout=120).text
    log('2 resolve with photo', (fl2[:50], 'began=' + str('بدأ الفني المعالجة' in track), 'resolved=' + str('عولج' in track)))
    assert fl2.startswith('success') and 'بدأ الفني المعالجة' in track and 'عولج' in track
    results.append(('الفني', st.presses, 3))
    log('2 technician', f'presses={st.presses}')

# ── المناوب: من البلاغ العاجل إلى الحالة بضغطة واحدة ──
g2 = sess()
form = page(g2, '/incident/urgent?place=HZ-06')
p06 = re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form).group(1)
r, _ = press(g2, '/incident/urgent', {'place_id': p06, 'description': 'بوابة ١١-١ — دخان من مكتب في الدور الثاني'}, form)
t2 = re.search(r'code=([A-Z0-9]+)', r.url).group(1)
ucode = re.search(r'ش-\d{4}', g2.get(BASE + f'/incident/track?code={t2}', timeout=120).text).group(0)
uid = find_incident(sa, ucode)
sa.presses = 0
d = page(sa, f'/app/incidents/{uid}')                               # فتح (لا يُعدّ في «التأكيد»)
typ = re.search(r'name="incident_type"[^>]*>.*?<option value="(\w+)" selected>', d, re.S)
sev = re.search(r'name="severity"[^>]*>.*?<option value="(\w+)" selected>', d, re.S)
btn = re.search(r'فعّل حالة [^<]+ الآن', d)
log('3 proposed', ('type=' + (typ.group(1) if typ else '?'), 'severity=' + (sev.group(1) if sev else '?'), 'button=' + (btn.group(0) if btn else '?')))
assert typ and sev and btn
before = sa.presses
r3, fl3 = press(sa, f'/app/incidents/{uid}/trigger-emergency', {'incident_type': typ.group(1), 'severity': sev.group(1)}, d)  # ١ تأكيد
eid = re.search(r'/app/emergency/incidents/(\d+)/live', r3.url)
assert eid, fl3
live = r3.text
log('3 trigger', (fl3[:50], 'confirm_presses=' + str(sa.presses - before), 'live_no_reload=' + str('location.reload' not in live and 'refreshPanels' in live)))
assert 'location.reload' not in live and 'refreshPanels' in live
results.append(('المناوب من بلاغ (تأكيد)', sa.presses - before, 1))
r4, fl4 = press(sa, f'/app/emergency/incidents/{eid.group(1)}/end', {'final_report': 'بوابة ١١-١ — انتهى'}, live)
log('3 end', fl4[:40])

print()
print('الرحلة | الضغطات | الهدف')
ok = True
for name, n, target in results:
    print(f'{name} | {n} | {target}'); ok = ok and n <= target
print('GATE 11-1 PASSED' if ok else 'GATE 11-1 FAILED')
sys.exit(0 if ok else 1)
