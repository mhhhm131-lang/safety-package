# -*- coding: utf-8 -*-
"""بوابة ٢٩-٥ «توليد الإقرار السري» (قرار ٨٨) — على المحلي بـWebKit: مسؤول السلامة يفتح جولة؛ المدير على الجوال يعبّئ (نعم بدليل ووثيقة في
«موثّق» الأهداف، ولا في الباقي) ويعتمد فيولّد النظام الإقرار آلياً وتصير الحالة «الإقرارات جارية» بعدد المكلَّفين لا أسمائهم؛
الموظف على الجوال: «ما ينتظرك» فيه إقراره، صفحة الإقرار سرية وفيها ما قالته إدارته ودليلها ووثيقتها والعبارات الأربع و«لا ينطبق على عملي»،
«لا» على «أطبّقه» تجعل «ما الواقع» إلزامية ويرفضها الخادم بلا نص، ثم يُقبل ويصل «سُجّلت إجابتك سرياً»؛ مسؤول السلامة يرى العدد والنتائج محجوبة تحت خمسة؛ المدير لا يفتح النتائج.
الاستعمال: webkit-29-5.py <مسؤول السلامة> <كلمته> <مدير> <كلمته> <موظف> <كلمته> <اسم الموظف> <رقم الوحدة> <عدد المكلَّفين المتوقع> <ملف PDF> <مجلد الصور>
التهيئة قبلها: prep-29-5.php."""
import sys, json, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
adm_user, adm_pass, mgr_user, mgr_pass, emp_user, emp_pass, emp_name, unit_id, ack_total, pdf, out = sys.argv[1:12]
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

    # ١ مسؤول السلامة يفتح الجولة
    a = b.new_context(viewport={"width": 1100, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e)))
    login(ap, adm_user, adm_pass)
    ap.goto(f"{BASE}/app/forms/rounds", wait_until="networkidle")
    ap.select_option("[data-open-round] select[name=unit_id]", unit_id)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-open-round] button")
    ap.wait_for_load_state("networkidle")
    m = re.search(r"/app/forms/rounds/(\d+)$", ap.url)
    ok("فُتحت الجولة", m is not None, ap.url)
    round_id = m.group(1) if m else "0"

    # ٢ المدير يعبّئ ويعتمد
    mctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = mctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e))); pg.on("dialog", lambda d: d.accept())
    login(pg, mgr_user, mgr_pass)
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-fill]")
    pg.wait_for_load_state("networkidle")
    # «موثّق» المخاطر يقرؤه النظام من السجل: إن كان «نعم» وصل الموظفَ بند المخاطر أيضاً
    reg_yes = pg.locator("[data-claim-fact] .badge").first.inner_text().strip() == "نعم"
    expected_ladders = 1 + (1 if reg_yes else 0)
    claims = pg.locator(".claim")
    claims.nth(0).locator("label.claim-btn").nth(0).click()
    claims.nth(0).locator("textarea").fill("خطة الإدارة ٢٠٢٦ — معتمدة في ١٤/٩")
    claims.nth(0).locator("input[type=file]").set_input_files(pdf)
    for i in range(1, claims.count()):
        claims.nth(i).locator("label.claim-btn").nth(1).click()
    pg.wait_for_timeout(1500)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("المدير أرسل البطاقة", "/submitted" in pg.url, pg.url)
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-approve]")
    pg.wait_for_load_state("networkidle")
    ok("بعد الاعتماد: الحالة «الإقرارات جارية» آلياً", pg.locator("[data-round-state=ack]").count() >= 1)
    ok("السجل: أُرسل الإقرار السري آلياً", pg.locator("[data-log=acks_sent]").count() == 1 and "آلياً" in pg.locator("[data-log=acks_sent]").inner_text())
    ack_box = pg.locator("[data-ack]")
    ok("العدد لا الأسماء: أُرسل إلى ن، أجاب ٠", ack_box.count() == 1 and pg.locator("[data-ack-total]").get_attribute("data-ack-total") == ack_total and pg.locator("[data-ack-done]").get_attribute("data-ack-done") == "0" and emp_name not in pg.locator("body").inner_text(), f"{pg.locator('[data-ack-total]').inner_text() if ack_box.count() else '-'}/{ack_total}")
    ack_id = ack_box.get_attribute("data-ack") if ack_box.count() else "0"
    ok("المدير لا يرى زر النتائج للإقرار", ack_box.locator("a", has_text="النتائج").count() == 0)
    pg.screenshot(path=out + "/29-5-round-ack.png", full_page=True)
    r = pg.goto(f"{BASE}/app/forms/{ack_id}/results", wait_until="domcontentloaded")
    ok("المدير يطلب نتائج الإقرار ← 403", r is not None and r.status == 403, str(r.status if r else None))

    # ٣ الموظف على الجوال
    ectx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    ep = ectx.new_page(); ep.on("pageerror", lambda e: errors.append(str(e)))
    login(ep, emp_user, emp_pass)
    ep.goto(f"{BASE}/app", wait_until="networkidle")
    ep.click("#navInbox"); ep.wait_for_load_state("networkidle")
    ok("«ما ينتظرك» للموظف فيه «إقرارك السري»", "إقرارك السري" in ep.content())
    ep.goto(f"{BASE}/app/forms/{ack_id}/fill", wait_until="networkidle")
    body = ep.locator("body").inner_text()
    ok("صفحة الإقرار سرية: صندوق السرية و«لا يُنسب جوابك إليك»", ep.locator("[data-anonymous-box]").count() == 1 and "لا يُنسب جوابك إليك" in body)
    ok("رأس البطاقة: الإدارة ومديرها والجولة", ep.locator("[data-round-header]").count() == 1 and "الجولة" in ep.locator("[data-round-header]").inner_text())
    ladders = ep.locator(".ladder")
    ok("بنود الإقرار = ما قالت فيه الإدارة «نعم» (الأهداف، والمخاطر إن قال السجل نعم) — بند «لا» في كل مراحله لا يُعرض", ladders.count() == expected_ladders and "أهداف الإدارة" in body and ("المخاطر والفرص" in body) == reg_yes, f"{ladders.count()}/{expected_ladders}")
    cl = ladders.nth(0).locator("[data-ladder-claims]")
    ok("ما قالته الإدارة: موثّق نعم بالدليل والوثيقة، والباقي لا", "خطة الإدارة ٢٠٢٦" in cl.inner_text() and cl.locator("a", has_text="plan.pdf").count() == 1 and cl.locator(".badge.bg-secondary").count() == 3)
    ok("العبارات الأربع بالمتكلم و«لا ينطبق على عملي» في كل بند", ladders.nth(0).locator("[data-ladder-row]").count() == 4 and "أطبّقه فعلاً في عملي اليومي" in body and ep.locator(".ladder-na").count() == ladders.count())
    doc = ep.context.request.get(f"{BASE}/app/forms" + cl.locator("a", has_text="plan.pdf").get_attribute("href").split("/app/forms", 1)[1])
    ok("الموظف يفتح وثيقة إدارته", doc.status == 200 and doc.body()[:4] == b"%PDF", str(doc.status))
    ep.screenshot(path=out + "/29-5-ack-fill.png", full_page=True)
    for k in ("doc", "ann", "und"):
        ladders.nth(0).locator(f"[data-ladder-row={k}] label", has_text="نعم").click()
    ladders.nth(0).locator("[data-ladder-row=app] label", has_text="لا").click()
    for i in range(1, ladders.count()):
        ladders.nth(i).locator(".ladder-na").check()
    ok("«لا ينطبق على عملي» يطوي عبارات البند", ladders.count() == 1 or ladders.nth(1).locator(".ladder-rows").get_attribute("hidden") is not None)
    ok("«لا» على «أطبّقه» تجعل «ما الواقع» إلزامية في الشاشة", ladders.nth(0).locator(".ladder-note-req").get_attribute("hidden") is None and ladders.nth(0).locator("textarea").evaluate("e => e.required"))
    ladders.nth(0).locator("textarea").evaluate("e => e.required = false")  # لنصل إلى الخادم
    with ep.expect_navigation(wait_until="domcontentloaded"):
        ep.click('#fillForm button.btn-g')
    ep.wait_for_load_state("networkidle")
    ok("الخادم يرفض «لا» على «أطبّقه» بلا «ما الواقع»", "/fill" in ep.url and ep.locator(".text-danger", has_text="ما الواقع").count() >= 1)
    ladders = ep.locator(".ladder")
    ok("الإجابات باقية بعد الرفض (ومعها «لا ينطبق»)", ladders.nth(0).locator("[data-ladder-row=app] input[value=no]").is_checked() and (ladders.count() == 1 or ladders.nth(1).locator(".ladder-na").is_checked()))
    ladders.nth(0).locator("textarea").fill("الصلاحيات في الورقة غير التي نُمارسها")
    with ep.expect_navigation(wait_until="domcontentloaded"):
        ep.click('#fillForm button.btn-g')
    ep.wait_for_load_state("networkidle")
    ok("يُقبل ويصل «سُجّلت إجابتك سرياً» بلا رقم", ep.url.endswith("/submitted") and ep.locator("[data-anonymous-done]").count() == 1, ep.url)
    ep.screenshot(path=out + "/29-5-ack-done.png", full_page=False)

    # ٤ مسؤول السلامة بعد الإجابة
    ap.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    ok("صفحة الجولة: أجاب ١ (العدد لا الاسم)", ap.locator("[data-ack-done]").get_attribute("data-ack-done") == "1" and emp_name not in ap.locator("body").inner_text())
    ap.goto(f"{BASE}/app/forms/{ack_id}/results", wait_until="networkidle")
    ok("نتائج الإقرار محجوبة تحت خمسة مجيبين", ap.locator("[data-hidden-below-threshold]").count() == 1 and "الصلاحيات في الورقة" not in ap.locator("body").inner_text())
    ap.screenshot(path=out + "/29-5-results-hidden.png", full_page=False)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
