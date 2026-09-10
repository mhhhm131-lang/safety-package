# -*- coding: utf-8 -*-
"""
تحويل جداول الكتاب المعتمدة (md) إلى JSON يبذره RiskBookSeeder::applyTables() عند كل نشر.

    python database/data/book/tables/build_tables.py PH-01-01 PH-01-02 ...   (من مجلد backend)
    python database/data/book/tables/build_tables.py --all                     (كل جدول حالته «معتمد»)

المصدر: `tables/<صنف>-<فرع>.md` (جدول لكل خطر تحت عنوان `## <الكود> — …`). الناتج: `tables/<الكود>.json`.
الصيغة: الرأس (الكود، العنوان، الوصف، الخطورة، الاحتمالية، المرجع، الفائدة، قناة الاتصال) + الطبقات الثلاث
(الأسباب قائمة «صنف: تفصيل»، العواقب والأضرار بالفئات التسع بأثر ١–٥ ونطاق للسمعة وتفصيل، الإجراءان، التقييم، الجهة).
لا يُحوَّل جدول لم يُعتمد إلا إن مُرّر كوده صراحةً.
"""
import re, json, io, sys, glob, os
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
HERE = os.path.dirname(os.path.abspath(__file__))
GROUPS = ['الموظفون', 'المتدربون والزوار', 'المقاولون وعمالهم', 'الفريق الأولي للاستجابة', 'ذوو الإعاقة والحالات الخاصة',
          'الممتلكات والأنظمة', 'استمرارية الأعمال', 'السمعة', 'الخسائر المالية والقانونية']
SCOPES = {'محلي': 'local', 'إقليمي': 'regional', 'وطني': 'national', 'دولي': 'international'}
AR = str.maketrans('١٢٣٤٥٦٧٨٩٠', '1234567890')

def strip_md(t): return re.sub(r'\*\*', '', t).strip()
def num(t):
    m = re.search(r'([١-٩1-9])', strip_md(t)); return int(m.group(1).translate(AR))
def parse_affected(cell):
    out = []
    for part in [x.strip() for x in strip_md(cell).split(' · ')]:
        m = re.match(r'(.+?) — ([١-٩1-9]) \S+(?: / (\S+))?(?: \((.*)\))?$', part)
        if not m: raise SystemExit('تعذّر قراءة العواقب: ' + part)
        name = m.group(1).strip()
        if name not in GROUPS: raise SystemExit('فئة غير معروفة: ' + name)
        out.append({'name': name, 'impact': str(num(m.group(2))), 'rep_scope': SCOPES.get(m.group(3)) if m.group(3) else None, 'detail': m.group(4)})
    return out
def parse_causes(cell):
    parts = re.split(r'\.\s+(?=[^\s:]+(?: [^\s:]+)?(?:/[^\s:]+)?:)', strip_md(cell))
    return [x.strip().rstrip('.') for x in parts if x.strip()]

def blocks():
    for f in sorted(glob.glob(os.path.join(HERE, '*.md'))):
        s = open(f, encoding='utf-8').read()
        for m in re.finditer(r'^## ([A-Z]{2}-\d\d-\d\d) — .*?(?=^## [A-Z]{2}-\d\d-\d\d — |\Z)', s, re.M | re.S):
            yield m.group(1), m.group(0)

def convert(code, block):
    cells = {}
    for m in re.finditer(r'^\| (.+?) \| (.+?) \|$', block, re.M):
        cells.setdefault(m.group(1).strip(), []).append(m.group(2).strip())
    head = lambda k: strip_md(cells[k][0])
    prev = {'proactive': cells['الإجراء الوقائي'][0], 'operational': cells['الإجراء الوقائي (الضوابط القائمة)'][0], 'response': cells['الإجراء الوقائي (تسلسل الاستجابة)'][0]}
    phases = {}
    for i, key in enumerate(['proactive', 'operational', 'response']):
        causes_cell = cells['الأسباب'][i] if key != 'response' else cells['الأسباب (ما يفاقم الحدث)'][0]
        phases[key] = {'causes': parse_causes(causes_cell), 'affected': parse_affected(cells['العواقب والأضرار'][i]),
                       'preventive_action': strip_md(prev[key]), 'corrective_action': strip_md(cells['الإجراء التصحيحي'][i]),
                       'residual_assessment': strip_md(cells['التقييم بعد الإجراءات'][i]), 'responsible': strip_md(cells['الجهة والشخص'][i])}
    j = {'code': head('الكود'), 'title': head('العنوان'), 'description': head('الوصف'), 'severity': num(cells['الخطورة'][0]),
         'likelihood': num(cells['الاحتمالية'][0]), 'legal_reference': head('المرجع القانوني'), 'benefit': head('الفائدة من التوثيق'),
         'contact_channel': head('قناة الاتصال'), 'phases': phases}
    for ph in phases.values():
        top = max(int(a['impact']) for a in ph['affected'])
        if top > j['severity']: raise SystemExit(f'{code}: أثر فئة ({top}) أعلى من الخطورة ({j["severity"]}) — قاعدة الاتساق (قرار ٢٧)')
    json.dump(j, open(os.path.join(HERE, f'{code}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
    return j

want = [a for a in sys.argv[1:] if not a.startswith('--')]
for code, block in blocks():
    approved = re.search(r'\*\*الحالة:\*\* \*\*معتمد\*\*', block) is not None
    if code in want or ('--all' in sys.argv and approved):
        j = convert(code, block)
        print(code, ('معتمد' if approved else 'غير معتمد — حُوّل بطلب صريح'), f"{j['severity']}×{j['likelihood']}", {k: (len(v['causes']), len(v['affected'])) for k, v in j['phases'].items()})
