# -*- coding: utf-8 -*-
"""بوابة ٣٠-١ «كلام المكان — خطة السلامة» (قرار ٨٩) — على المحلي بـWebKit:
مسؤول السلامة يفتح ملف قبو الملز فيجد زر «خطة السلامة» يفتح شاشة النظام بصفوف الوثيقة (الجدولان كما في الورقة)؛
منسق سلامة فرع الدمام على الجوال يعدّل «المسؤول» في مهمة بضغطتين (تعديل ← حفظ) ويسم مهمة «لا ينطبق هنا»؛
قبو الملز كما كان؛ الوثيقة لم تتغير بصمةً؛ مسؤول السلامة يرى «عدّله فلان» وسجل التعديلات؛ موظف الدمام يقرأ بلا زر؛ منسق الملز 403.
الاستعمال: webkit-30-1.py <كلمة حسابات البوابة> <قبو الدمام id> <قبو الملز id> <مجلد الصور>  — الحسابات من seed-30-1.php."""
import sys, json, hashlib, os
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, dmm_id, mlz_id, out = sys.argv[1:5]
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))  # مجلد المستودع الأعلى (ملفات المعهد)
DOC = os.path.join(ROOT, "HZ-01-basement", "safety-plan.html")
NEW_WHO = "مدير فرع الدمام (تجربة بوابة ٣٠-١)"
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

