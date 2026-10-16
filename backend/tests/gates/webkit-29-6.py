# -*- coding: utf-8 -*-
"""بوابة ٢٩-٦ «شاشة الأرقام» (قرار ٨٨) — على المحلي بـWebKit: جولة تُفتح، المدير يعبّئ (الأهداف نعم في الأربع، الدور نعم في ثلاث ولا في مطبَّق،
الباقي لا) ويعتمد؛ أربعة موظفين يجيبون فالأرقام محجوبة؛ الخامس يجيب فتظهر: سطر الإدارة (المستوى من ٤، أكبر فجوة «مفهوم وغير مطبَّق»، أجاب ٥ من ن)،
الجدول مرتباً والأهداف أولاً بنسبها التراكمية ونصوص الموظفين لمسؤول السلامة؛ المدير يرى النسب بلا نصوص؛ الموظف لا يرى؛ ثم الإقفال يوقف الإقرار والأرقام تبقى.
الاستعمال: webkit-29-6.py <مسؤول السلامة> <كلمته> <مدير> <كلمته> <رقم الوحدة> <عدد المكلَّفين المتوقع> <مجلد الصور>  — الموظفون num1..num5 بكلمة 1234 (prep-29-6.php)."""
import sys, json, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
adm_user, adm_pass, mgr_user, mgr_pass, unit_id, ack_total, out = sys.argv[1:8]
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

# إجابات الموظفين الخمسة: الأهداف [doc, ann, und, app, note]، والدور كذلك أو "na"
PLAN = [
    (["yes", "yes", "yes", "yes", ""],                     ["yes", "yes", "no", "no", "لم أفهم الصلاحيات"]),
    (["yes", "yes", "yes", "no", "لا وقت للتطبيق"],        ["yes", "no", "no", "no", "لم يُعلن"]),
    (["yes", "yes", "yes", "no", "الصلاحيات في الورقة غير التي نُمارسها"], "na"),
    (["yes", "yes", "no", "no", "لا أفهمه"],               ["yes", "yes", "no", "no", "غامض"]),
    (["yes", "no", "no", "no", "لم يصلني إعلان"],          ["yes", "yes", "yes", "yes", ""]),
]

def answer_ladder(ladder, plan):
    if plan == "na":
        ladder.locator(".ladder-na").check(); return
    for k, v in zip(("doc", "ann", "und", "app"), plan[:4]):
        ladder.locator(f"[data-ladder-row={k}] label", has_text=("نعم" if v == "yes" else "لا")).click()
    if plan[4]:
        ladder.locator("textarea").fill(plan[4])

