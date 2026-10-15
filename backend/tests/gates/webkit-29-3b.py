# -*- coding: utf-8 -*-
"""بوابة ٢٩-٣ (ب) — قرار ٨٨ — على المحلي بـWebKit جوال: مدير إدارة يفتح «بطاقة الإدارة» (سبعة بنود × أربع مراحل)،
«نعم» تُظهر «أرني» ونعم بلا أرني تُرفض من الخادم، «موثّق» المخاطر يقرؤه النظام من السجل، ثم الإرسال، ثم مسؤول السلامة يرى النتائج.
الاستعمال: webkit-29-3b.py <مدير> <كلمته> <مسؤول السلامة> <كلمته> <رقم القالب> <مجلد الصور>"""
import sys, json
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
mgr_user, mgr_pass, adm_user, adm_pass, form_id, out = sys.argv[1:7]
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
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = m.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
    login(pg, mgr_user, mgr_pass)
    pg.goto(f"{BASE}/app/forms/{form_id}/fill", wait_until="networkidle")
    ok("البطاقة تفتح لمدير الإدارة", pg.url.endswith("/fill"), pg.url)
    ok("سبعة بنود عناوين", pg.locator("[data-field-type=section]").count() == 7, str(pg.locator("[data-field-type=section]").count()))
    claims = pg.locator(".claim"); facts = pg.locator("[data-claim-fact]")
    ok("٢٧ ادعاءً يُسأل عنها + واحد من السجل", claims.count() == 27 and facts.count() == 1, f"{claims.count()}+{facts.count()}")
    ok("«موثّق» المخاطر يقرؤه النظام من السجل", "يقرؤه النظام من سجل الإدارة" in facts.first.inner_text())
    first = claims.nth(0)
    ok("«أرني» مخفية قبل الاختيار", first.locator(".claim-ev").get_attribute("hidden") is not None)
    first.locator("label.claim-btn").nth(0).click()
    ok("ضغطة «نعم» تُظهر «أرني»", first.locator(".claim-ev").get_attribute("hidden") is None)
    ok("«موثّق» يقبل وثيقة", first.locator("input[type=file]").count() == 1)
    pg.screenshot(path=out + "/29-3b-fill-top.png", full_page=False)
    # «لا» في الباقي
    for i in range(1, claims.count()):
        claims.nth(i).locator("label.claim-btn").nth(1).click()
    # نعم بلا أرني ← الخادم يرفض
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("نعم بلا «أرني» تُرفض من الخادم", "/fill" in pg.url and pg.locator(".text-danger").filter(has_text="نعم بلا").count() == 1)
    claims = pg.locator(".claim")
    ok("الإجابات باقية بعد الرفض", claims.nth(1).locator("input.claim-yn[value=no]").is_checked() and claims.nth(0).locator(".claim-ev").get_attribute("hidden") is None)
    claims.nth(0).locator("textarea").fill("خطة الإدارة ٢٠٢٦ — معتمدة في ١٤/٩")
    pg.screenshot(path=out + "/29-3b-fill-rejected.png", full_page=False)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("بالدليل يُقبل ويصل «سُجّلت»", "/submitted" in pg.url, pg.url)

    a = b.new_context(viewport={"width": 1100, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e)))
    login(ap, adm_user, adm_pass)
    ap.goto(f"{BASE}/app/forms", wait_until="networkidle")
    t = ap.locator("body").inner_text()
    ok("القائمة فيها بطاقة الإدارة وحدها من الحزمة", "بطاقة الإدارة" in t and "لوحة المؤشرات" not in t and "سجل السياسات والممارسات" not in t)
    ap.goto(f"{BASE}/app/forms/{form_id}/results", wait_until="networkidle")
    t = ap.locator("body").inner_text()
    ok("النتائج تعرض الدليل", "خطة الإدارة ٢٠٢٦" in t)
    ok("النتائج تعرض ما قرأه النظام من السجل", ("خطراً مفعّلاً" in t) or ("لا أخطار مفعّلة" in t))
    ok("٢٧ شارة نعم/لا + واحدة من السجل", ap.locator("[data-claim]").count() == 28, str(ap.locator("[data-claim]").count()))
    ap.screenshot(path=out + "/29-3b-results.png", full_page=True)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
