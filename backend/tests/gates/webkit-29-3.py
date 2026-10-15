# -*- coding: utf-8 -*-
"""بوابة ٢٩-٣ على المحلي بـWebKit: مسؤول السلامة يرى القوالب الثمانية في القائمة، والموظف يفتح «بطاقة الإدارة» كاملة من الجوال
(أقسامها وجداولها وآلياتها السبع بصفوف المراحل الأربع). قراءة فقط؛ لا تعبئة."""
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

TITLES = ['بطاقة الإدارة', 'بطاقة الدور الوظيفي', 'سجل المخاطر والفرص', 'سجل السياسات والممارسات', 'سجل الإبلاغ والاقتراح', 'بطاقة المبادرة والابتكار', 'مصفوفة الربط المؤسسي', 'لوحة المؤشرات']

with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    ctx = b.new_context(viewport={"width": 1100, "height": 900}); pg = ctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
    login(pg, adm_user, adm_pass)
    pg.goto(BASE + "/app/forms", wait_until="networkidle")
    txt = pg.locator("body").inner_text()
    ok("القائمة تعرض القوالب الثمانية بأسمائها", all(t in txt for t in TITLES), ", ".join(t for t in TITLES if t not in txt))
    pg.screenshot(path=out + "/29-3-index.png", full_page=True)
    pg.goto(f"{BASE}/app/forms/{form_id}", wait_until="networkidle")
    ok("صفحة بطاقة الإدارة تعرض ٣١ حقلاً", pg.locator("[data-fields-count]").inner_text().strip() == "31", pg.locator("[data-fields-count]").inner_text())
    pg.screenshot(path=out + "/29-3-show.png", full_page=True)

    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar"); mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e)))
    login(mp, emp_user, emp_pass)
    mp.goto(f"{BASE}/app/forms/{form_id}/fill", wait_until="networkidle")
    ok("التعبئة تفتح للموظف", mp.url.endswith("/fill"), mp.url)
    ok("ستة أقسام", mp.locator("[data-field-type=section]").count() == 6, str(mp.locator("[data-field-type=section]").count()))
    ok("ثمانية جداول (المخاطر + سبع آليات)", mp.locator(".form-table").count() == 8, str(mp.locator(".form-table").count()))
    mech = mp.locator(".form-table").nth(1)
    ok("جدول الآلية الأولى بصفوف المراحل الأربع", mech.locator("tbody tr").count() == 4 and "موثّق" in mech.inner_text() and "مطبَّق" in mech.inner_text())
    ok("حالة المرحلة اختيار بثلاثة", mech.locator("tbody tr").nth(0).locator("select option").count() == 4)
    ok("جدول المخاطر بأربعة صفوف ابتدائية وزر صف", mp.locator(".form-table").nth(0).locator("tbody tr").count() == 4 and mp.locator(".row-add").count() == 1)
    ok("لا حقل إلزامي", mp.locator(".form-label .text-danger").count() == 0)
    ok("لا توقيع مرسوم", mp.locator(".sig-pad").count() == 0)
    mp.screenshot(path=out + "/29-3-fill-01.png", full_page=True)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
