# -*- coding: utf-8 -*-
"""بوابة المرحلة ٦ (المقاولون والعمال) على HTTP: مقاول يُسجَّل ← يُؤهَّل (مستندات + ملف + مسبق/لاحق) ← مشروع في مكان ← عماله ← بوابته.
التشغيل: PYTHONIOENCODING=utf-8 python gate6.py https://ipa-safety.onrender.com  (الحسابات salama/coord/fani بكلمة 1234؛ ينشئ حساب مقاول g6.muqawil)."""
import re, sys, time, io, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://ipa-safety.onrender.com'
H = {'Accept': 'application/json'}
TAG = 'بوابة٦-' + time.strftime('%H%M')
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
    assert r.status_code == 302 and 'login' not in r.headers.get('Location', ''), (user, r.status_code)
    return s
def post(s, url, data, page, files=None, method='post'):
    tok, _ = csrf(s, page)
    d = dict(data); d['_token'] = tok
    if method != 'post': d['_method'] = method.upper()
    r = s.post(BASE + url, data=d, files=files, allow_redirects=True, timeout=120)
    flash = re.search(r'alert alert-(success|danger|warning)[^>]*>(.*?)</div>', r.text, re.S)
    err = re.search(r'invalid-feedback[^>]*>(.*?)</div>', r.text, re.S)
    return r, (flash.group(1) + ': ' + re.sub(r'<[^>]+>', '', flash.group(2)).strip()[:120]) if flash else (('validation: ' + re.sub(r'<[^>]+>', '', err.group(1)).strip()[:100]) if err else f'no-flash http={r.status_code}')
def ok(s, url):
    r = s.get(BASE + url, timeout=120); return r.status_code

sa = login('salama', '1234')
b = sa.get(BASE + '/build.txt', timeout=120); log('build', b.text.strip()[:12] if b.ok else 'n/a (local)')

# ٠) الشاشات تفتح لمسؤول السلامة
log('screens (salama)', [(u, ok(sa, u)) for u in ['/app/external-parties', '/app/projects', '/app/workers', '/app/competency/matrix', '/app/competency/trades', '/app/settings/contractor-channels']])

# ١) تسجيل المقاول
r, f = post(sa, '/app/external-parties', {'name': f'{TAG} شركة الصيانة', 'party_type': 'contractor', 'cr_number': '1010' + time.strftime('%M%S'), 'contact_person': 'م. سعد', 'phone': '0500000001', 'email': 'g6@example.test'}, '/app/external-parties/create')
pid = re.search(r'/app/external-parties/(\d+)', r.url).group(1)
log('1 contractor registered', (pid, f))

# ٢) التأهيل: المستندان الإلزاميان (سجل + تأمين) ← توثيق ← الملف اليدوي ← درجة الثقة
for t, n in [('cr', 'السجل التجاري'), ('insurance', 'وثيقة التأمين')]:
    r, f = post(sa, f'/app/external-parties/{pid}/documents', {'name': n, 'document_type': t, 'expiry_date': '2027-12-31'}, f'/app/external-parties/{pid}/documents',
                files={'file': (f'{t}.pdf', io.BytesIO(b'%PDF-1.4 gate6'), 'application/pdf')})
    log(f'2 document {t} uploaded', f)
docs = sa.get(BASE + f'/app/external-parties/{pid}/documents', timeout=120).text
for did in re.findall(r'data-doc="(\d+)"', docs):
    post(sa, f'/app/external-parties/{pid}/documents/{did}/verify', {}, f'/app/external-parties/{pid}/documents')
docs = sa.get(BASE + f'/app/external-parties/{pid}/documents', timeout=120).text
log('2 documents verified', (docs.count('data-verified="1"'), '✓' in docs))
r, f = post(sa, f'/app/external-parties/{pid}/profile', {'cr_expiry_date': '2027-12-31', 'insurance_provider': 'التعاونية', 'insurance_policy_number': 'P-1', 'insurance_expiry_date': '2027-06-30'}, f'/app/external-parties/{pid}/profile', method='put')
prof = r.text
score = re.search(r'data-trust="(\d+)"', sa.get(BASE + f'/app/external-parties/{pid}', timeout=120).text)
checks = re.findall(r'data-check="([a-z_]+)" data-passed="(\d)"', prof)
log('2 profile saved → trust score / checks', (f, score.group(1) if score else None, dict(checks)))

# ٣) مشروع في مكان HZ-06 ← ربط المقاول ← التأهيل المسبق ← اللاحق
create = sa.get(BASE + '/app/projects/create', timeout=120).text
p06 = re.search(r'<option value="(\d+)"[^>]*>HZ-06', create).group(1)
r, f = post(sa, '/app/projects', {'name': f'{TAG} صيانة التكييف', 'code': 'G6-' + time.strftime('%M%S'), 'place_id': p06, 'status': 'active', 'start_date': '2026-10-01'}, '/app/projects/create')
prj = re.search(r'/app/projects/(\d+)', r.url).group(1)
log('3 project in HZ-06', (prj, f, 'HZ-06' in r.text))
r, f = post(sa, f'/app/projects/{prj}/contractors/assign', {'external_party_id': pid, 'role': 'main', 'activity_scope': 'صيانة وحدات التكييف', 'contract_start_date': '2026-10-01', 'contract_end_date': '2027-09-30'}, f'/app/projects/{prj}/contractors/assign')
log('3 contractor linked', f)
cpage = r.text
pcid = re.search(r'/app/projects/%s/contractors/(\d+)/transition' % prj, cpage).group(1)
for st in ['pre_review', 'pre_approved', 'post_review', 'post_approved']:
    r, f = post(sa, f'/app/projects/{prj}/contractors/{pcid}/transition', {'status': st}, f'/app/projects/{prj}/contractors'); log(f'3 qualification → {st}', f)
