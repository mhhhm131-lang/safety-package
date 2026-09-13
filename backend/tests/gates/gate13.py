# -*- coding: utf-8 -*-
"""
بوابة المرحلة ١٣ — «المظهر الموحد» (قرار ٣٩): شاشة أولى بخمسة أجزاء بالترتيب، ونسخة واحدة تتشكل بحسب الشاشة،
وزر أحمر ثابت على الجوال لمن له نية طوارئ، ومظهر واحد لكل الشاشات.

    python tests/gates/gate13.py [BASE] [--accounts=اسم:كلمة,اسم:كلمة,…] [--shots=مجلد] [--pages=/app/users,/app/incidents]

يتحقق على العنوان لكل حساب مُمرَّر (والافتراضي حسابات التطوير السبعة):
  ١) الشاشة الأولى: الأجزاء بترتيبها — الأرقام (لمن تظهر له) ← الرسم والخريطة ← ما ينتظرك (أو حالته الفارغة) ← أريد أن… ← آخر الإجراءات (إن وُجدت)؛
     مع الأرقام يأتي الرسم والخريطة (٩ أماكن) وخانة الفجوة رقم أو «لا بيانات» — لا صفر مقنّع.
  ٢) الزر الأحمر الثابت يظهر إذا وفقط إذا كان في «أريد أن…» نية «فعّل حالة طارئة» أو «أستغيث الآن» — لكل شاشة لا الأولى وحدها.
  ٣) المظهر الواحد: كل صفحة مُمرَّرة تحمل شريط المعهد بخطه الذهبي والأنماط الموحدة من اللاي أوت، ولا لاي أوت OHSMS.
  ٤) الضيف: صفحة البلاغ بلا زر ثابت (هي الزر)، وصفحة التتبع بزر «أبلّغ عن خطر».
  ٥) لقطات (اختياري --shots): كل حساب على الحاسب (١٤٤٠) والجوال (٣٩٠) بـEdge headless — HTML الصفحة يُحفظ ويُعرض عبر الخادم المحلي.
"""
import os, re, subprocess, sys, requests
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
opt = lambda k, d: next((a.split('=', 1)[1] for a in sys.argv if a.startswith(f'--{k}=')), d)
ACCOUNTS = [a for a in opt('accounts', 'salama:1234,fani:1234,marafiq:1234,shuon:1234,idara:1234,maktab:1234,mudir:1234').split(',') if a]
PAGES = [p for p in opt('pages', '/app/notifications,/app/search?q=HZ').split(',') if p]
SHOTS = opt('shots', '')
EDGE = r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
LOCAL = 'http://127.0.0.1:8089'
PUB = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), 'public')

def log(k, v): print(k, '|', v, flush=True)
def sess():
    s = requests.Session(); s.hooks['response'].append(lambda r, *a, **k: setattr(r, 'encoding', 'utf-8')); return s
def token(html):
    m = re.search(r'csrf-token" content="([^"]+)"', html) or re.search(r'name="_token" value="([^"]+)"', html); return m.group(1) if m else ''
def login(user, pw):
    s = sess(); h = s.get(BASE + '/login', timeout=120).text
    r = s.post(BASE + '/login', data={'_token': token(h), 'username': user, 'password': pw}, allow_redirects=False, timeout=120)
    return None if (r.status_code != 302 or r.headers.get('Location', '').rstrip('/').endswith('/login')) else s

def unified(html):
    """المظهر الواحد: شريط أخضر بخط ذهبي وأنماط اللاي أوت — لا لاي أوت آخر."""
    return ('border-bottom:3px solid var(--gold)' in html and '.sec-h' in html and '.tiles{' in html
            and '<nav class="topbar' in html and 'id="navInbox"' in html)

def sos_expected(html):
    intents = html[html.find('id="intents"'):] if 'id="intents"' in html else ''
    return bool(re.search(r'data-intent="(trigger|sos)"', intents))

