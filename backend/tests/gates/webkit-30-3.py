# -*- coding: utf-8 -*-
"""بوابة ٣٠-٣ «كلام المكان — بطاقات الأدوار» (قرار ٨٩) — على المحلي بـWebKit:
مسؤول السلامة من «الأدوار والبطاقات» يفتح «بطاقات مبناي» فيجد ٢١ بطاقة الملز من الملفات؛
منسق سلامة فرع الدمام على الجوال يفتح بطاقات الدمام ويسمّي شاغل بطاقة المسعف وهاتفه ويزيد مهمة استجابة بضغطتين؛
شاشة خطة استجابة قبو الدمام تعرض الشاغل والهاتف تحت بطاقة ٩؛ حالة طبية تُفعَّل في قبو الدمام فتعرض القائمة الحية الشاغل وهاتفه؛
بطاقات الملز كما هي؛ ملفات البطاقات لم تتغير بصمةً؛ موظف الدمام يقرأ بلا زر؛ منسق إدارة في الملز بلا زر.
الاستعمال: webkit-30-3.py <كلمة حسابات البوابة> <مبنى الدمام id> <قبو الدمام id> <مجلد الصور> — الحسابات من seed-30-3.php."""
import sys, json, hashlib, os, re, glob
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, dmm_b, dmm_place, out = sys.argv[1:5]
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
FILES = sorted(glob.glob(os.path.join(ROOT, "role-cards", "*", "role-*.html")))
HOLDER = "سعد المسعف — الدمام (بوابة ٣٠-٣)"
PHONE = "0551234567"
TASK = "نقل المصاب إلى مستشفى الدمام المركزي (لا عيادة في الفرع)"
checks = []
def ok(name, cond, extra=""):
    checks.append((name, bool(cond), extra)); print(("✓ " if cond else "✗ ") + name + ((" — " + extra) if extra else ""), flush=True)
def files_hash():
    h = hashlib.sha1()
    for f in FILES:
        with open(f, "rb") as fh: h.update(hashlib.sha1(fh.read()).hexdigest().encode())
    return h.hexdigest()

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

