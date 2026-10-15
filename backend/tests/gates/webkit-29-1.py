# -*- coding: utf-8 -*-
"""بوابة ٢٩-١ على المحلي بمتصفح WebKit بمقاس iPhone: موظف يفتح التعبئة، يضيف صفاً، يملأ الجدولين،
يجيب «لا» بلا ملاحظة فيُرفض، ثم بالملاحظة فيُقبل؛ ثم مسؤول السلامة يفتح النتائج. كل فحص يقرأ القيمة من الشاشة."""
import sys, json
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
emp_user, emp_pass, adm_user, adm_pass, form_id, out = sys.argv[1:7]
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
    ctx = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))

    login(page, emp_user, emp_pass)
    page.goto(f"{BASE}/app/forms/{form_id}/fill", wait_until="networkidle")
    ok("صفحة التعبئة تفتح للموظف", page.url.endswith("/fill"), page.url)
    ok("القسم يظهر عنواناً", page.locator('[data-field-type=section] h2').count() == 2)
    free = page.locator('.form-table').nth(0); fixed = page.locator('.form-table').nth(1)
    ok("الجدول الحر بصفين ابتداءً", free.locator('tbody tr').count() == 2)
    ok("الجدول الثابت بثلاثة صفوف بأسمائها", fixed.locator('tbody tr').count() == 3 and "خطة تربط" in fixed.locator('tbody tr').nth(0).inner_text())
    page.screenshot(path=out + "/29-1-fill-top.png", full_page=True)

    # صف ثالث في الحر
    page.click('.row-add'); ok("زر «صف» يضيف صفاً ثالثاً", free.locator('tbody tr').count() == 3)
    rows = [("مقبس يشرر في الممر", "نفسي", "بلاغ للمرافق"), ("لا وصف مكتوب للدور", "معرفي", "كتابة بطاقة الدور"), ("اجتماع أسبوعي بلا محضر", "تنظيمي", "محضر")]
    for r, (a, bsel, c) in enumerate(rows):
        tr = free.locator('tbody tr').nth(r)
        tr.locator('input[type=text]').nth(0).fill(a); tr.locator('select').select_option(bsel); tr.locator('input[type=text]').nth(1).fill(c)
    for r, (v1, v2, g) in enumerate([("نعم", "جزئياً", "خطة ٢٠٢٦ معتمدة"), ("لا", "لا", ""), ("جزئياً", "نعم", "سجل المخاطر")]):
        tr = fixed.locator('tbody tr').nth(r)
        tr.locator('select').nth(0).select_option(v1); tr.locator('select').nth(1).select_option(v2); tr.locator('input[type=text]').fill(g)
    # بند الإقرار: «لا» في الفهم بلا ملاحظة
    page.check('input[name$="[ok]"][value="نعم"]'); page.check('input[name$="[und]"][value="لا"]'); page.check('input[name$="[app]"][value="نعم"]')
    ok("أي «لا» تُظهر أن الملاحظة إلزامية", page.locator('.ack-note-req').is_visible())
    note = page.locator('textarea[name$="[note]"]')
    ok("المتصفح يمنع الإرسال بلا ملاحظة (required)", page.evaluate("document.querySelector('textarea[name$=\"[note]\"]').required"))
    # نتجاوز حارس المتصفح لنختبر الخادم
    page.evaluate("document.querySelector('textarea[name$=\"[note]\"]').required = false")
    with page.expect_navigation(wait_until="domcontentloaded"):
        page.click('#fillForm button.btn-g')
    page.wait_for_load_state("networkidle")
    ok("الخادم يرفض «لا» بلا ملاحظة ويعيد الصفحة بالخطأ", "/fill" in page.url and page.locator('.text-danger').filter(has_text="اكتب ما الواقع").count() >= 1)
    ok("القيم المدخلة باقية بعد الرفض", page.locator('.form-table').nth(0).locator('tbody tr').count() == 3 and page.locator('.form-table').nth(0).locator('input[type=text]').nth(0).input_value() == rows[0][0])
    page.screenshot(path=out + "/29-1-fill-rejected.png", full_page=True)
    note.fill("لا أعرف من يملك الخطة ولم تُعرض علينا")
    with page.expect_navigation(wait_until="domcontentloaded"):
        page.click('#fillForm button.btn-g')
    page.wait_for_load_state("networkidle")
    ok("بالملاحظة يُقبل ويصل صفحة «سُجّلت»", "/submitted" in page.url, page.url)
    page.screenshot(path=out + "/29-1-submitted.png", full_page=True)

    # مسؤول السلامة: النتائج
    page.goto(BASE + "/logout", wait_until="networkidle") if page.locator('form[action$="/logout"]').count() == 0 else None
    ctx2 = b.new_context(viewport={"width": 1100, "height": 900}); p2 = ctx2.new_page()
    login(p2, adm_user, adm_pass)
    p2.goto(f"{BASE}/app/forms/{form_id}/results", wait_until="networkidle")
    txt = p2.locator('body').inner_text(); html = p2.content()
    ok("النتائج تعرض خلايا الجدول الحر (داخل التفاصيل المطوية)", "مقبس يشرر في الممر" in html and "اجتماع أسبوعي بلا محضر" in html)
    ok("النتائج تعرض الصف الثابت وقيمه", "خطة ٢٠٢٦ معتمدة" in html and "خطة تربط دور الفرد بالرؤية" in html)
    p2.locator('details summary').first.click(); p2.wait_for_timeout(300)
    ok("فتح التفاصيل يُظهر الجدول للعين", "مقبس يشرر في الممر" in p2.locator('body').inner_text())
    ok("بند الإقرار بشاراته والملاحظة", p2.locator('[data-ack=und].bg-danger').count() == 1 and "لا أعرف من يملك الخطة" in txt)
    ok("الملخص يعدّ «أفهمه — لا»", "أفهمه — لا" in txt)
    ok("القسم لا عمود له في جدول التعبئات", p2.locator('table.table-hover thead th').filter(has_text="أولاً: المخاطر").count() == 0)
    p2.screenshot(path=out + "/29-1-results.png", full_page=True)
    p2.goto(f"{BASE}/app/forms/{form_id}", wait_until="networkidle")
    ok("صفحة القالب تعرض تعريف الجدول مقروءاً", "الأعمدة: الآلية" in p2.locator('body').inner_text())
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
