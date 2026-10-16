# -*- coding: utf-8 -*-
"""بوابة ٣١-١ «المقارنة لكل مبنى» (قرار ٩٠) — على المحلي بـWebKit:
مسؤول السلامة من ملف المبنى يفتح «المقارنة مع اللائحة» فيجد ١٦ مادة وبنود ١٢ و١٣ و١٤ والجاهزية صفر؛
منسق سلامة فرع الدمام على الجوال يجعل المادة ٨ «قيد الإعداد» بمكلّف وموعد (ضغطتان) والبند ١٣-٤ مستوفى؛
الجاهزية تتغير والمادة ١٣ تقول «مستوفى 1 من 12»؛ الملز كما كان؛ الوثيقة لم تتغير بصمةً؛ مسؤول السلامة يرى تعديل الدمام وسجله؛
موظف الدمام يقرأ بلا زر؛ منسق إدارة في الملز بلا زر.
الاستعمال: webkit-31-1.py <كلمة حسابات البوابة> <مبنى الملز id> <مبنى الدمام id> <مجلد الصور> — الحسابات من seed-31-1.php."""
import sys, json, hashlib, os, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, main_b, dmm_b, out = sys.argv[1:5]
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
DOC = os.path.join(ROOT, "comparison.html")
HOLDER = "مدير فرع الدمام (بوابة ٣١-١)"
checks = []
def ok(name, cond, extra=""):
    checks.append((name, bool(cond), extra)); print(("✓ " if cond else "✗ ") + name + ((" — " + extra) if extra else ""), flush=True)
def sha(p):
    with open(p, "rb") as f: return hashlib.sha1(f.read()).hexdigest()

def login(page, u, p):
    page.set_default_timeout(120000)
    for _ in range(4):
        page.goto(BASE + "/login", wait_until="domcontentloaded")
        if page.locator("input[name=username]").count() == 0:
            break
        page.fill("input[name=username]", u); page.fill("input[name=password]", p)
        with page.expect_navigation(wait_until="domcontentloaded"):
            page.locator("form:has(input[name=username]) button[type=submit]").click()
        page.wait_for_load_state("networkidle")
        if "/login" not in page.url:
            break
        page.wait_for_timeout(5000)
    assert "/login" not in page.url, f"فشل دخول {u}: {page.url}"

def readiness(page):
    return int(page.locator("[data-readiness]").get_attribute("data-readiness") or "-1")