h_before = files_hash()
with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ مسؤول السلامة: من «الأدوار والبطاقات» إلى بطاقات مبناه (الملز)
    a = b.new_context(viewport={"width": 1200, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e))); ap.on("dialog", lambda d: d.accept())
    login(ap, "g303.salama", pw)
    ap.goto(f"{BASE}/app/roles", wait_until="networkidle")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("a[href$='/app/role-cards']")
    ap.wait_for_load_state("networkidle")
    cards = ap.locator(".rc[data-card]")
    ok("«بطاقات مبناي»: ٢١ بطاقة الملز من الملفات بأسمائها ومهامها الثلاث", len(FILES) == 21 and cards.count() == 21 and "المسعف" in ap.locator("body").inner_text() and ap.locator(".rc[data-card='9'] ul li").count() >= 8 and "الملز" in ap.locator("h1").inner_text())
    ap.screenshot(path=out + "/30-3-malaz-cards.png", full_page=True)

    # ٢ منسق سلامة فرع الدمام على الجوال: بطاقات الدمام ← تعديل المسعف ← حفظ
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e))); mp.on("dialog", lambda d: d.accept())
    login(mp, "g303.coord", pw)
    mp.goto(f"{BASE}/app/role-cards", wait_until="networkidle")
    dmm_title = mp.locator("h1").inner_text()
    ok("منسق الفرع يفتح بطاقات الدمام (مبنى جلسته) بأزرار التعديل", "الملز" not in dmm_title and mp.locator(".rc[data-card]").count() == 21 and mp.locator(".rc[data-card='9'] button.rc-edit").count() == 1, dmm_title)
    c9 = mp.locator(".rc[data-card='9']"); cid = c9.get_attribute("id")[1:]
    presses = 0
    mp.click(f".rc[data-card='9'] button.rc-edit"); presses += 1
    ok("ضغطة «تعديل» تفتح الاسم والوصف والشاغل والهاتف والمهام الثلاث و«لا ينطبق هنا»", mp.locator(f"#e{cid}").is_visible() and mp.locator(f"#e{cid} input[name=holder_name]").count() == 1 and mp.locator(f"#e{cid} input[name=holder_phone]").count() == 1 and mp.locator(f"#e{cid} textarea[name=tasks_response]").count() == 1 and mp.locator(f"#na{cid}").count() == 1)
    mp.screenshot(path=out + "/30-3-dmm-edit-mobile.png", full_page=False)
    mp.fill(f"#e{cid} input[name=holder_name]", HOLDER); mp.fill(f"#e{cid} input[name=holder_phone]", PHONE)
    tasks = mp.locator(f"#e{cid} textarea[name=tasks_response]").input_value().strip()
    mp.fill(f"#e{cid} textarea[name=tasks_response]", tasks + "\n" + TASK)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{cid} button.btn-g"); presses += 1
    mp.wait_for_load_state("networkidle")
    c9 = mp.locator(f"#c{cid}")
    ok("حُفظ بضغطتين: الشاغل والهاتف رابطَ اتصال والمهمة الجديدة و«عدّله …» و«الأصل»", presses == 2 and HOLDER in c9.inner_text() and c9.locator(f"a[href='tel:{PHONE}']").count() == 1 and TASK in c9.inner_text() and "عدّله منسق سلامة فرع الدمام" in c9.inner_text() and "الأصل في الملف" in c9.inner_text())
    ok("الرقم والفئة والملف لم تتغير", c9.get_attribute("data-card") == "9" and c9.locator("a[href='/role-cards/response-team/role-09.html']").count() == 1)
    mp.screenshot(path=out + "/30-3-dmm-saved-mobile.png", full_page=False)

    # ٣ خطوات الدمام تشير إلى بطاقة المبنى: شاشة الخطة ثم القائمة الحية
    mp.goto(f"{BASE}/app/places/{dmm_place}/response-plan", wait_until="networkidle")
    ok("خطة استجابة قبو الدمام: الشاغل وهاتفه تحت بطاقة ٩ في خطواتها", HOLDER in mp.locator("body").inner_text() and mp.locator(f"a[href='tel:{PHONE}']").count() >= 1)
    ap.goto(f"{BASE}/app/emergency/buildings/{dmm_b}/control", wait_until="networkidle")
    ap.select_option("#triggerForm select[name=place_id]", dmm_place)
    ap.select_option("#triggerForm select[name=incident_type]", "medical")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("#triggerForm button[type=submit]")
    ap.wait_for_load_state("networkidle")
    mm = re.search(r"/incidents/(\d+)", ap.url)
    if mm and "/live" not in ap.url:
        ap.goto(f"{BASE}/app/emergency/incidents/{mm.group(1)}/live", wait_until="networkidle")
    ok("حالة طبية في قبو الدمام: القائمة الحية تعرض شاغل بطاقة المسعف وهاتفه", mm is not None and HOLDER in ap.locator("body").inner_text() and ap.locator(f"a[href='tel:{PHONE}']").count() >= 1, ap.url)
    ap.screenshot(path=out + "/30-3-dmm-live.png", full_page=True)

    # ٤ الملز كما هو، الملفات لم تُمس، مسؤول السلامة يرى تعديل الدمام والسجل
    ap.goto(f"{BASE}/app/role-cards", wait_until="networkidle")
    ok("بطاقات الملز كما هي: لا شاغل على المسعف ولا صف معدَّل", HOLDER not in ap.locator("body").inner_text() and ap.locator(".rc[data-edited='1']").count() == 0 and "الملز" in ap.locator("h1").inner_text())
    ok("ملفات role-cards/ الـ٢١ لم تتغير بصمةً", files_hash() == h_before)
    ap.goto(f"{BASE}/app/role-cards?b={dmm_b}", wait_until="networkidle")
    logs = [ap.locator("[data-log]").nth(i).inner_text() for i in range(ap.locator("[data-log]").count())]
    ok("مسؤول السلامة يبدّل إلى الدمام فيرى التعديل وسجله باسم المنسق وخانة «الهاتف»", ap.locator("h1").inner_text() == dmm_title and HOLDER in ap.locator("body").inner_text() and len(logs) >= 1 and any(("منسق سلامة فرع الدمام" in l and "الهاتف" in l) for l in logs), str(len(logs)))
    ap.screenshot(path=out + "/30-3-dmm-log.png", full_page=True)

    # ٥ موظف الدمام يقرأ بلا زر؛ منسق إدارة في الملز يقرأ الملز بلا زر
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g303.emp", pw)
    r = ep.goto(f"{BASE}/app/role-cards", wait_until="networkidle")
    ok("موظف الدمام: يقرأ بطاقات مبناه بالشاغل الجديد بلا زر «تعديل»", r is not None and r.status == 200 and HOLDER in ep.locator("body").inner_text() and ep.locator("button.rc-edit").count() == 0)
    c = b.new_context(viewport={"width": 1200, "height": 900}); cp = c.new_page()
    login(cp, "g303.mlzc", pw)
    r = cp.goto(f"{BASE}/app/role-cards?b={dmm_b}", wait_until="networkidle")
    ok("منسق إدارة في الملز: يبقى على الملز (لا يفتح الدمام) وبلا زر", r is not None and r.status == 200 and "الملز" in cp.locator("h1").inner_text() and HOLDER not in cp.locator("body").inner_text() and cp.locator("button.rc-edit").count() == 0)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
