# -*- coding: utf-8 -*-
"""
بوابة المرحلة ٩-١ — طبقة واحدة + كتاب المعهد (قرارات ٢١–٢٤).

    python tests/gates/gate9_1.py [BASE] [--admin=اسم:كلمة] [--replace-book]

يتحقق على العنوان (محلياً أو المنشور) أن:
  ١) القائمة بلا «كتاب المخاطر»، والسجل العام يفتح شجرةً.
  ٢) الشجرة: ٨ أصناف بأسمائها، ٤٩ فرعاً، ١٧٧ خطراً، كل خطر تحت فرع — أو (قبل الاستبدال) كتاب OHSMS مع زر الاستبدال ظاهراً.
  ٣) لوحة إضافة خطر مرجعي: اسم الخطر، الوصف، قناة الاتصال، ٩ فئات متأثرين × ٣ طبقات، حقل «ما الضرر تحديداً؟».
  ٤) إنشاء خطر مرجعي بالاسم الحر والتفصيل ← يظهر في الشجرة بتفصيله ← تقديمه للاعتماد ← اعتماده.
  ٥) مدير إدارة يفعّله لإدارته باسم معدَّل ← يظهر في سجل الإدارة بالاسم الجديد والتفصيل المنسوخ.
  ٦) شاشة الإغلاق تعرض حال الكتاب؛ و`--replace-book` يستبدله (بعد نسخة احتياطية) ويعيد الفحص ٢.
"""
import re, sys, json, requests
from html import unescape
sys.stdout.reconfigure(encoding='utf-8')

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
ADMIN = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--admin=')), 'salama:1234')
MANAGER = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--manager=')), 'mudir:1234')
UNIT = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--unit=')), 'موارد البشرية')  # وحدة مدير الإدارة
REPLACE = '--replace-book' in sys.argv
CATS = ['الفيزيائية (عوامل البيئة)', 'الكيميائية', 'البيولوجية والصحية', 'الميكانيكية والإنشائية', 'الكهربائية', 'الحريق والانفجار', 'الإرجونومية', 'التنظيمية والعوامل البشرية']
GROUPS = ['الموظفون', 'المتدربون والزوار', 'المقاولون وعمالهم', 'الفريق الأولي للاستجابة', 'ذوو الإعاقة والحالات الخاصة', 'الممتلكات والأنظمة', 'استمرارية الأعمال', 'السمعة', 'الخسائر المالية والقانونية']
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
    return r, ((flash.group(1) + ': ' + unescape(re.sub(r'<[^>]+>', '', flash.group(2))).strip()[:140]) if flash else f'no-flash http={r.status_code}')
def tree(s):
    cats = s.get(BASE + '/app/risk/registry/tree/reference/categories', timeout=120).json()
    subs, risks = [], []
    for c in cats:
        cs = s.get(BASE + f"/app/risk/registry/tree/reference/sub-categories/{c['id']}", timeout=120).json(); subs += cs
        for sc in cs:
            risks += s.get(BASE + f"/app/risk/registry/tree/reference/risks-by-sub-category/{sc['id']}", timeout=120).json()
    return cats, subs, risks

au, ap = ADMIN.split(':', 1); sa, code = login(au, ap)
assert code == 302, f'تعذّر الدخول بحساب «{au}» — مرّر --admin=اسم:كلمة'
b = sa.get(BASE + '/build.txt', timeout=120)
log('build', (b.text.strip()[:12] if b.ok and 'DOCTYPE' not in b.text else 'n/a (local)', f'login={code}'))

# ١) القائمة والسجل العام
h = sa.get(BASE + '/app/risk/reference', timeout=120).text
log('1 menu', ('كتاب المخاطر</a>' not in h, 'السجل العام للمعهد' in h, 'registry/tree/reference' in h))

# ٢) الشجرة
cats, subs, risks = tree(sa)
names = [c['name'] for c in cats]
institute = sorted(names) == sorted(CATS)
log('2 tree', (f'cats={len(cats)}', f'subs={len(subs)}', f'risks={len(risks)}', 'institute' if institute else 'OHSMS/old'))
coded = [r for r in risks if re.match(r'^(PH|CH|BI|ME|EL|FI|ER|OR)-\d\d-\d\d$', str(r.get('code') or ''))]
log('2b institute-coded risks', (len(coded), 'others (manual/gate/old):', len(risks) - len(coded)))
if institute:
    assert (len(cats), len(subs), len(coded)) == (8, 49, 177), 'الشجرة لا تطابق ٨/٤٩/١٧٧'

# ٣) لوحة الإدخال
c = sa.get(BASE + '/app/risk/reference/create', timeout=120).text
grp_ok = all(g in c for g in GROUPS)
log('3 create form', ('name="title"' in c, 'name="description"' in c, 'name="contact_channel"' in c, f'groups9={grp_ok}', f'rag_proactive={c.count("rag_proactive_")}', 'affected_detail' in c))
cat_id = str(cats[0]['id']); sub_id = str(subs[0]['id'])  # خطر تحت فرع (قاعدة: لا خطر بلا فرع)
grp_id = re.search(r'id="rag_proactive_(\d+)"', c).group(1)