log('3 party page shows post_approved', 'data-status="post_approved"' in sa.get(BASE + f'/app/external-parties/{pid}', timeout=120).text)

# ٤) حساب المقاول (مشرف مقاول مربوط بالطرف) — من شاشة المستخدمين
uname = 'g6.muqawil'
r, f = post(sa, '/app/users', {'name': 'مشرف ' + TAG, 'username': uname, 'password': '123456', 'role': 'contractor_supervisor', 'external_party_id': pid}, '/app/users/create')
if 'no-flash' in f or 'danger' in f:
    # الحساب موجود من تشغيل سابق: أعد ربطه بالطرف الجديد
    users = sa.get(BASE + '/app/users', timeout=120).text
    uid = re.search(r'/app/users/(\d+)/edit[^>]*>[^<]*</a>[^\n]*%s' % uname, users) or re.search(r'%s.*?/app/users/(\d+)/edit' % uname, users, re.S)
    if uid:
        r, f = post(sa, f'/app/users/{uid.group(1)}', {'name': 'مشرف ' + TAG, 'username': uname, 'password': '123456', 'role': 'contractor_supervisor', 'external_party_id': pid}, f'/app/users/{uid.group(1)}/edit', method='put')
log('4 contractor account created/relinked', f)

# ٥) بوابة المقاول: يرى طرفه فقط، يسجّل عاملاً ويقدّمه؛ لا يرى الأطراف الأخرى ولا يعتمد
mq = login(uname, '123456')
home = mq.get(BASE + '/app', timeout=120)
log('5 contractor login → portal', (home.url.endswith('/app/contractor'), 'بوابة المقاول' in home.text, 'data-status="post_approved"' in home.text))
log('5 contractor cannot list all parties / create project', (mq.get(BASE + '/app/projects/create', timeout=120).status_code, len(re.findall(r'/app/external-parties/(\d+)"', mq.get(BASE + '/app/external-parties', timeout=120).text))))
wc = mq.get(BASE + '/app/workers/create', timeout=120).text
trade = re.search(r'name="trade_id"[^>]*>.*?<option value="(\d+)"', wc, re.S).group(1)
r, f = post(mq, '/app/workers', {'full_name': 'عامل ' + TAG, 'national_id': '2' + time.strftime('%m%d%H%M%S'), 'trade_id': trade, 'external_party_id': pid, 'project_id': prj, 'place_id': p06, 'phone': '0500000002'}, '/app/workers/create')
wid = re.search(r'/app/workers/(\d+)', r.url).group(1)
log('5 worker registered by contractor (draft)', (wid, f))
r, f = post(mq, f'/app/workers/{wid}/transition', {'status': 'submitted'}, f'/app/workers/{wid}'); log('5 contractor submits worker', f)
tok, _ = csrf(mq, f'/app/workers/{wid}')
log('5 contractor cannot approve (403)', mq.post(BASE + f'/app/workers/{wid}/transition', data={'_token': tok, 'status': 'induction'}, allow_redirects=False, timeout=120).status_code)

# ٦) مسؤول السلامة يعتمد العامل حتى «مصرّح بالعمل»؛ الكفاءة؛ المشرف يرى الحالة
q = sa.get(BASE + '/app/workers/approval-queue', timeout=120).text
log('6 worker in approval queue', f'/app/workers/{wid}' in q)
for st in ['induction', 'training', 'approved', 'work_authorized']:
    r, f = post(sa, f'/app/workers/{wid}/transition', {'status': st}, f'/app/workers/{wid}'); log(f'6 worker → {st}', f)
log('6 contractor sees work_authorized', 'data-worker-status="work_authorized"' in mq.get(BASE + f'/app/workers/{wid}', timeout=120).text)
log('6 competency pages', (ok(sa, f'/app/competency/worker/{wid}'), ok(sa, f'/app/competency/contractor/{pid}'), ok(sa, f'/app/projects/{prj}/dashboard')))
r, f = post(sa, '/app/competency/trades', {'code': 'G6-' + time.strftime('%M%S'), 'name': 'فني تكييف ' + TAG, 'level': 'occupation'}, '/app/competency/trades'); log('6 trade added from screen', f)

# ٧) الأدوار: الفني 403؛ مدير الإدارة يرى ولا ينشئ؛ المكتب الاستشاري (طرف بلا ربط) يرى قوائم فارغة
fa = login('fani', '1234'); md = login('mudir', '1234'); mk = login('maktab', '1234')
log('7 tech 403 projects/parties', (ok(fa, '/app/projects'), ok(fa, '/app/external-parties')))
log('7 dept manager: list 200, create 403', (ok(md, '/app/projects'), ok(md, '/app/projects/create')))
log('7 consultant office (no party linked): parties list empty', (ok(mk, '/app/external-parties'), len(re.findall(r'/app/external-parties/(\d+)"', mk.get(BASE + '/app/external-parties', timeout=120).text))))
print('GATE6 DONE')
