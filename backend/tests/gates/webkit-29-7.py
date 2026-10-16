# -*- coding: utf-8 -*-
"""بوابة ٢٩-٧ «التبويب والقصة الكاملة» (قرار ٨٨) — على المحلي بـWebKit بالأدوار: مسؤول السلامة من تبويب «الطبقة الثانية» يفتح جولة؛
المدير على الجوال يعبّئ ويعتمد؛ خمسة موظفين يجيبون؛ الإدارة العليا ترى الأرقام بنصوصها؛ المدير بلا نصوص؛ الموظف 403؛ مسؤول السلامة يقفل؛
والقائمة العامة للنماذج بلا بطاقة الإدارة ولا الإقرارات.
الاستعمال: webkit-29-7.py <مسؤول السلامة> <كلمته> <مدير> <كلمته> <الإدارة العليا> <كلمتها> <رقم الوحدة> <مجلد الصور> — الموظفون num1..num5 بكلمة 1234 (prep-29-6.php)."""
import sys, json, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
adm_user, adm_pass, mgr_user, mgr_pass, top_user, top_pass, unit_id, out = sys.argv[1:9]
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

    # ١ التبويب
    ap.goto(f"{BASE}/app/forms", wait_until="networkidle")
    t = ap.locator("body").inner_text()
    ok("قائمة النماذج: تبويبان، وبلا بطاقة الإدارة ولا الإقرارات", ap.locator("[data-forms-tabs] [data-tab=layer2]").count() == 1 and "إقرارك السري" not in t and ap.locator("tr[data-form] a", has_text="بطاقة الإدارة").count() == 0)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-forms-tabs] [data-tab=layer2]")
    ap.wait_for_load_state("networkidle")
    ok("تبويب «الطبقة الثانية»: البطاقة بزر «بنود البطاقة» وفتح جولة والجولات", ap.locator("[data-card]").count() == 1 and "بنود البطاقة" in ap.locator("body").inner_text() and ap.locator("[data-open-round]").count() == 1)
    ap.screenshot(path=out + "/29-7-layer2.png", full_page=False)
    ap.select_option("[data-open-round] select[name=unit_id]", unit_id)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-open-round] button")
    ap.wait_for_load_state("networkidle")
    m = re.search(r"/app/forms/rounds/(\d+)$", ap.url); round_id = m.group(1) if m else "0"
    ok("فُتحت الجولة", m is not None, ap.url)

    # ٢ المدير
    mctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = mctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e))); pg.on("dialog", lambda d: d.accept())
    login(pg, mgr_user, mgr_pass)
    pg.goto(f"{BASE}/app/forms/19/fill", wait_until="networkidle")
    claims = pg.locator(".claim")
    for i in range(claims.count()):
        claims.nth(i).locator("label.claim-btn").nth(0 if i < 7 else 1).click()
        if i < 7:
            claims.nth(i).locator("textarea").fill(f"دليل {i + 1}")
    pg.wait_for_timeout(1500)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-approve]")
    pg.wait_for_load_state("networkidle")
    ok("المدير عبّأ واعتمد ← الإقرارات جارية", pg.locator("[data-round-state=ack]").count() >= 1)
    ack_id = pg.locator("[data-ack]").get_attribute("data-ack")

    # ٣ الموظفون الخمسة
    for i, (goals, role) in enumerate(PLAN, start=1):
        ctx = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = ctx.new_page(); ep.on("pageerror", lambda e: errors.append(str(e)))
        login(ep, f"num{i}", "1234")
        ep.goto(f"{BASE}/app", wait_until="networkidle"); ep.click("#navInbox"); ep.wait_for_load_state("networkidle")
        if i == 1:
            ok("الموظف: «ما ينتظرك» فيه إقراره السري", "إقرارك السري" in ep.content())
        ep.goto(f"{BASE}/app/forms/{ack_id}/fill", wait_until="networkidle")
        ladders = ep.locator(".ladder")
        answer_ladder(ladders.nth(0), goals); answer_ladder(ladders.nth(1), role)
        for j in range(2, ladders.count()):
            answer_ladder(ladders.nth(j), "na")
        with ep.expect_navigation(wait_until="domcontentloaded"):
            ep.click('#fillForm button.btn-g')
        ep.wait_for_load_state("networkidle")
        assert ep.url.endswith("/submitted"), f"موظف {i} لم يُقبل: {ep.url}"
        if i == 5:
            r = ep.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="domcontentloaded")
            ok("الموظف لا يرى الأرقام (403)", r is not None and r.status == 403, str(r.status if r else None))
        ctx.close()

    # ٤ الإدارة العليا ترى الأرقام بنصوصها
    tctx = b.new_context(viewport={"width": 1200, "height": 900}); tp = tctx.new_page(); tp.on("pageerror", lambda e: errors.append(str(e)))
    login(tp, top_user, top_pass)
    tp.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="networkidle")
    tb = tp.locator("body").inner_text()
    ok("الإدارة العليا: الأرقام بالمستوى وأكبر فجوة ونصوص الموظفين", tp.locator("[data-level]").count() == 1 and tp.locator("[data-top-gap=goals]").count() == 1 and "لا وقت للتطبيق" in tb)
    tp.screenshot(path=out + "/29-7-numbers-top.png", full_page=True)
    r = tp.goto(f"{BASE}/app/forms/rounds", wait_until="domcontentloaded")
    ok("الإدارة العليا تفتح تبويب الطبقة الثانية وترى الجولة بزر الأرقام", r is not None and r.status == 200 and tp.locator(f"tr[data-round='{round_id}'] a", has_text="الأرقام").count() == 1, str(r.status if r else None))

    # ٥ المدير بلا نصوص
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}/numbers", wait_until="networkidle")
    mb = pg.locator("body").inner_text()
    ok("المدير: النسب بلا نصوص الموظفين", pg.locator("[data-level]").count() == 1 and "لا وقت للتطبيق" not in mb and "بلا نصوص الموظفين" in mb)

    # ٦ مسؤول السلامة يقفل
    ap.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-close]")
    ap.wait_for_load_state("networkidle")
    ok("الإقفال ← الأرقام بحالة «مقفلة»", ap.locator("[data-round-state=closed]").count() >= 1 and ap.locator("[data-level]").count() == 1)
    ap.goto(f"{BASE}/app/forms/rounds", wait_until="networkidle")
    ok("القائمة: الجولة مقفلة بزر الأرقام", ap.locator(f"tr[data-round='{round_id}'] [data-round-state=closed]").count() == 1 and ap.locator(f"tr[data-round='{round_id}'] a", has_text="الأرقام").count() == 1)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