with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    a = b.new_context(viewport={"width": 1200, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e))); ap.on("dialog", lambda d: d.accept())
    login(ap, adm_user, adm_pass)
    ap.goto(f"{BASE}/app/forms/rounds", wait_until="networkidle")
    ap.select_option("[data-open-round] select[name=unit_id]", unit_id)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-open-round] button")
    ap.wait_for_load_state("networkidle")
    m = re.search(r"/app/forms/rounds/(\d+)$", ap.url); round_id = m.group(1) if m else "0"
    ok("فُتحت الجولة", m is not None, ap.url)

    # المدير: الأهداف نعم×٤، الدور نعم×٣ ولا في مطبَّق، الباقي لا
    mctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = mctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e))); pg.on("dialog", lambda d: d.accept())
    login(pg, mgr_user, mgr_pass)
    pg.goto(f"{BASE}/app/forms/19/fill", wait_until="networkidle")
    reg_yes = pg.locator("[data-claim-fact] .badge").first.inner_text().strip() == "نعم"  # «موثّق» المخاطر من السجل
    expected_ladders = 2 + (1 if reg_yes else 0)
    claims = pg.locator(".claim")
    yes_idx = {0, 1, 2, 3, 4, 5, 6}  # الأهداف ٠-٣ ثم الدور ٤-٦ (موثّق، معلن، مفهوم)
    for i in range(claims.count()):
        claims.nth(i).locator("label.claim-btn").nth(0 if i in yes_idx else 1).click()
        if i in yes_idx:
            claims.nth(i).locator("textarea").fill(f"دليل {i + 1}")
    pg.wait_for_timeout(1500)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("المدير أرسل البطاقة", "/submitted" in pg.url, pg.url)
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-approve]")
    pg.wait_for_load_state("networkidle")
    ok("اعتمد ← الإقرارات جارية", pg.locator("[data-round-state=ack]").count() >= 1)
    ack_id = pg.locator("[data-ack]").get_attribute("data-ack")

    # الموظفون
    for i, (goals, role) in enumerate(PLAN, start=1):
        ctx = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = ctx.new_page(); ep.on("pageerror", lambda e: errors.append(str(e)))
        login(ep, f"num{i}", "1234")
        ep.goto(f"{BASE}/app/forms/{ack_id}/fill", wait_until="networkidle")
        ladders = ep.locator(".ladder")
        if i == 1:
            ok("إقرار الموظف فيه الأهداف والدور (والمخاطر إن قال السجل نعم)", ladders.count() == expected_ladders and "دور كل موظف" in ep.locator("body").inner_text(), f"{ladders.count()}/{expected_ladders}")
        answer_ladder(ladders.nth(0), goals); answer_ladder(ladders.nth(1), role)
        for j in range(2, ladders.count()):
            answer_ladder(ladders.nth(j), "na")
        with ep.expect_navigation(wait_until="domcontentloaded"):
            ep.click('#fillForm button.btn-g')
        ep.wait_for_load_state("networkidle")
        assert ep.url.endswith("/submitted"), f"موظف {i} لم يُقبل: {ep.url}"
        if i == 4:
            ap.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="networkidle")
            ok("بعد أربعة مجيبين: الأرقام محجوبة", ap.locator("[data-numbers-hidden='4']").count() == 1 and ap.locator("[data-numbers-table]").count() == 0)
            ap.screenshot(path=out + "/29-6-hidden.png", full_page=False)
        if i == 5:
            r = ep.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="domcontentloaded")
            ok("الموظف لا يرى الأرقام (403)", r is not None and r.status == 403, str(r.status if r else None))
        ctx.close()

    # مسؤول السلامة: الأرقام
    ap.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    ok("صفحة الجولة: زر «الأرقام» وزر «أقفل الجولة»", ap.locator("[data-numbers]").count() == 1 and ap.locator("[data-close]").count() == 1)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-numbers]")
    ap.wait_for_load_state("networkidle")
    body = ap.locator("body").inner_text()
    ok("سطر الإدارة: المستوى من ٤ وأكبر فجوة «الأهداف — مفهوم وغير مطبَّق» وأجاب ٥ من ن", ap.locator("[data-level]").count() == 1 and ap.locator("[data-top-gap=goals]").count() == 1 and "مفهوم وغير مطبَّق" in ap.locator("[data-top-gap=goals]").inner_text() and f"أجاب 5 من {ack_total}" in body, ap.locator("[data-level]").get_attribute("data-level") if ap.locator("[data-level]").count() else "-")
    ok("المستوى ٢٫٤ من ٤ (متوسط ٢٫٦ و٢٫٢٥)", ap.locator("[data-level]").get_attribute("data-level") == "2.4", ap.locator("[data-level]").get_attribute("data-level") or "")
    rows = ap.locator("tr[data-item]")
    ok("الجدول سبعة صفوف والأهداف أولاً ثم الدور، وما قالت فيه الإدارة «لا» آخراً", rows.count() == 7 and rows.nth(0).get_attribute("data-item") == "goals" and rows.nth(1).get_attribute("data-item") == "role" and rows.nth(6).get_attribute("data-kind") == "none")
    ok("نسب الأهداف التراكمية ١٠٠/٨٠/٦٠/٢٠", "5 من 5 (100٪)" in ap.locator("[data-share='goals.doc']").inner_text() and "1 من 5 (20٪)" in ap.locator("[data-share='goals.app']").inner_text())
    ok("الدور على أربعة مجيبين و«لا ينطبق» واحد", "4 من 4 (100٪)" in ap.locator("[data-share='role.doc']").inner_text() and "لا ينطبق على عملي: 1" in rows.nth(1).inner_text())
    ok("نصوص الموظفين لمسؤول السلامة ووقائع النظام بجانب الرقم", "لا وقت للتطبيق" in body and "سجل الإدارة" in body and "بلاغات" in body)
    ok("لا بطاقات أبعاد", "النفسي" not in body and "المعرفي" not in body)
    ap.screenshot(path=out + "/29-6-numbers.png", full_page=True)

    # المدير: النسب بلا نصوص
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="networkidle")
    mb = pg.locator("body").inner_text()
    ok("المدير يرى نسب إدارته بلا نصوص الموظفين", pg.locator("[data-level]").count() == 1 and "لا وقت للتطبيق" not in mb and "بلا نصوص الموظفين" in mb)
    pg.screenshot(path=out + "/29-6-numbers-manager.png", full_page=True)

    # الإقفال
    ap.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-close]")
    ap.wait_for_load_state("networkidle")
    ok("الإقفال يصل صفحة الأرقام بحالة «مقفلة»", "/numbers" in ap.url and ap.locator("[data-round-state=closed]").count() >= 1 and ap.locator("[data-level]").count() == 1)
    ctx = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, locale="ar"); ep = ctx.new_page()
    login(ep, "anon11", "1234")
    r = ep.goto(f"{BASE}/app/forms/{ack_id}/fill", wait_until="domcontentloaded")
    ok("بعد الإقفال: من لم يجب لا يجيب (403)", r is not None and r.status == 403, str(r.status if r else None))
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