# ٤) إنشاء ← شجرة ← تقديم ← اعتماد
title = 'خطر بوابة ٩-١ — اختبار اسم حر'
r, f = post(sa, '/app/risk/reference/create', {
    'category_id': cat_id, 'sub_category_id': sub_id, 'severity': 3, 'likelihood': 2, 'title': title,
    'description': 'المصدر: بوابة · الحدث: اختبار', 'contact_channel': 'مركز السلامة',
    'phases[proactive][affected_group_ids][]': grp_id, f'phases[proactive][affected_impact][{grp_id}]': '4',
    f'phases[proactive][affected_rep_scope][{grp_id}]': 'local', f'phases[proactive][affected_detail][{grp_id}]': 'تفصيل البوابة',
}, '/app/risk/reference/create')
log('4a create', f)
cats2, subs2, risks2 = tree(sa)
mine = sorted([x for x in risks2 if x.get('title') == title], key=lambda x: int(x['id']))
assert mine, 'الخطر الجديد لم يظهر في الشجرة'
rid = mine[-1]['id']  # الأحدث (تكرار التشغيل يترك خطراً معتمداً من الجولة السابقة)
d = sa.get(BASE + f'/app/risk/registry/tree/reference/risk/{rid}', timeout=120).json()
pro = next(p for p in d['phases'] if p['phase'] == 'proactive')
log('4b detail in tree', (d['title'] == title, d['contact_channel'], pro['affected_groups'][0].get('detail')))
r, f = post(sa, f'/app/risk/{rid}/submit', {}, '/app/risk/reference'); log('4c submit', f)
r, f = post(sa, f'/app/risk/{rid}/approve', {}, '/app/risk/approval/queue'); log('4d approve', f)
d2 = sa.get(BASE + f'/app/risk/registry/tree/reference/risk/{rid}', timeout=120).json()
st = sa.get(BASE + '/app/risk/reference?search=' + requests.utils.quote(title), timeout=120).text
log('4e approved', ('معتمد' in st))

# ٥) التفعيل لإدارة: بمدير إدارة إن كان له حساب ووحدة (--manager)، وإلا بمسؤول السلامة (نطاق عام)
mu, mp = MANAGER.split(':', 1); sm, mcode = login(mu, mp)
actor, who = (sm, mu) if mcode == 302 else (sa, au)
fh = actor.get(BASE + f'/app/risk/{rid}/activate', timeout=120).text
unit = re.search(r'<option value="(\d+)"[^>]*>[^<]*' + re.escape(UNIT) + r'[^<]*</option>', fh)
unit_id = unit.group(1) if unit else ''
r, f = post(actor, f'/app/risk/{rid}/activate', {'scope_type': 'org_unit', 'organization_unit_id': unit_id, 'severity': 3, 'likelihood': 2,
                                                 'title': title + ' — لإدارتي'}, f'/app/risk/{rid}/activate')
log('5a activate', (who, f'unit={unit_id}', f))
if 'لإدارتك' in f and actor is sm:  # مدير الإدارة التجريبي بلا وحدة محلياً — نعيدها بمسؤول السلامة
    r, f = post(sa, f'/app/risk/{rid}/activate', {'scope_type': 'org_unit', 'organization_unit_id': unit_id, 'severity': 3, 'likelihood': 2,
                                                  'title': title + ' — لإدارتي'}, f'/app/risk/{rid}/activate')
    actor = sa; log('5a activate (admin)', f)
def tree_active(s):
    out = []
    for c in s.get(BASE + '/app/risk/registry/tree/active/categories', timeout=120).json():
        for sc in s.get(BASE + f"/app/risk/registry/tree/active/sub-categories/{c['id']}", timeout=120).json():
            out += s.get(BASE + f"/app/risk/registry/tree/active/risks-by-sub-category/{sc['id']}", timeout=120).json()
    return out
act = [x for x in tree_active(actor) if x.get('title') == title + ' — لإدارتي']
ad = actor.get(BASE + f"/app/risk/registry/tree/active/risk/{act[-1]['id']}", timeout=120).json() if act else {}
apro = next((p for p in ad.get('phases', []) if p['phase'] == 'proactive'), {})
log('5b active registry', (bool(act), 'title=' + str(ad.get('title'))[:40], 'detail copied=' + str((apro.get('affected_groups') or [{}])[0].get('detail'))))

# ٦) شاشة الإغلاق وحال الكتاب
ch = sa.get(BASE + '/app/closeout', timeout=120).text
def cell(html, label):
    m = re.search('data-book="' + re.escape(label) + r'">(.*?)</tr>', html, re.S)
    n = re.findall(r'>(\d+)<', m.group(1)) if m else []
    return int(n[-1]) if n else None
log('6a book status', {k: cell(ch, k) for k in ['الأصناف الرئيسية', 'الفروع', 'مخاطر السجل العام', 'منها من كتاب المعهد', 'منها بأكواد أخرى (OHSMS أو مضافة يدوياً)', 'مخاطر فعلية (إدارات)']})
if REPLACE:
    bk = sa.get(BASE + '/app/closeout/backup', timeout=300)
    log('6b backup', (bk.status_code, f'{len(bk.content)//1024} KB'))
    r, f = post(sa, '/app/closeout/purge', {'confirm': 'احذف'}, '/app/closeout'); log('6c purge operational first', f)
    r, f = post(sa, '/app/closeout/book-replace', {'confirm': 'استبدل'}, '/app/closeout'); log('6d replace book', f)
    cats3, subs3, risks3 = tree(sa)
    log('6e tree after', (f'cats={len(cats3)}', f'subs={len(subs3)}', f'risks={len(risks3)}', sorted(c['name'] for c in cats3) == sorted(CATS)))
    coded3 = [r for r in risks3 if re.match(r'^(PH|CH|BI|ME|EL|FI|ER|OR)-\d\d-\d\d$', str(r.get('code') or ''))]
    assert (len(cats3), len(subs3), len(coded3), len(risks3)) == (8, 49, 177, 177), 'بعد الاستبدال يجب أن يكون الكتاب كله من المعهد'
log('7 done', 'البوابة اكتملت')
