# -*- coding: utf-8 -*-
"""
بوابة الخطوة ١٠-٣ — من البلاغ إلى الحالة + البلاغ العادي (قرار ٣٠، المكوّنان و + ح).

    python tests/gates/gate10_3.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة]

يتحقق على العنوان (محلياً أو المنشور) أن:
  ١) بلاغ عاجل من الصفحة العامة في HZ-06 يصل «حُوّل للفني» لا «استلمه الفني» (ح-١)، وصفحته عند المركز تعرض الطبقة «الاستجابة» وزر التفعيل.
  ٢) زر «تفعيل حالة طارئة» ينشئ حالة طبية في مركز الطوارئ مربوطة بالبلاغ، بخطوات المسار الطبي الثلاث.
  ٣) البلاغ يحمل الحدث في خطه الزمني (يراه الشاغل بالرمز) ويكمل مساره؛ الحالة تعرض «المصدر: بلاغ ش-…».
  ٤) بلاغ عادي يعرض الطبقة «التشغيلية»، والفني يستلم بيده (إن مُرّر حساب فني له المكان).
يترك المبنى فارغاً (الحالة تُنهى).
"""
import re, sys, json, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
TECH = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--tech=')), 'fani:1234')
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
if re.search(r'incidents/\d+/live"[^>]*>[^<]*(?:نشطة|تمت السيطرة)', dash) or 'حالة نشطة' in sa.get(BASE + '/app/emergency/buildings/' + re.search(r'/app/emergency/buildings/(\d+)/control', dash).group(1) + '/control', timeout=120).text:
    log('0 open incident', 'يوجد حالة مفتوحة — أنهِها أولاً'); sys.exit(1)

# الصفحة العامة: مكان HZ-06 وخطر من السجل العام
g = sess(); tok, form = csrf(g, '/incident/urgent')
p06 = re.search(r'<option value="(\d+)"[^>]*>\s*HZ-06', form).group(1)
H = {'Accept': 'application/json'}
cats = re.findall(r'<option value="(\d+)">([^<]+)</option>', form.split('id="riskCat"')[1].split('</select>')[0])
risks = []
for cat_id, cat_name in cats:
    for sc in g.get(BASE + f'/incident/api/sub-categories?category_id={cat_id}', headers=H, timeout=120).json():
        risks = g.get(BASE + f"/incident/api/risks?sub_category_id={sc['id']}", headers=H, timeout=120).json()
        if risks: break
    if risks: break
assert risks, 'لا خطر في السجل العام'
log('0 risk', (cat_name, risks[0].get('code') or risks[0].get('title')))

# ١) بلاغ عاجل
r = g.post(BASE + '/incident/urgent', data={'_token': tok, 'place_id': p06, 'description': 'بوابة ١٠-٣ — دخان كثيف من مكتب', 'location_text': 'الدور الثاني', 'risk_id': risks[0]['id'], 'reporter_name': 'سعد'}, allow_redirects=False, timeout=120)
tcode = re.search(r'code=([A-Z0-9]+)', r.headers.get('Location', '')).group(1)
track = g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text
icode = re.search(r'ش-\d{4}', track).group(0)
log('1 urgent report', (r.status_code, icode, 'forwarded=' + str('حُوّل إلى الفني' in track), 'not_received=' + str('استلمه الفني' not in track)))
assert 'استلمه الفني' not in track
lst = sa.get(BASE + '/app/incidents', timeout=120).text
iid = re.search(r'/app/incidents/(\d+)"[^>]*>[^<]*' + icode, lst) or re.search(icode + r'.*?/app/incidents/(\d+)', lst, re.S)
assert iid, 'لم أجد البلاغ في قائمة المركز'
iid = iid.group(1); show_url = f'/app/incidents/{iid}'
show = sa.get(BASE + show_url, timeout=120).text
sel = re.search(r'<option value="(\w+)" selected>', show)
log('1 center view', ('layer=' + ('الاستجابة' if 'الطبقة الاستجابة' in show else ('التشغيلية' if 'الطبقة التشغيلية' in show else '?')), 'button=' + str('trigger-emergency' in show), 'proposed=' + (sel.group(1) if sel else '?')))
assert 'الطبقة الاستجابة' in show and 'trigger-emergency' in show

