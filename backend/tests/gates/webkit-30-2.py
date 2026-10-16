# -*- coding: utf-8 -*-
"""بوابة ٣٠-٢ «كلام المكان — خطة الاستجابة» (قرار ٨٩) — على المحلي بـWebKit:
مسؤول السلامة يفتح ملف قبو الملز فيجد زر «خطة الاستجابة» يفتح شاشة النظام بصفوف الوثيقة (المسارات والخطوات كما في الورقة)؛
منسق سلامة فرع الدمام على الجوال يعدّل «من» والهاتف في خطوة الطبيب بضغطتين ويسم خطوة «لا ينطبق هنا»؛
مسؤول السلامة يفعّل حالة طبية في قبو الدمام من شاشة التحكم فتعرض القائمة الحية الكلام الجديد والهاتف؛
قبو الملز كما كان؛ الوثيقة لم تتغير بصمةً؛ موظف الدمام يقرأ بلا زر؛ منسق الملز 403.
الاستعمال: webkit-30-2.py <كلمة حسابات البوابة> <قبو الدمام id> <قبو الملز id> <مبنى الدمام id> <مجلد الصور> — الحسابات من seed-30-2.php."""
import sys, json, hashlib, os, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, dmm_id, mlz_id, dmm_b, out = sys.argv[1:6]
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
DOC = os.path.join(ROOT, "HZ-01-basement", "response-plan.html")
NEW_WHO = "الإسعاف ٩٩٧ من خارج المعهد (بوابة ٣٠-٢)"
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

def medical_rows(page):
    return page.locator("h2:has-text('المسار الطبي') + div tr[data-step]")