doc_before = sha(DOC)
with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ مسؤول السلامة: من ملف المبنى إلى المقارنة
    a = b.new_context(viewport={"width": 1200, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e))); ap.on("dialog", lambda d: d.accept())
    login(ap, "g311.salama", pw)
    ap.goto(f"{BASE}/app/emergency/buildings/{main_b}", wait_until="networkidle")
    door = ap.locator("a[data-compliance]")
    ok("ملف المبنى: زر «المقارنة مع اللائحة» بجاهزية المبنى وعدد ما لم يُقيَّم", door.count() == 1 and "لم يُقيَّم" in door.inner_text(), door.inner_text() if door.count() else "")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        door.click()
    ap.wait_for_load_state("networkidle")
    ok("شاشة المقارنة: ١٦ مادة، وبنود ١٢ و١٣ و١٤ (١١ و١٢ و١٠)، ورابط الوثيقة", ap.locator(".cp-art[data-article]").count() == 16 and ap.locator("#a12 .cp-row.item").count() == 11 and ap.locator("#a13 .cp-row.item").count() == 12 and ap.locator("#a14 .cp-row.item").count() == 10 and ap.locator("a[href='/comparison.html']").count() == 1)
    mlz_ready = readiness(ap)
    ok("جاهزية الملز قبل التقييم: الرقم ظاهر و«لم يُقيَّم» معدود", mlz_ready >= 0 and "لم يُقيَّم" in ap.locator("[data-readiness]").inner_text(), str(mlz_ready))
    ap.screenshot(path=out + "/31-1-malaz.png", full_page=False)

    # ٢ منسق سلامة فرع الدمام على الجوال: المادة ٨ قيد الإعداد ← البند ١٣-٤ مستوفى
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e))); mp.on("dialog", lambda d: d.accept())
    login(mp, "g311.coord", pw)
    mp.goto(f"{BASE}/app/compliance", wait_until="networkidle")
    dmm_title = mp.locator("h1").inner_text()
    before = readiness(mp)
    r8 = mp.locator("#a8 .cp-row"); rid = r8.get_attribute("id")[1:]
    ok("منسق الفرع يفتح مقارنة مبناه بأزرار التعديل (المادة ١٣ بنودها لا رأسها)", "الملز" not in dmm_title and mp.locator(f"button.cp-edit[data-for='e{rid}']").count() == 1 and mp.locator("#a13 button.cp-edit").count() == 12 and mp.locator("#a13 > div.d-flex button.cp-edit").count() == 0, dmm_title)
    presses = 0
    mp.click(f"button.cp-edit[data-for='e{rid}']"); presses += 1
    ok("ضغطة «تعديل» تفتح الحالات الخمس والوضع والإجراء والمكلّف والموعد والملاحظات", mp.locator(f"#e{rid}").is_visible() and mp.locator(f"#e{rid} input[name=status]").count() == 5 and mp.locator(f"#e{rid} textarea[name=action]").count() == 1 and mp.locator(f"#e{rid} input[name=holder_name]").count() == 1 and mp.locator(f"#e{rid} input[name=due_date]").count() == 1)
    mp.screenshot(path=out + "/31-1-dmm-edit-mobile.png", full_page=False)
    mp.locator(f"label[for='st{rid}in_progress']").click(force=True)  # زر الحالة تسمية فوق راديو مخفي (Bootstrap btn-check)
    ok("ضغطة على «قيد الإعداد» تختارها", mp.locator(f"#st{rid}in_progress").is_checked())
    mp.fill(f"#e{rid} textarea[name=action]", "اعتماد صلاحيات مسؤول السلامة في الفرع"); mp.fill(f"#e{rid} input[name=holder_name]", HOLDER); mp.fill(f"#e{rid} input[name=due_date]", "2026-12-31")
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{rid} button.btn-g"); presses += 1
    mp.wait_for_load_state("networkidle")
    row = mp.locator(f"#r{rid}")
    ok("حُفظ بثلاث ضغطات (تعديل، الحالة، حفظ): «قيد الإعداد» والمكلّف والموعد والإجراء و«عدّله …»", presses == 2 and row.get_attribute("data-status") == "in_progress" and HOLDER in row.inner_text() and "2026-12-31" in row.inner_text() and "عدّله منسق سلامة فرع الدمام" in row.inner_text())
    mp.screenshot(path=out + "/31-1-dmm-saved-mobile.png", full_page=False)
    i134 = mp.locator("#a13 .cp-row.item[data-row='13.4']"); iid = i134.get_attribute("id")[1:]
    mp.click(f"button.cp-edit[data-for='e{iid}']"); mp.locator(f"label[for='st{iid}met']").click(force=True)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{iid} button.btn-g")
    mp.wait_for_load_state("networkidle")
    after = readiness(mp)
    ok("البند ١٣-٤ مستوفى: المادة ١٣ تقول «مستوفى 1 من 12» والجاهزية تغيّرت", mp.locator(f"#r{iid}").get_attribute("data-status") == "met" and "مستوفى 1 من 12" in mp.locator("[data-summary='13']").inner_text() and after > before, f"{before} → {after}")

    # ٣ الملز كما كان، الوثيقة لم تُمس، مسؤول السلامة يرى الدمام
    ap.goto(f"{BASE}/app/compliance?b={main_b}", wait_until="networkidle")
    ok("الملز كما كان: الجاهزية نفسها ولا صف معدَّل ولا مكلّف الدمام", readiness(ap) == mlz_ready and ap.locator(".cp-row[data-edited='1']").count() == 0 and HOLDER not in ap.locator("body").inner_text())
    ok("comparison.html لم تتغير بصمةً", sha(DOC) == doc_before)
    ap.goto(f"{BASE}/app/compliance?b={dmm_b}", wait_until="networkidle")
    logs = [ap.locator("[data-log]").nth(i).inner_text() for i in range(ap.locator("[data-log]").count())]
    ok("مسؤول السلامة يبدّل إلى الدمام فيرى التعديل والسجل باسم المنسق وخانة «الحالة»", ap.locator("h1").inner_text() == dmm_title and HOLDER in ap.locator("body").inner_text() and len(logs) >= 2 and any(("منسق سلامة فرع الدمام" in l and "الحالة" in l) for l in logs), str(len(logs)))
    ap.goto(f"{BASE}/app/emergency/buildings/{dmm_b}", wait_until="networkidle")
    ok("ملف مبنى الدمام: زر المقارنة يحمل جاهزيته الجديدة", ap.locator("a[data-compliance]").get_attribute("data-compliance") == str(after))
    ap.screenshot(path=out + "/31-1-dmm-log.png", full_page=True)

    # ٤ موظف الدمام بلا زر؛ منسق إدارة في الملز بلا زر
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g311.emp", pw)
    r = ep.goto(f"{BASE}/app/compliance", wait_until="networkidle")
    ok("موظف الدمام: يقرأ مقارنة مبناه بالمكلّف الجديد بلا زر «تعديل»", r is not None and r.status == 200 and HOLDER in ep.locator("body").inner_text() and ep.locator("button.cp-edit").count() == 0)
    c = b.new_context(viewport={"width": 1200, "height": 900}); cp = c.new_page()
    login(cp, "g311.mlzc", pw)
    r = cp.goto(f"{BASE}/app/compliance?b={dmm_b}", wait_until="networkidle")
    ok("منسق إدارة في الملز: يبقى على الملز وبلا زر", r is not None and r.status == 200 and cp.locator("h1").inner_text() != dmm_title and cp.locator("button.cp-edit").count() == 0)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
