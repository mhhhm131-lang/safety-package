# -*- coding: utf-8 -*-
"""بوابة ٢٩-٢ «السرية» (قرار ٨٨ §٤) — على المحلي بـWebKit جوال: موظف يفتح قالباً يقبل السرية فيرى صندوق «إجابتك سرية»
وخانة «أُعلن اسمي»، يرسل فيصل «سُجّلت إجابتك سرياً» بلا رقم تعبئة، ولا يفتح التعبئة ثانية؛ ومسؤول السلامة يرى النتائج
محجوبة تحت خمسة ثم ظاهرة بلا اسم ولا ساعة، والتصدير بلا اسم، والمتابعة بالأعداد لا الأسماء، وصفحة القالب بشارة «سري».
الاستعمال: webkit-29-2.py <موظف> <كلمته> <مسؤول السلامة> <كلمته> <رقم القالب> <اسم الموظف الظاهر> <مجلد الصور>
التهيئة قبلها: prep-29-2.php (أربع تعبئات سرية جاهزة، والموظف بانتظار التعبئة)."""
import sys, json, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
emp_user, emp_pass, adm_user, adm_pass, form_id, emp_name, out = sys.argv[1:8]
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

CLOCK = re.compile(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}")

with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []

    # مسؤول السلامة قبل التعبئة الخامسة: النتائج محجوبة (أربعة مجيبين)
    a = b.new_context(viewport={"width": 1100, "height": 900}); ap = a.new_page(); ap.on("pageerror", lambda e: errors.append(str(e)))
    login(ap, adm_user, adm_pass)
    ap.goto(f"{BASE}/app/forms/{form_id}/results", wait_until="networkidle")
    hidden = ap.locator("[data-hidden-below-threshold]")
    ok("تحت خمسة مجيبين: النتائج محجوبة", hidden.count() == 1 and hidden.get_attribute("data-hidden-below-threshold") == "4", str(hidden.count()))
    ok("ولا صف ولا إجابة تظهر", ap.locator("tr[data-submission]").count() == 0 and "واقع 1" not in ap.locator("body").inner_text())
    ap.screenshot(path=out + "/29-2-results-hidden.png", full_page=False)
    ex = ap.context.request.get(f"{BASE}/app/forms/{form_id}/export", max_redirects=0)
    ok("ولا تصدير (تحويل إلى النتائج)", ex.status in (301, 302) and "/results" in (ex.headers.get("location") or ""), f"{ex.status}")

    # الموظف على الجوال
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = m.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
    login(pg, emp_user, emp_pass)
    pg.goto(f"{BASE}/app/forms/{form_id}/fill", wait_until="networkidle")
    ok("التعبئة تفتح للموظف", pg.url.endswith("/fill"), pg.url)
    ok("صندوق «إجابتك سرية» وخانة «أُعلن اسمي»", pg.locator("[data-anonymous-box]").count() == 1 and pg.locator("input[name=reveal_name]").count() == 1)
    ok("الخانة غير مختارة افتراضاً (سري افتراضاً)", not pg.locator("input[name=reveal_name]").is_checked())
    ok("لا «تُسجَّل باسمك» في التذييل", "باسمك" not in pg.locator(".card-footer").inner_text())
    pg.fill("input[name^='fields[']", "الصلاحيات في الورقة غير التي نُمارسها")
    pg.locator("label.form-check-label", has_text="نعم").first.click()
    pg.screenshot(path=out + "/29-2-fill.png", full_page=True)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("يصل «سُجّلت» بلا رقم تعبئة في الرابط", pg.url.endswith("/submitted") and "submission=" not in pg.url, pg.url)
    ok("«سُجّلت إجابتك سرياً»", pg.locator("[data-anonymous-done]").count() == 1 and "لا يُنسب جوابك إليك" in pg.locator("body").inner_text())
    ok("التاريخ باليوم لا بالساعة", not CLOCK.search(pg.locator(".card-body").inner_text()))
    pg.screenshot(path=out + "/29-2-submitted.png", full_page=False)
    resp = pg.goto(f"{BASE}/app/forms/{form_id}/fill", wait_until="domcontentloaded")
    ok("لا تعبئة ثانية (403)", resp is not None and resp.status == 403, str(resp.status if resp else None))

    # مسؤول السلامة بعد الخامسة
    ap.goto(f"{BASE}/app/forms/{form_id}/results", wait_until="networkidle")
    body = ap.locator("body").inner_text()
    ok("خمسة مجيبين: النتائج تظهر", ap.locator("[data-hidden-below-threshold]").count() == 0 and "الصلاحيات في الورقة" in body)
    ok("خمسة صفوف كلها «سري» بلا رقم", ap.locator("tr[data-submission='secret']").count() == 5 and body.count("سري") >= 5)
    ok("لا اسم الموظف في النتائج", emp_name not in body and "موظف تجربة" not in body, emp_name)
    ok("لا ساعة في النتائج", not CLOCK.search(ap.locator("table").last.inner_text()))
    ap.screenshot(path=out + "/29-2-results.png", full_page=True)
    ex = ap.context.request.get(f"{BASE}/app/forms/{form_id}/export")
    csv = ex.text()
    ok("التصدير: «سري» بلا اسم ولا ساعة", ex.status == 200 and "سري" in csv and emp_name not in csv and "موظف تجربة" not in csv and not CLOCK.search(csv), f"{ex.status}")
    ap.goto(f"{BASE}/app/forms/{form_id}/tracking", wait_until="networkidle")
    body = ap.locator("body").inner_text()
    ok("المتابعة بالأعداد لا الأسماء", ap.locator("[data-anonymous-tracking]").count() == 1 and ap.locator("tr[data-assignment]").count() == 0 and emp_name not in body and "موظف تجربة" not in body)
    ok("الأعداد: مكلَّف ٦ وعبّأ ٥", ap.locator("[data-stat='مكلَّف']").inner_text().strip() == "6" and ap.locator("[data-stat='عبّأ']").inner_text().strip() == "5", ap.locator("[data-stat='مكلَّف']").inner_text() + "/" + ap.locator("[data-stat='عبّأ']").inner_text())
    ok("زر «ذكّر من لم يعبّئ» موجود", "ذكّر من لم يعبّئ" in body)
    ap.screenshot(path=out + "/29-2-tracking.png", full_page=False)
    ap.goto(f"{BASE}/app/forms/{form_id}", wait_until="networkidle")
    ok("صفحة القالب بشارة «سري باختيار المعبِّئ»", ap.locator("[data-anonymous]").count() == 1)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