doc_before = sha(DOC)
with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ مسؤول السلامة: ملف قبو الملز ← الزر يفتح شاشة النظام
    a = b.new_context(viewport={"width": 1200, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e))); ap.on("dialog", lambda d: d.accept())
    login(ap, "g302.salama", pw)
    ap.goto(f"{BASE}/app/places/{mlz_id}/file", wait_until="networkidle")
    href = ap.locator("#pfPlanResponse").get_attribute("href") or ""
    ok("ملف قبو الملز: زر «خطة الاستجابة» يفتح شاشة النظام ويعدّ الخطوات", href.endswith(f"/app/places/{mlz_id}/response-plan") and "16 خطوة" in ap.locator("#pfPlanResponse").inner_text(), href)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("#pfPlanResponse")
    ap.wait_for_load_state("networkidle")
    t = ap.locator("body").inner_text()
    ok("شاشة الخطة: المسارات بخطواتها ورابط الوثيقة الأصلية", medical_rows(ap).count() == 3 and "الكشف والبلاغ" in t and "المسار الطبي" in t and ap.locator("a[href='/HZ-01-basement/response-plan.html']").count() == 1 and ap.locator("tr[data-step]").count() >= 16)
    mlz_step3 = medical_rows(ap).nth(2)
    step_title = mlz_step3.locator(".rp-title").inner_text().split("\n")[0].strip()
    mlz_who = mlz_step3.locator("td[data-f=who_text]").inner_text().strip()
    ok("خطوة الطبيب في الملز كما في الورقة وبطاقتها", step_title != "" and mlz_who != "" and mlz_step3.locator("td[data-label=البطاقة] a").count() >= 1, f"{step_title} / {mlz_who}")
    ap.screenshot(path=out + "/30-2-malaz-plan.png", full_page=True)

    # ٢ منسق سلامة فرع الدمام على الجوال: «من» والهاتف ← حفظ؛ ثم «لا ينطبق هنا» على خطوة في مسار القبو
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e))); mp.on("dialog", lambda d: d.accept())
    login(mp, "g302.coord", pw)
    mp.goto(f"{BASE}/app/places/{dmm_id}/response-plan", wait_until="networkidle")
    drow = medical_rows(mp).nth(2)
    ok("قبو الدمام: خطوة الطبيب نفسها بالكلام نفسه", drow.locator(".rp-title").inner_text().split("\n")[0].strip() == step_title and drow.locator("td[data-f=who_text]").inner_text().strip() == mlz_who)
    sid = drow.get_attribute("data-step")
    ok("للمنسق زر «تعديل» والنموذج مطوي، وبلا خانة للعنوان أو «متى»", mp.locator(f"button.rp-edit[data-for='e{sid}']").count() == 1 and mp.locator(f"#e{sid}").is_hidden() and mp.locator("input[name=when_text], input[name=title]").count() == 0)
    presses = 0
    mp.click(f"button.rp-edit[data-for='e{sid}']"); presses += 1
    ok("ضغطة «تعديل» تفتح من وأين وكيف والهاتف و«لا ينطبق هنا»", mp.locator(f"#e{sid}").is_visible() and mp.locator(f"#e{sid} input[name=who_text]").count() == 1 and mp.locator(f"#e{sid} input[name=phone_text]").count() == 1 and mp.locator(f"#e{sid} textarea[name=how_text]").count() == 1 and mp.locator(f"#na{sid}").count() == 1)
    mp.screenshot(path=out + "/30-2-dmm-edit-mobile.png", full_page=False)
    mp.fill(f"#e{sid} input[name=who_text]", NEW_WHO); mp.fill(f"#e{sid} input[name=phone_text]", "997")
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{sid} button.btn-g"); presses += 1
    mp.wait_for_load_state("networkidle")
    row = mp.locator(f"tr#s{sid}")
    ok("حُفظ بضغطتين: «من» الجديد والهاتف رابطَ اتصال و«عدّله …» و«الأصل» وبلا بطاقة", presses == 2 and row.locator("td[data-f=who_text]").inner_text().startswith(NEW_WHO) and row.locator("a[href='tel:997']").count() == 1 and "عدّله منسق سلامة فرع الدمام" in row.inner_text() and ("الأصل: " + mlz_who) in row.inner_text() and "بلا بطاقة" in row.locator("td[data-label=البطاقة]").inner_text())
    ok("العنوان و«متى» لم يتغيرا", row.locator(".rp-title").inner_text().split("\n")[0].strip() == step_title)
    mp.screenshot(path=out + "/30-2-dmm-saved-mobile.png", full_page=False)
    fire_rows = mp.locator("h2:has-text('مسار') + div tr[data-step]")
    last = fire_rows.nth(fire_rows.count() - 1); fid = last.get_attribute("data-step")
    mp.click(f"button.rp-edit[data-for='e{fid}']"); mp.check(f"#na{fid}")
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{fid} button.btn-g")
    mp.wait_for_load_state("networkidle")
    ok("«لا ينطبق هنا» على آخر خطوة في مسار القبو: موسومة والعدّاد «2 معدّلة»", mp.locator(f"tr#s{fid}").get_attribute("data-na") == "1" and "2 معدّلة في هذا المكان" in mp.locator("body").inner_text())

    # ٣ مسؤول السلامة يفعّل حالة طبية في قبو الدمام ← القائمة الحية بالكلام الجديد والهاتف
    ap.goto(f"{BASE}/app/emergency/buildings/{dmm_b}/control", wait_until="networkidle")
    ap.select_option("#triggerForm select[name=place_id]", dmm_id)
    ap.select_option("#triggerForm select[name=incident_type]", "medical")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("#triggerForm button[type=submit]")
    ap.wait_for_load_state("networkidle")
    mm = re.search(r"/incidents/(\d+)", ap.url)
    if mm and "/live" not in ap.url:
        ap.goto(f"{BASE}/app/emergency/incidents/{mm.group(1)}/live", wait_until="networkidle")
    lt = ap.locator("body").inner_text()
    ok("حالة طبية في قبو الدمام: القائمة الحية ٣ خطوات، فيها «من» الجديد والهاتف رابطاً", mm is not None and NEW_WHO in lt and ap.locator("a[href='tel:997']").count() >= 1 and "/ 3" in ap.locator("body").inner_html(), ap.url)
    ap.screenshot(path=out + "/30-2-dmm-live.png", full_page=True)

    # ٤ الملز كما كان، الوثيقة لم تُمس، السجل لمسؤول السلامة، وعدّاد ملف الدمام
    ap.goto(f"{BASE}/app/places/{mlz_id}/response-plan", wait_until="networkidle")
    ok("قبو الملز كما كان: «من» الأصلي ولا صف معدَّل", medical_rows(ap).nth(2).locator("td[data-f=who_text]").inner_text().strip() == mlz_who and ap.locator("tr[data-step][data-edited='1']").count() == 0 and NEW_WHO not in ap.locator("body").inner_text())
    ok("ملف response-plan.html لم يتغير بصمةً", sha(DOC) == doc_before)
    ap.goto(f"{BASE}/app/places/{dmm_id}/response-plan", wait_until="networkidle")
    logs = [ap.locator("[data-log]").nth(i).inner_text() for i in range(ap.locator("[data-log]").count())]
    ok("مسؤول السلامة يرى تعديل الدمام وسجل التعديلات باسم المنسق وخانتي «من» و«الهاتف»", NEW_WHO in ap.locator("body").inner_text() and len(logs) >= 2 and any(("منسق سلامة فرع الدمام" in l and "من:" in l and "الهاتف:" in l) for l in logs) and any("لا ينطبق هنا:" in l for l in logs), str(len(logs)))
    ap.goto(f"{BASE}/app/places/{dmm_id}/file", wait_until="networkidle")
    ok("ملف قبو الدمام: الزر يعدّ «2 معدّلة هنا»", "2 معدّلة هنا" in ap.locator("#pfPlanResponse").inner_text())
    ap.screenshot(path=out + "/30-2-dmm-log.png", full_page=True)

    # ٥ موظف الدمام يقرأ بلا زر؛ منسق الملز 403
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g302.emp", pw)
    r = ep.goto(f"{BASE}/app/places/{dmm_id}/response-plan", wait_until="networkidle")
    ok("موظف الدمام: يقرأ الكلام الجديد بلا زر «تعديل»", r is not None and r.status == 200 and NEW_WHO in ep.locator("body").inner_text() and ep.locator("button.rp-edit").count() == 0)
    c = b.new_context(viewport={"width": 1200, "height": 900}); cp = c.new_page()
    login(cp, "g302.mlzc", pw)
    r = cp.goto(f"{BASE}/app/places/{dmm_id}/response-plan", wait_until="domcontentloaded")
    ok("منسق إدارة في الملز: شاشة قبو الدمام 403", r is not None and r.status == 403, str(r.status if r else None))
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
