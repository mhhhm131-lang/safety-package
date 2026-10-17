# -*- coding: utf-8 -*-
"""بوابة ٣٢-١ «فرع واحد ظاهر والرسم يتبعه» (قرار ٩١) — على المحلي بـWebKit، بحسابات seed-32-1.php:
مسؤول السلامة (يرى الكل): صف الفروع، الملز مختار وشبكته وحدها ظاهرة، والرسم يبدأ بأرقام الملز لا بمجموع المباني (الرقم يُقرأ من العمود ويُقارن ببيانات الصفحة)؛
زر الدمام يُظهر شبكته ويرسم مجموعه؛ قبو الدمام يضيّق الرسم بعنوان «الدمام · القبو» وزر «الفرع كله» يعيد إلى الدمام؛ العودة إلى الملز.
مدير فرع الدمام: كما كان — بلا صف، عنوان «أماكنك»، أرقام فرعه.
الاستعمال: webkit-32-1.py <كلمة حسابات البوابة> <JSON التجهيز> <مجلد الصور>"""
import sys, json
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8089"
pw, seed_json, out = sys.argv[1:4]
S = json.loads(seed_json)
MAIN, DMM = str(S["main_building"]), str(S["dmm_building"])
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

def snap(page):
    return page.evaluate("JSON.parse(document.getElementById('snapshot').textContent)")
def bar(page, k):
    return int(page.locator(f"#bars .bar[data-k='{k}']").get_attribute("data-n"))
def visible_groups(page):
    return page.evaluate("Array.from(document.querySelectorAll('#places [data-tile-group]')).filter(g=>!g.hidden).map(g=>g.getAttribute('data-tile-group'))")
def scope(page):
    return page.locator("#chartScope").inner_text().strip()