def shoot(tag, html):
    if not SHOTS: return
    os.makedirs(SHOTS, exist_ok=True)
    for name, w, h in [('desktop', 1440, 1400), ('mobile', 390, 1400)]:
        inner = os.path.join(PUB, f'_gate13_{tag}_{name}_in.html'); host = os.path.join(PUB, f'_gate13_{tag}_{name}.html')
        open(inner, 'w', encoding='utf-8').write(html)
        open(host, 'w', encoding='utf-8').write(f'<!doctype html><body style="margin:0;background:#888"><iframe src="/_gate13_{tag}_{name}_in.html" style="border:0;width:{w}px;height:{h}px;display:block"></iframe></body>')
        png = os.path.join(SHOTS, f'{tag}_{name}.png')
        try:
            subprocess.run([EDGE, '--headless=new', '--disable-gpu', '--hide-scrollbars', f'--window-size={max(w, 600)},{h}', f'--screenshot={png}', f'{LOCAL}/_gate13_{tag}_{name}.html'], capture_output=True, timeout=90)
        finally:
            os.remove(inner); os.remove(host)
        log(f'5 shot {tag} {name}', 'ok' if os.path.exists(png) else 'MISSING')

# ٤) الضيف
g = sess()
land = g.get(BASE + '/incident', timeout=120).text
track = g.get(BASE + '/incident/track', timeout=120).text
log('4 guest', ('landing_bar=' + str('id="sosBar"' in land), 'track_bar=' + str('data-intent="report"' in track and 'id="sosBar"' in track)))
assert 'id="sosBar"' not in land and 'id="sosBar"' in track and 'data-intent="report"' in track
if SHOTS: shoot('guest_track', track)

# ١–٣) الحسابات
seen = 0
for cred in ACCOUNTS:
    user, pw = cred.split(':', 1)
    s = login(user, pw)
    if not s: log(f'1 {user}', 'skipped: تعذّر الدخول'); continue
    seen += 1
    r = s.get(BASE + '/app', timeout=120, allow_redirects=True)
    h = r.text
    if '/contractor' in r.url:
        log(f'1 {user}', f'contractor portal · unified={unified(h)}'); assert unified(h); continue
    role = re.search(r'· ([^<]+)</span></span>', h)
    ids = ['id="tiles"', 'id="homeDetails"', 'id="inboxList"', 'id="intents"', 'id="recent"']
    pos = [h.find(i) for i in ids]
    present = [i for i, p in zip(ids, pos) if p >= 0]
    order_ok = [p for p in pos if p >= 0] == sorted(p for p in pos if p >= 0)
    has_tiles = pos[0] >= 0
    empty = 'لا شيء ينتظرك الآن' in h
    gap = re.search(r'data-tile="gap".*?<span class="n[^"]*"[^>]*>\s*([^<]+?)\s*<', h, re.S)
    places = len(re.findall(r'class="place lvl\d"', h))
    trend = 'id="trend"' in h or 'id="trendEmpty"' in h
    sos = 'id="sosBar"' in h
    log(f'1 {user}', (role.group(1).strip() if role else '?', 'parts=' + '>'.join(i[4:-1] for i in present), f'order={order_ok}',
                       f'tiles={has_tiles}', f'gap={gap.group(1).strip() if gap else None}', f'places={places}', f'trend={trend}',
                       f'tasks={"empty" if empty else "list"}', f'sos={sos}', f'unified={unified(h)}'))
    assert order_ok, 'الأجزاء ليست بترتيبها'
    assert 'id="intents"' in present, 'أريد أن… غائبة'
    assert empty or pos[2] >= 0, 'لا مهام ولا حالة فارغة'
    assert unified(h), 'مظهر غير موحد'
    if has_tiles:
        assert pos[1] >= 0 and places == 9 and trend and gap, 'الأرقام بلا رسم/خريطة/فجوة'
        assert gap.group(1).strip() == 'لا بيانات' or re.match(r'^[\d.,]+', gap.group(1).strip()), 'الفجوة ليست رقماً ولا «لا بيانات»'
    assert sos == sos_expected(h), 'الزر الأحمر لا يطابق نية الطوارئ'
    assert (('<body class="has-sos">' in h) == sos), 'has-sos لا يطابق الزر'
    for p in PAGES:
        ph = s.get(BASE + p, timeout=120).text
        ok = unified(ph) and (('id="sosBar"' in ph) == sos)
        log(f'3 {user} {p}', f'unified={unified(ph)} sos={"id=\"sosBar\"" in ph}')
        assert ok, f'الصفحة {p} تخالف النمط أو زر الجوال'
    shoot(user, h)

assert seen, 'لم يدخل أي حساب'
print('GATE 13 PASSED')