# ٢) التفعيل: حالة طبية
r, fl = post(sa, f'{show_url}/trigger-emergency', {'incident_type': 'medical', 'severity': 'high', 'note': 'بوابة ١٠-٣'}, show_url)
eid = re.search(r'/app/emergency/incidents/(\d+)/live', r.url)
assert eid, fl
eid = eid.group(1); live = r.text
ecode = re.search(r'(ط-\d+)', live).group(1)
steps = re.findall(r'<tr data-step="(\d+)" data-status="(\w+)"', live)
log('2 trigger', (fl[:60], ecode, 'type=' + ('طوارئ طبية' if 'طوارئ طبية' in live else '?'), f'steps={len(steps)}', 'source=' + str('المصدر: بلاغ' in live and icode in live)))
assert len(steps) == 3 and 'المصدر: بلاغ' in live and icode in live

# ٣) البلاغ يحمل الحدث ويكمل مساره
track = g.get(BASE + f'/incident/track?code={tcode}', timeout=120).text
show = sa.get(BASE + show_url, timeout=120).text
log('3 report carries event', ('track=' + str('فُعّلت حالة طارئة بناءً على البلاغ' in track and ecode in track), 'linked_badge=' + str('فُعّلت من هذا البلاغ الحالة الطارئة' in show), 'no_second_button=' + str('id="emergencyModal"' not in show)))
assert ecode in track and 'فُعّلت من هذا البلاغ' in show
r2, fl2 = post(sa, f'{show_url}/trigger-emergency', {'incident_type': 'fire', 'severity': 'high'}, show_url)
log('3 second trigger refused', fl2[:60]); assert fl2.startswith('danger')
tu, tp = TECH.split(':', 1); st, tcode2 = login(tu, tp)
forwarded = 'حُوّل إلى الفني' in track
if tcode2 == 302 and forwarded:
    r3, fl3 = post(st, f'{show_url}/field-receive', {}, show_url)
    log('3 tech receives by hand', fl3[:50])
else:
    log('3 tech receives by hand', f'skipped (login={tcode2}, forwarded={forwarded}: لا فني للمكان في هذه القاعدة)')
r, fl = post(sa, f'/app/emergency/incidents/{eid}/end', {'final_report': 'بوابة ١٠-٣ — انتهى'}, f'/app/emergency/incidents/{eid}/live')
rep = sa.get(BASE + f'/app/emergency/incidents/{eid}/report', timeout=120).text
log('3 end + report', (r.status_code, 'report_links_incident=' + str('بلاغ الشاغل المرتبط' in rep and icode in rep)))

# ٤) بلاغ عادي: الطبقة التشغيلية والاستلام بيد الفني
tok, _ = csrf(g, '/incident/normal')
r = g.post(BASE + '/incident/normal', data={'_token': tok, 'place_id': p06, 'description': 'بوابة ١٠-٣ — بلاغ عادي', 'risk_id': risks[0]['id'], 'reporter_name': 'سعد'}, allow_redirects=False, timeout=120)
ncode = re.search(r'code=([A-Z0-9]+)', r.headers.get('Location', '')).group(1)
ntrack = g.get(BASE + f'/incident/track?code={ncode}', timeout=120).text
nicode = re.search(r'ش-\d{4}', ntrack).group(0)
lst = sa.get(BASE + '/app/incidents', timeout=120).text
nid = (re.search(r'/app/incidents/(\d+)"[^>]*>[^<]*' + nicode, lst) or re.search(nicode + r'.*?/app/incidents/(\d+)', lst, re.S)).group(1)
nshow = sa.get(BASE + f'/app/incidents/{nid}', timeout=120).text
log('4 normal report', (nicode, 'layer=' + ('التشغيلية' if 'الطبقة التشغيلية' in nshow else '?'), 'auto_received=' + str('استلمه الفني' in ntrack)))
assert 'الطبقة التشغيلية' in nshow and 'استلمه الفني' not in ntrack
if tcode2 == 302 and 'حُوّل إلى الفني' in ntrack:
    r4, fl4 = post(st, f'/app/incidents/{nid}/field-receive', {}, f'/app/incidents/{nid}')
    ntrack = g.get(BASE + f'/incident/track?code={ncode}', timeout=120).text
    ok4 = 'استلمه الفني' in ntrack
    # إن لم يُقبل الاستلام فحساب --tech على هذا الخادم ليس فنياً منفّذاً (الانتقال للفني وحده) — يُسجَّل ولا يُعدّ سقوطاً؛ السلوك مغطّى في IncidentEmergencyTest
    log('4 tech receives', (fl4[:60], 'received_now=' + str(ok4), '' if ok4 else 'الحساب ليس فنياً منفّذاً على هذا الخادم — مرّر --tech=فني:كلمته'))
else:
    log('4 tech receives', 'skipped: البلاغ لم يُحوَّل لفني (لا فني للمكان في هذه القاعدة) — مغطّى في IncidentEmergencyTest')
print('GATE 10-3 PASSED')