with sync_playwright() as p:
    b = p.webkit.launch()
    errors = []
    # ١ مسؤول السلامة على الجوال: الصف، الملز مختار وظاهر وحده، والرسم بأرقام الملز
    m = b.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, is_mobile=True, has_touch=True, locale="ar")
    mp = m.new_page(); mp.on("pageerror", lambda e: errors.append(str(e))); mp.on("dialog", lambda d: d.accept())
    login(mp, "g321.salama", pw)
    mp.goto(f"{BASE}/app", wait_until="networkidle")
    D = snap(mp)
    tabs = mp.locator("#bldTabs .bld-tab")
    ok("صف الفروع: زر لكل مبنى (≥٢) والملز مختار", tabs.count() >= 2 and mp.locator(f"#bldTabs .bld-tab[data-bld='{MAIN}'][aria-selected='true']").count() == 1, f"أزرار={tabs.count()}")
    ok("شبكة الملز وحدها ظاهرة", visible_groups(mp) == [MAIN], str(visible_groups(mp)))
    ok("لا قوائم تُطوى تحت بعض", mp.locator("#places .grp-h, #places [data-bs-toggle='collapse']").count() == 0)
    mlz_inc, all_inc = D["buildings"][MAIN]["total"]["incidents"], D["total"]["incidents"]
    ok("الرسم يبدأ بعنوان الملز وبرقم بلاغات الملز لا مجموع المباني", scope(mp) == S["main_name"] and bar(mp, "incidents") == mlz_inc and all_inc > mlz_inc,
       f"العنوان={scope(mp)!r} العمود={bar(mp,'incidents')} الملز={mlz_inc} الكل={all_inc}")
    ok("زر «الفرع كله» مخفي على مستوى الفرع", mp.locator("#chartAll").is_hidden())
    mp.screenshot(path=out + "/32-1-home-mlz-mobile.png", full_page=False)

    # ٢ زر الدمام: شبكته وحدها، والرسم مجموع الدمام
    mp.click(f"#bldTabs .bld-tab[data-bld='{DMM}']"); mp.wait_for_timeout(300)
    dmm_inc = D["buildings"][DMM]["total"]["incidents"]
    ok("ضغطة الدمام: زره مختار وشبكته وحدها ظاهرة", mp.locator(f"#bldTabs .bld-tab[data-bld='{DMM}'][aria-selected='true']").count() == 1 and visible_groups(mp) == [DMM], str(visible_groups(mp)))
    ok("الرسم صار «حال الآن · الدمام» برقم بلاغات الدمام (≥١)", scope(mp) == S["dmm_name"] and bar(mp, "incidents") == dmm_inc and dmm_inc >= 1,
       f"العنوان={scope(mp)!r} العمود={bar(mp,'incidents')} الدمام={dmm_inc}")
    mp.screenshot(path=out + "/32-1-home-dmm-mobile.png", full_page=False)

    # ٣ قبو الدمام: «الدمام · القبو» برقم المكان، وزر «الفرع كله» يعيد إلى الدمام
    code = S["dmm_basement"]
    mp.click(f"#places .pl-tile[data-place='{code}']"); mp.wait_for_timeout(300)
    place_inc = D["places"][code]["n"]["incidents"]
    ok("ضغطة قبو الدمام: العنوان «الدمام · القبو» والعمود رقم المكان (≥١)", scope(mp) == f"{S['dmm_name']} · {S['dmm_basement_name']}" and bar(mp, "incidents") == place_inc and place_inc >= 1,
       f"العنوان={scope(mp)!r} العمود={bar(mp,'incidents')} المكان={place_inc}")
    ok("زر «الفرع كله» ظاهر بنصه وزر ملف المكان يفتح ملف قبو الدمام", mp.locator("#chartAll").is_visible() and mp.locator("#chartAll").inner_text().strip() == "الفرع كله"
       and mp.locator("#chartFile").is_visible() and mp.locator("#chartFile").get_attribute("href") == D["places"][code]["file"])
    mp.click("#chartAll"); mp.wait_for_timeout(300)
    ok("«الفرع كله» يعيد إلى الدمام لا إلى المباني كلها", scope(mp) == S["dmm_name"] and bar(mp, "incidents") == dmm_inc and mp.locator("#places .pl-tile.on").count() == 0,
       f"العنوان={scope(mp)!r} العمود={bar(mp,'incidents')}")

    # ٤ العودة إلى الملز
    mp.click(f"#bldTabs .bld-tab[data-bld='{MAIN}']"); mp.wait_for_timeout(300)
    ok("العودة إلى الملز: شبكته وحدها ورقمه", visible_groups(mp) == [MAIN] and scope(mp) == S["main_name"] and bar(mp, "incidents") == mlz_inc)

    # ٤-ب (٣٢-٢) مبنى فرع الرياض بلا أماكن (من الترحيل على المحلي): زره موجود، شبكته رسالة وباب ملفه، ورسمه أصفار
    ryd = mp.locator("#bldTabs .bld-tab", has_text="فرع الرياض")
    ok("زر فرع الرياض موجود بوسم «بلا أماكن بعد»", ryd.count() == 1 and "بلا أماكن بعد" in ryd.first.inner_text(), f"عدد={ryd.count()}")
    if ryd.count() == 1:
        rid = ryd.first.get_attribute("data-bld"); rname = mp.evaluate(f"JSON.parse(document.getElementById('snapshot').textContent).buildings['{rid}'].name")
        ryd.first.click(); mp.wait_for_timeout(300)
        empty = mp.locator(f"#places .pl-empty[data-tile-group='{rid}']")
        door = empty.locator("a.btn")
        ok("ضغطة الرياض: شبكته وحدها (رسالة «لم تُنشأ أماكن هذا المبنى بعد») وزر «افتح ملف المبنى» إلى ملفه",
           visible_groups(mp) == [rid] and empty.is_visible() and "لم تُنشأ أماكن هذا المبنى بعد" in empty.inner_text()
           and door.count() == 1 and f"/app/emergency/buildings/{rid}" in (door.get_attribute("href") or ""), str(visible_groups(mp)))
        ok("رسم الرياض باسمه وأصفار", scope(mp) == rname and bar(mp, "incidents") == 0 and bar(mp, "rounds") == 0, f"العنوان={scope(mp)!r}")
        mp.screenshot(path=out + "/32-2-home-ryd-mobile.png", full_page=False)
        with mp.expect_navigation(wait_until="domcontentloaded"):
            door.click()
        mp.wait_for_load_state("networkidle")
        ok("الباب يفتح ملف المبنى وفيه زر «أماكن المبنى»", f"/app/emergency/buildings/{rid}" in mp.url and mp.locator("form button:has-text('أماكن المبنى'), button:has-text('أماكن المبنى'), a:has-text('أماكن المبنى')").count() >= 1, mp.url)
        mp.goto(f"{BASE}/app", wait_until="networkidle")

    # ٥ على الحاسب: الصف نفسه والرسم نفسه
    d = b.new_context(viewport={"width": 1280, "height": 800}, locale="ar"); dp = d.new_page(); dp.on("pageerror", lambda e: errors.append(str(e)))
    login(dp, "g321.salama", pw)
    dp.goto(f"{BASE}/app", wait_until="networkidle")
    ok("الحاسب: الملز مختار وظاهر وحده والرسم بأرقامه", visible_groups(dp) == [MAIN] and scope(dp) == S["main_name"] and bar(dp, "incidents") == mlz_inc)
    dp.screenshot(path=out + "/32-1-home-desktop.png", full_page=False)

    # ٦ مدير فرع الدمام: كما كان
    e = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ar"); ep = e.new_page(); ep.on("pageerror", lambda x: errors.append(str(x)))
    login(ep, "g321.mgr", pw)
    ep.goto(f"{BASE}/app", wait_until="networkidle")
    E = snap(ep)
    ok("مدير فرع الدمام: بلا صف فروع ولا مجموعات، عنوان «أماكنك»، والعمود رقم فرعه",
       ep.locator("#bldTabs").count() == 0 and ep.locator("#places [data-tile-group]").count() == 0 and scope(ep) == "أماكنك"
       and bar(ep, "incidents") == E["total"]["incidents"] and E["total"]["incidents"] == dmm_inc and list(E["buildings"].keys()) == [DMM],
       f"العنوان={scope(ep)!r} العمود={bar(ep,'incidents')} مبانيه={list(E['buildings'].keys())}")
    ep.screenshot(path=out + "/32-1-home-mgr-mobile.png", full_page=False)
    ok("لا أخطاء جافاسكربت", errors == [], "; ".join(errors)[:200])
    b.close()

print(json.dumps({"passed": sum(1 for c in checks if c[1]), "failed": [c[0] for c in checks if not c[1]]}, ensure_ascii=False))
sys.exit(0 if all(c[1] for c in checks) else 1)
