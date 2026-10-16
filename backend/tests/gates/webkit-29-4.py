# -*- coding: utf-8 -*-
"""بوابة ٢٩-٤ «الجولة» (قرار ٨٨) — على المحلي بـWebKit: مسؤول السلامة يفتح جولة لإدارة من صفحة الجولات فيُكلَّف مديرها؛
مدير الإدارة على الجوال: «ما ينتظرك» فيه البطاقة، صفحة الجولة بزر «عبّئ البطاقة»، التعبئة برأس تملؤه الإدارة ومديرها وعدد موظفيها والجولة،
الحفظ الآلي يحفظ على الخادم ويعود بعد إعادة التحميل، الإرسال، ثم «البيانات صحيحة وأعتمدها» فتصير الحالة «اعتمدها المدير» بالاسم والوقت؛
ومسؤول السلامة يرى الحالة والسجل وتصله مهمة «عادت بطاقة إدارة».
الاستعمال: webkit-29-4.py <مسؤول السلامة> <كلمته> <اسمه الظاهر> <مدير> <كلمته> <رقم الوحدة> <اسم الوحدة> <اسم المدير> <مجلد الصور>
التهيئة قبلها: prep-29-4.php (يقفل أي جولة سابقة لوحدة المدير)."""
import sys, json, re
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
adm_user, adm_pass, adm_name, mgr_user, mgr_pass, unit_id, unit_name, mgr_name, out = sys.argv[1:10]
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
    ap.goto(f"{BASE}/app/forms", wait_until="networkidle")
    ok("زر «جولات بطاقة الإدارة» في قائمة النماذج", ap.locator("[data-rounds-link]").count() == 1)
    ap.goto(f"{BASE}/app/forms/rounds", wait_until="networkidle")
    ok("صفحة الجولات فيها «فتح جولة لإدارة»", ap.locator("[data-open-round]").count() == 1)
    ap.select_option("[data-open-round] select[name=unit_id]", unit_id)
    with ap.expect_navigation(wait_until="domcontentloaded"):
        ap.click("[data-open-round] button")
    ap.wait_for_load_state("networkidle")
    m = re.search(r"/app/forms/rounds/(\d+)$", ap.url)
    ok("فُتحت الجولة ووصلنا صفحتها", m is not None, ap.url)
    round_id = m.group(1) if m else "0"
    ok("الحالة «لدى الإدارة للتعبئة»", ap.locator("[data-round-state=sent]").count() >= 1)
    lo = ap.locator("[data-log=opened]")
    ok("السجل: فُتحت الجولة باسم مسؤول السلامة ووقتها", lo.count() == 1 and adm_name in lo.inner_text() and re.search(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}", lo.inner_text()) is not None, lo.inner_text() if lo.count() else "")
    ok("البطاقة بانتظار التعبئة ومديرها مسمّى", ap.locator("[data-card-status=pending]").count() == 1 and ap.locator("strong", has_text=mgr_name).count() >= 1)
    ap.screenshot(path=out + "/29-4-round-opened.png", full_page=False)

    # ٢ المدير على الجوال
    mctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    pg = mctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.on("dialog", lambda d: d.accept())
    login(pg, mgr_user, mgr_pass)
    pg.goto(f"{BASE}/app", wait_until="networkidle")
    pg.click("#navInbox"); pg.wait_for_load_state("networkidle")
    ok("«ما ينتظرك» فيه «نموذج «بطاقة الإدارة» ينتظر تعبئتك»", "نموذج «بطاقة الإدارة» ينتظر تعبئتك" in pg.content(), pg.url)
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    ok("المدير يفتح صفحة جولته ويرى «عبّئ البطاقة»", pg.locator("[data-fill]").count() == 1)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-fill]")
    pg.wait_for_load_state("networkidle")
    ok("التعبئة تفتح", pg.url.endswith("/fill"), pg.url)
    hdr = pg.locator("[data-round-header]")
    t = hdr.inner_text() if hdr.count() else ""
    ok("رأس البطاقة: الإدارة ومديرها وموظفوها والجولة", hdr.count() == 1 and unit_name in t and mgr_name in t and "موظفوها" in t and "الجولة" in t, t[:120])
    ok("مؤشر الحفظ الآلي موجود", pg.locator("[data-autosave]").count() == 1)
    claims = pg.locator(".claim")
    claims.nth(0).locator("label.claim-btn").nth(0).click()
    claims.nth(0).locator("textarea").fill("خطة الإدارة ٢٠٢٦ — معتمدة في ١٤/٩")
    pg.wait_for_selector("[data-autosave][data-state=saved]", timeout=15000)
    ok("حُفظ تلقائياً على الخادم", "حُفظ تلقائياً" in pg.locator("[data-autosave]").inner_text())
    pg.screenshot(path=out + "/29-4-fill-autosave.png", full_page=False)
    pg.reload(wait_until="networkidle")
    claims = pg.locator(".claim")
    ok("المسودة تعود بعد إعادة التحميل", claims.nth(0).locator("input.claim-yn[value=yes]").is_checked() and claims.nth(0).locator("textarea").input_value().startswith("خطة الإدارة"))
    for i in range(1, claims.count()):
        claims.nth(i).locator("label.claim-btn").nth(1).click()
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click('#fillForm button.btn-g')
    pg.wait_for_load_state("networkidle")
    ok("الإرسال يصل «سُجّلت»", "/submitted" in pg.url, pg.url)
    pg.goto(f"{BASE}/app", wait_until="networkidle")
    pg.click("#navInbox"); pg.wait_for_load_state("networkidle")
    ok("«ما ينتظرك»: مهمة «اعتمدها» للمدير", f'data-task="round:{round_id}:approve"' in pg.content() and "البيانات صحيحة وأعتمدها" in pg.content(), pg.url)
    pg.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    ok("البطاقة مكتملة وزر «البيانات صحيحة وأعتمدها»", pg.locator("[data-card-status=completed]").count() == 1 and pg.locator("[data-approve]").count() == 1)
    pg.screenshot(path=out + "/29-4-round-approve.png", full_page=True)
    with pg.expect_navigation(wait_until="domcontentloaded"):
        pg.click("[data-approve]")
    pg.wait_for_load_state("networkidle")
    ok("الحالة «اعتمدها المدير»", pg.locator("[data-round-state=done]").count() >= 1)
    lg = pg.locator("[data-log=approved]")
    ok("السجل: الاعتماد باسم المدير ووقته", lg.count() == 1 and mgr_name in lg.inner_text() and re.search(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}", lg.inner_text()) is not None, lg.inner_text() if lg.count() else "")
    ok("لا زر اعتماد ثانٍ", pg.locator("[data-approve]").count() == 0)
    pg.screenshot(path=out + "/29-4-round-done.png", full_page=True)

    # ٣ مسؤول السلامة بعد الاعتماد
    ap.goto(f"{BASE}/app/forms/rounds/{round_id}", wait_until="networkidle")
    ok("مسؤول السلامة يرى «اعتمدها المدير» واسمه", ap.locator("[data-round-state=done]").count() >= 1 and ap.locator("[data-approved]").count() == 1 and mgr_name in ap.locator("[data-approved]").inner_text())
    ap.goto(f"{BASE}/app/forms/rounds", wait_until="networkidle")
    ok("قائمة الجولات: الصف بحالته واعتماده", ap.locator(f"tr[data-round='{round_id}'] [data-round-state=done]").count() == 1 and mgr_name in ap.locator(f"tr[data-round='{round_id}']").inner_text())
    ap.goto(f"{BASE}/app", wait_until="networkidle")
    ap.click("#navInbox"); ap.wait_for_load_state("networkidle")
    ok("«ما ينتظرك» لمسؤول السلامة: «عادت بطاقة إدارة»", f'data-task="round:{round_id}:returned"' in ap.content() and "عادت بطاقة إدارة" in ap.content(), ap.url)
    ap.screenshot(path=out + "/29-4-admin-inbox.png", full_page=False)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
