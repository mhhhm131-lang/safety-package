# -*- coding: utf-8 -*-
"""بوابة ٣١-٢ «الموعد في ما ينتظرك» (قرار ٩٠) — على المحلي بـWebKit، بحسابات seed-31-1.php:
منسق سلامة فرع الدمام يضع موعداً بعد يومين على المادة ٨ من الشاشة؛ مدير/منسق الفرع يجد في «ما ينتظرك» بطاقة «مقارنة … مع اللائحة: 1 مادة يحين موعدها»
تفتح الشاشة على المادة؛ موظف الدمام لا بطاقة له لكن عنده زر «المقارنة مع اللائحة» في «أريد أن»؛ «مستوفى» يُخفي البطاقة.
الاستعمال: webkit-31-2.py <كلمة حسابات البوابة> <مبنى الدمام id> <مجلد الصور>"""
import sys, json, re, datetime
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, dmm_b, out = sys.argv[1:4]
DUE = (datetime.date.today() + datetime.timedelta(days=2)).isoformat()
checks = []
def ok(name, cond, extra=""):
    checks.append((name, bool(cond), extra)); print(("✓ " if cond else "✗ ") + name + ((" — " + extra) if extra else ""), flush=True)

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

with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ المنسق على الجوال: لا بطاقة قبل الموعد ← يضع موعداً بعد يومين على المادة ٨
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e))); mp.on("dialog", lambda d: d.accept())
    login(mp, "g311.coord", pw)
    mp.goto(f"{BASE}/app", wait_until="networkidle"); mp.click("#navInbox"); mp.wait_for_load_state("networkidle")
    ok("قبل الموعد: لا بطاقة مقارنة في «ما ينتظرك»", mp.locator("#inboxList [data-task^='compliance:']").count() == 0)
    mp.goto(f"{BASE}/app/compliance", wait_until="networkidle")
    r8 = mp.locator("#a8 .cp-row"); rid = r8.get_attribute("id")[1:]
    mp.click(f"button.cp-edit[data-for='e{rid}']")
    mp.locator(f"label[for='st{rid}in_progress']").click(force=True)
    mp.fill(f"#e{rid} input[name=holder_name]", "مدير فرع الدمام (بوابة ٣١-٢)"); mp.fill(f"#e{rid} input[name=due_date]", DUE)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{rid} button.btn-g")
    mp.wait_for_load_state("networkidle")
    ok("المادة ٨ قيد الإعداد بموعد بعد يومين", mp.locator(f"#r{rid}").get_attribute("data-status") == "in_progress" and DUE in mp.locator(f"#r{rid}").inner_text())

    # ٢ البطاقة في «ما ينتظرك» عند المنسق، وتفتح الشاشة على المادة
    mp.goto(f"{BASE}/app", wait_until="networkidle"); mp.click("#navInbox"); mp.wait_for_load_state("networkidle")
    c = mp.locator(f"#inboxList [data-task='compliance:{dmm_b}']")
    if c.count():  # المجموعات مطويّة (قرار ٢٥-٣-ب): تُفتح مجموعة البطاقة
        btn = c.first.locator("xpath=ancestor::section[1]").locator(":scope > button.grp-h")
        if btn.count() and btn.get_attribute("aria-expanded") == "false":
            btn.click(); mp.wait_for_timeout(600)
    t = c.first.inner_text() if c.count() else ""
    card = c.first.locator(f"a[data-target$='#r{rid}']") if c.count() else mp.locator("a[href='#none']")  # زر البطاقة يمرّ بـ inbox.open ويحمل هدفه في data-target
    ok("بطاقة «مقارنة … مع اللائحة: 1 مادة يحين موعدها — المادة 8» برابط يفتح المادة", c.count() == 1 and "مع اللائحة: 1 مادة يحين موعدها" in t and "المادة 8" in t and card.count() >= 1, t[:120])
    mp.screenshot(path=out + "/31-2-inbox-mobile.png", full_page=False)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        card.first.click()
    mp.wait_for_load_state("networkidle")
    ok("الضغطة تفتح شاشة المقارنة على المادة", f"/app/compliance" in mp.url and mp.url.endswith(f"#r{rid}") and mp.locator(f"#r{rid}").count() == 1, mp.url)

    # ٣ «أريد أن»: زر «المقارنة مع اللائحة» عند الموظف، ولا بطاقة له
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g311.emp", pw)
    ep.goto(f"{BASE}/app", wait_until="networkidle")
    btn = ep.locator("a[data-intent='compliance']")
    ok("موظف الدمام: زر «المقارنة مع اللائحة» في «أريد أن» ولا بطاقة في «ما ينتظرك»", btn.count() == 1 and "المقارنة مع اللائحة" in btn.inner_text() and ep.locator("#inboxList [data-task^='compliance:']").count() == 0)
    with ep.expect_navigation(wait_until="domcontentloaded"):
        btn.click()
    ep.wait_for_load_state("networkidle")
    ok("الزر يفتح مقارنة مبناه بلا تعديل", "/app/compliance" in ep.url and ep.locator(".cp-art[data-article]").count() == 16 and ep.locator("button.cp-edit").count() == 0)
    ep.screenshot(path=out + "/31-2-intent-mobile.png", full_page=False)

    # ٤ «مستوفى» يُخفي البطاقة
    mp.goto(f"{BASE}/app/compliance", wait_until="networkidle")
    mp.click(f"button.cp-edit[data-for='e{rid}']"); mp.locator(f"label[for='st{rid}met']").click(force=True)
    with mp.expect_navigation(wait_until="domcontentloaded"):
        mp.click(f"#e{rid} button.btn-g")
    mp.wait_for_load_state("networkidle")
    mp.goto(f"{BASE}/app", wait_until="networkidle"); mp.click("#navInbox"); mp.wait_for_load_state("networkidle")
    ok("«مستوفى» يُخفي البطاقة", mp.locator(f"#inboxList [data-task='compliance:{dmm_b}']").count() == 0)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
