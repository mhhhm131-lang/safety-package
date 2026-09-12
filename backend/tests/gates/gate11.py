# -*- coding: utf-8 -*-
"""
بوابة المرحلة ١١ كاملة — تشغّل البوابات الأربع بالترتيب على العنوان (محلياً أو المنشور).

    python tests/gates/gate11.py [BASE] [--admin=اسم:كلمة] [--tech=اسم:كلمة] [--fm=اسم:كلمة]
"""
import subprocess, sys, os
here = os.path.dirname(os.path.abspath(__file__))
rc = 0
for n in ('11_1', '11_2', '11_3', '11_4'):
    print(f'\n===== gate{n} =====', flush=True)
    r = subprocess.run([sys.executable, os.path.join(here, f'gate{n}.py'), *sys.argv[1:]])
    if r.returncode != 0: rc = r.returncode; print(f'gate{n} FAILED', flush=True); break
print('\nGATE 11 PASSED' if rc == 0 else '\nGATE 11 FAILED')
sys.exit(rc)