doc_before = sha(DOC)
with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ مسؤول السلامة: ملف قبو الملز ← الزر يفتح شاشة النظام
    a = b.new_context(viewport={"width": 1200, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e)))
    login(ap, "g301.salama", pw)
    ap.goto(f"{BASE}/app/places/{mlz_id}/file", wait_until="networkidle")
    href = ap.locator("#pfPlanSafety").get_attribute("href") or ""
    ok("ملف قبو الملز: زر «خطة السلامة» يفتح شاشة النظام ويعدّ المهام", href.endswith(f"/app/places/{mlz_id}/safety-plan") and "10 مهام" in ap.locator("#pfPlanSafety").inner_text(), href)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("#pfPlanSafety")
    ap.wait_for_load_state("networkidle")
    rows = ap.locator("tr[data-task]")
    t = ap.locator("body").inner_text()
    ok("شاشة الخطة: ١٠ صفوف في طبقتين، ورابط الوثيقة الأصلية", rows.count() == 10 and "الطبقة الاستباقية" in t and "الطبقة التشغيلية" in t and ap.locator("a[href='/HZ-01-basement/safety-plan.html']").count() == 1)
    first_task = rows.nth(0).locator(".sp-task").inner_text().split("\n")[0].strip()
    mlz_who = rows.nth(0).locator("td[data-f=responsible]").inner_text().strip()
    ok("الصف الأول كما في الورقة: المسؤول «مدير المرافق والصيانة»", mlz_who == "مدير المرافق والصيانة", mlz_who)
    ap.screenshot(path=out + "/30-1-malaz-plan.png", full_page=True)

    # ٢ منسق سلامة فرع الدمام على الجوال: تعديل ← حفظ
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e)))
    login(mp, "g301.coord", pw)
    mp.goto(f"{BASE}/app/places/{dmm_id}/safety-plan", wait_until="networkidle")
    drows = mp.locator("tr[data-task]")
    ok("قبو الدمام: الصفوف العشرة نفسها والمهمة الأولى نفسها", drows.count() == 10 and drows.nth(0).locator(".sp-task").inner_text().split("\n")[0].strip() == first_task)
    tid = drows.nth(0).get_attribute("data-task")
    ok("للمنسق زر «تعديل» والنموذج مطوي", mp.locator(f"button.sp-edit[data-for='e{tid}']").count() == 1 and mp.locator(f"#e{tid}").is_hidden())
    presses = 0
    mp.click(f"button.sp-edit[data-for='e{tid}']"); presses += 1
    ok("ضغطة «تعديل» تفتح الصف بخاناته الثلاث ومعها «لا ينطبق هنا»", mp.locator(f"#e{tid}").is_visible() and mp.locator(f"#e{tid} input[name=responsible]").count() == 1 and mp.locator(f"#e{tid} input[name=when_text]").count() == 1 and mp.locator(f"#e{tid} textarea[name=how_text]").count() == 1 and mp.locator(f"#na{tid}").count() == 1)
    mp.screenshot(path=out + "/30-1-dmm-edit-mobile.png", full_page=False)
    mp.fill(f"#e{tid} input[name=responsible]", NEW_WHO)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{tid} button.btn-g"); presses += 1
    mp.wait_for_load_state("networkidle")
    row = mp.locator(f"tr#t{tid}")
    ok("حُفظ بضغطتين: الصف يعرض المسؤول الجديد و«عدّله …» و«الأصل»", presses == 2 and row.locator("td[data-f=responsible]").inner_text().startswith(NEW_WHO) and "عدّله منسق سلامة فرع الدمام" in row.inner_text() and "الأصل: مدير المرافق والصيانة" in row.inner_text() and "حُفظ كلام" in mp.locator("body").inner_text())
    ok("المهمة نفسها لم تتغير", row.locator(".sp-task").inner_text().split("\n")[0].strip() == first_task)
    mp.screenshot(path=out + "/30-1-dmm-saved-mobile.png", full_page=False)
    # «لا ينطبق هنا» على أول مهمة تشغيلية
    op = mp.locator("tr[data-task]").nth(6); oid = op.get_attribute("data-task")
    mp.click(f"button.sp-edit[data-for='e{oid}']"); mp.check(f"#na{oid}")
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{oid} button.btn-g")
    mp.wait_for_load_state("networkidle")
    ok("«لا ينطبق هنا»: الصف موسوم وباهت والعدّاد في الرأس", mp.locator(f"tr#t{oid}").get_attribute("data-na") == "1" and "لا ينطبق هنا" in mp.locator(f"tr#t{oid} .badge").inner_text() and "2 معدّلة في هذا المكان" in mp.locator("body").inner_text())

    # ٣ قبو الملز كما كان، والوثيقة لم تُمس، ومسؤول السلامة يرى السجل
    ap.goto(f"{BASE}/app/places/{mlz_id}/safety-plan", wait_until="networkidle")
    mrows = ap.locator("tr[data-task]")
    ok("قبو الملز كما كان: المسؤول الأصلي ولا صف معدَّل", mrows.nth(0).locator("td[data-f=responsible]").inner_text().strip() == "مدير المرافق والصيانة" and ap.locator("tr[data-task][data-edited='1']").count() == 0 and NEW_WHO not in ap.locator("body").inner_text())
    ok("ملف safety-plan.html لم يتغير بصمةً", sha(DOC) == doc_before)
    ap.goto(f"{BASE}/app/places/{dmm_id}/safety-plan", wait_until="networkidle")
    t = ap.locator("body").inner_text()
    logs = [ap.locator("[data-log]").nth(i).inner_text() for i in range(ap.locator("[data-log]").count())]
    ok("مسؤول السلامة يرى تعديل الدمام وسجل التعديلات باسم المنسق وخانة «المسؤول»", NEW_WHO in t and "سجل التعديلات" in t and len(logs) >= 2 and any(("منسق سلامة فرع الدمام" in l and "المسؤول:" in l and NEW_WHO in l) for l in logs) and any(("لا ينطبق هنا:" in l) for l in logs), str(len(logs)))
    ap.goto(f"{BASE}/app/places/{dmm_id}/file", wait_until="networkidle")
    ok("ملف قبو الدمام: الزر يعدّ «2 معدّلة هنا»", "2 معدّلة هنا" in ap.locator("#pfPlanSafety").inner_text())
    ap.screenshot(path=out + "/30-1-dmm-log.png", full_page=True)

    # ٤ موظف الدمام يقرأ بلا زر؛ منسق الملز 403
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g301.emp", pw)
    r = ep.goto(f"{BASE}/app/places/{dmm_id}/safety-plan", wait_until="networkidle")
    ok("موظف الدمام: يقرأ الكلام الجديد بلا زر «تعديل»", r is not None and r.status == 200 and NEW_WHO in ep.locator("body").inner_text() and ep.locator("button.sp-edit").count() == 0)
    c = b.new_context(viewport={"width": 1200, "height": 900}); cp = c.new_page()
    login(cp, "g301.mlzc", pw)
    r = cp.goto(f"{BASE}/app/places/{dmm_id}/safety-plan", wait_until="domcontentloaded")
    ok("منسق إدارة في الملز: شاشة قبو الدمام 403", r is not None and r.status == 403, str(r.status if r else None))
    r = cp.goto(f"{BASE}/app/places/{mlz_id}/safety-plan", wait_until="domcontentloaded")
    ok("منسق إدارة في الملز (مكانه المكاتب): يقرأ قبو الملز بلا زر «تعديل»", r is not None and r.status == 200 and cp.locator("button.sp-edit").count() == 0, str(r.status if r else None))
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
