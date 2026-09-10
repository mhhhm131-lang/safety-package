# -*- coding: utf-8 -*-
"""
بوابة المرحلة ١٠ «تشغيل خطط الاستجابة» على المنشور — السيناريوهات الأربعة بالترتيب (١٠-١ ← ١٠-٤).

    python tests/gates/gate10.py https://ipa-safety.onrender.com --admin=اسم:كلمة [--tech=اسم:كلمة]

يشغّل gate10_1 (المزامنة والأدوار) ثم gate10_2 (القائمة الحية والتجاوز — ينتظر المجدول دقيقة على المنشور)
ثم gate10_3 (من البلاغ إلى الحالة) ثم gate10_4 (التقرير والمؤشرات والجاهزية). يتوقف عند أول سقوط.
كل سكربت يترك المبنى فارغاً (ينهي ما فعّله). البيانات التجريبية التي تبقى: حالات `ط-…` وبلاغان `ش-…` وتمرين — تُنظَّف من شاشة الإغلاق.
"""
import subprocess, sys, os
sys.stdout.reconfigure(encoding='utf-8')

here = os.path.dirname(os.path.abspath(__file__))
args = sys.argv[1:]
for name in ['gate10_1.py', 'gate10_2.py', 'gate10_3.py', 'gate10_4.py']:
    print(f'\n########## {name}', flush=True)
    r = subprocess.run([sys.executable, os.path.join(here, name), *args], cwd=os.path.dirname(os.path.dirname(here)))
    if r.returncode != 0:
        print(f'\nGATE 10 FAILED at {name}'); sys.exit(r.returncode)
print('\nGATE 10 PASSED (1..4)')
