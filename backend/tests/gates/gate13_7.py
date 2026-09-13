# -*- coding: utf-8 -*-
"""
بوابة ١٣-٧-٢ — الطبقة صفر (قرار ٤١، المشاكل ١ و٣): عمل الجهاز لا يضيع عند تعارض النسخ، والصور لا تُحمَّل مع الفتح الأولي.

    python tests/gates/gate13_7.py [BASE] [--user=اسم:كلمة]

يكتب صفحة اختبار مؤقتة في public/ ويفتحها بـEdge headless (ملف الاختبار يدخل بالحساب ثم يحمّل ipa-store.js نفسه):
  ١) وثيقة نموذج تُنشأ (v1) ← جهاز آخر يكتب فوقها (v2) ← هذا الجهاز يكتب نسخته الأقدم ← الخادم يرد 409
     ← الخادم يكسب: يبقى v2 كما هو، وتُستبدل نسخة هذا الجهاز بنسخة الخادم، ولا إعادة إرسال ولا معلّق.
     (منع النافذة القديمة من الحفظ في النموذج نفسه: بوابة sendrepro، لا هذا الاختبار.)
  ٢) صورة تُكتب ← `?all=1` يعيدها كسولة بلا بيانات ← `ipaStore.fetchDoc` يجلبها كاملة.
يعمل على الخادم المحلي (الصفحة تُكتب في public/) — وعلى المنشور يُشغَّل الجزء الخادمي فقط بطلبات HTTP.
"""
import os, re, subprocess, sys, requests
sys.stdout.reconfigure(encoding='utf-8')
args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = args[0] if args else 'http://127.0.0.1:8089'
USER = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--user=')), 'salama:1234')
EDGE = r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
PUB = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), 'public')
KEY = 'ipa-gate-form-v10'; PKEY = 'ipa-photo-ipa-gate-form-v10-x-0'
def log(k, v): print(k, '|', v, flush=True)

HARNESS = r"""<!doctype html><meta charset="utf-8"><title>PENDING</title><body><script>
(async function(){
  const R=[]; const U=%(user)s, P=%(pw)s, KEY=%(key)s, PKEY=%(pkey)s;
  const tok=async()=>{const h=await (await fetch('/login',{credentials:'same-origin'})).text();return (h.match(/name="_token" value="([^"]+)"/)||[])[1];};
  const xsrf=()=>decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]||'');
  await fetch('/login',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:await tok(),username:U,password:P})});
  const api=(m,k,body)=>fetch('/api/store/'+k,{method:m,credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-XSRF-TOKEN':xsrf()},body:body?JSON.stringify(body):null});
  await api('DELETE',KEY); await api('DELETE',PKEY); localStorage.removeItem(KEY); localStorage.removeItem(PKEY); localStorage.removeItem('ipa-store-pending');
  await new Promise(res=>{const s=document.createElement('script');s.src='/ipa-store.js?'+Date.now();s.onload=res;document.head.appendChild(s);});
  R.push('loaded='+!!window.ipaStore);
  const sleep=ms=>new Promise(r=>setTimeout(r,ms));
  localStorage.setItem(KEY,'{"v":1}'); await sleep(1500);
  let g=await (await api('GET',KEY)).json(); R.push('v1='+(g.version===1&&g.data==='{"v":1}'));
  const other=await api('PUT',KEY,{data:'{"v":"other"}',version:1}); R.push('other='+other.status);
  localStorage.setItem(KEY,'{"v":2,"reports":[1]}'); await sleep(2500);
  g=await (await api('GET',KEY)).json();
  R.push('after409: version='+g.version+' server-kept-other='+(g.version===2&&g.data==='{"v":"other"}')+' local-replaced='+(localStorage.getItem(KEY)==='{"v":"other"}')+' resent='+(ipaStore.log.some(e=>e.resent))+' pending='+Object.keys(ipaStore.pending()).length);
  localStorage.setItem(PKEY,JSON.stringify({img:'data:image/jpeg;base64,'+'A'.repeat(3000),rep:'x'})); await sleep(1500);
  const all=await (await fetch('/api/store?all=1',{credentials:'same-origin',headers:{'Accept':'application/json'}})).json();
  const d=all.docs[PKEY]; R.push('lazy='+(!!d&&d.lazy===true&&d.data===null&&d.version===1));
  localStorage.removeItem(PKEY); /* كأنه جهاز آخر */ await sleep(1200); /* الحذف يُرسل؟ لا: removeItem يحذف من الخادم — لذا نعيد الكتابة */
  await api('PUT',PKEY,{data:JSON.stringify({img:'data:image/jpeg;base64,BBBB',rep:'x'}),version:0});
  const got=await ipaStore.fetchDoc(PKEY); R.push('fetchDoc='+(typeof got==='string'&&got.indexOf('BBBB')>0)+' notInLocal='+(localStorage.getItem(PKEY)===null));
  await api('DELETE',KEY); await api('DELETE',PKEY); localStorage.removeItem('ipa-store-pending');
  document.title='GATE13_7 '+R.join(' | ');
})().catch(e=>{document.title='GATE13_7 ERROR '+e;});
</script></body>"""

u, p = USER.split(':', 1)
path = os.path.join(PUB, '_gate13_7.html')
open(path, 'w', encoding='utf-8').write(HARNESS % {'user': repr(u), 'pw': repr(p), 'key': repr(KEY), 'pkey': repr(PKEY)})
try:
    pr = subprocess.run([EDGE, '--headless=new', '--disable-gpu', '--virtual-time-budget=20000', '--dump-dom', BASE + '/_gate13_7.html'], capture_output=True, timeout=120)
finally:
    os.remove(path)
m = re.search(r'<title>(GATE13_7[^<]*)</title>', pr.stdout.decode('utf-8', 'replace'))
line = m.group(1) if m else 'NO RESULT'
log('1-2 browser', line)
ok = all(k in line for k in ['loaded=true', 'v1=true', 'other=200', 'server-kept-other=true', 'local-replaced=true', 'resent=false', 'pending=0', 'lazy=true', 'fetchDoc=true notInLocal=true'])
print('GATE 13-7 PASSED' if ok else 'GATE 13-7 FAILED')
