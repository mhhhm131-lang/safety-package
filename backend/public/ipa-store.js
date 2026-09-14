/*
 * ipa-store.js — الطبقة صفر: الخادم هو الحقيقة، والمتصفح ذاكرة مؤقتة.
 *
 * يُضمَّن سطراً واحداً في رأس صفحات المعهد التشغيلية (النماذج العشرة، اللوحة).
 * لا يتغير أي سطر آخر فيها: يعترض localStorage لكل مفتاح ipa-* ويمرره إلى /api/store.
 *
 *  - عند التحميل: يرسل أولاً الكتابات المعلقة من زيارة سابقة (طابور محفوظ محلياً)،
 *    ثم يجلب كل الوثائق من الخادم (طلب متزامن) قبل أن يعمل سكربت الصفحة،
 *    ويكتب جلسة الخادم في ipa-session فيدخل المستخدم تلقائياً كما اليوم.
 *  - عند الكتابة: يحفظ محلياً ويسجّلها في الطابور المعلق ويرسلها بعد ٤٠٠ مللي ثانية.
 *    تعارض النسخة (409) يعيد ما عند الخادم. ما لم يصل الخادم يبقى في الطابور حتى يصل.
 *  - كل ٣٠ ثانية وعند العودة للصفحة: يستطلع أرقام النسخ ويحدّث ما تغيّر من جهاز آخر.
 *  - بلا جلسة: يحوّل إلى /login. حذف ipa-session (الخروج في الصفحة) = خروج من الخادم.
 *  - من file://: لا يفعل شيئاً، والصفحة تعمل كما كانت.
 *  - شاشة الدخول القديمة (#login) لا تظهر تحت النظام. إن لم تُكمل الصفحة دخولها الآلي — الخادم لا يرد، أو يرفض،
 *    أو خطأ في الصفحة — تظهر رسالة النظام (#ipaGate) بسببها، بدل شاشة دخول لا تعرف حسابات النظام.
 */
(function () {
  'use strict';
  if (location.protocol === 'file:' || !window.localStorage) return;
  /* المشكلة ٨: تُخفى شاشة الدخول القديمة من لحظة قراءة الرأس، فلا تظهر ولو للحظة */
  try { var HIDE = document.createElement('style'); HIDE.textContent = '#login{display:none!important}'; (document.head || document.documentElement).appendChild(HIDE); } catch (e) {}

  var API = '/api/store';
  var PKEY = 'ipa-store-pending';
  var SKIP = { 'ipa-session': 1, 'ipa-store-pending': 1 };
  var LS = window.localStorage;
  var origSet = LS.setItem.bind(LS), origGet = LS.getItem.bind(LS), origRemove = LS.removeItem.bind(LS);
  var VER = {}, Q = {}, T = null, HAVE_SESSION = false, CSRF = '', INFLIGHT = {}, BACKOFF = false;
  var LOG = [];
  window.ipaStore = { status: 'loading', log: LOG, versions: VER, pending: function () { return readPending(); }, fetchDoc: fetchDoc, queued: function () { return Object.keys(Q); }, busy: busy, whenIdle: whenIdle };
  function fail(kind) { window.ipaStore.status = kind; }
  /* بعد أن تعمل سكربتات الصفحة: إن بقيت شاشة الدخول بلا .off فالدخول الآلي لم يكتمل ← رسالة النظام بسببها */
  document.addEventListener('DOMContentLoaded', function () {
    var st = window.ipaStore.status;
    if (st === 'login') return;
    var L = document.getElementById('login');
    if (!L || L.classList.contains('off')) return;
    var M = {
      offline: ['تعذّر الاتصال بالنظام', 'تحقق من اتصال الإنترنت ثم أعد المحاولة.', 'إعادة المحاولة', ''],
      error: ['النظام لا يستجيب الآن', 'أعد المحاولة بعد قليل.', 'إعادة المحاولة', ''],
      denied: ['حسابك لا يملك صلاحية هذه الصفحة', 'نماذج الفحص واللوحة لأدوار العمل اليومي.', 'العودة إلى النظام', '/app']
    }[st] || ['تعذّر فتح الصفحة', 'أعد التحميل. إن تكرر ذلك فأخبر مسؤول السلامة.', 'إعادة التحميل', ''];
    var g = document.createElement('div'); g.id = 'ipaGate';
    g.style.cssText = 'position:fixed;inset:0;z-index:10001;background:#e9e6e0;display:flex;align-items:center;justify-content:center;padding:16px;font-family:Cairo,Arial,sans-serif;direction:rtl';
    g.innerHTML = '<div style="background:#fff;border-top:4px solid #b8912f;border-radius:12px;padding:20px;max-width:420px;width:100%;text-align:center">'
      + '<b style="display:block;font-size:18px;color:#0f3d2e;margin-bottom:8px"></b><p style="font-size:14px;color:#6b6560;margin:0 0 16px;line-height:1.7"></p>'
      + '<button type="button" style="font:700 15px Cairo,Arial,sans-serif;min-height:48px;width:100%;border:0;border-radius:8px;background:#0f3d2e;color:#fff;cursor:pointer"></button></div>';
    g.querySelector('b').textContent = M[0]; g.querySelector('p').textContent = M[1]; g.querySelector('button').textContent = M[2];
    g.querySelector('button').onclick = function () { if (M[3]) location.href = M[3]; else location.reload(); };
    document.body.appendChild(g);
  });
  var isKey = function (k) { return typeof k === 'string' && k.indexOf('ipa-') === 0 && !SKIP[k]; };
  /* ١٣-٧-٢: أُزيلت «آخر كتابة تكسب» (1260876) بعد أن أثبتت البوابة أنها تجعل نافذة قديمة تمسح الأحدث من اللمسة الأولى.
     عند التعارض (409) الخادم يكسب، والنموذج نفسه يمنع النافذة القديمة من الحفظ (نسخة واحدة تحفظ). */
  var MEM = {};
  /* صورة أو وثيقة كسولة تُجلب عند الحاجة (مشكلة ٣): من الذاكرة ثم من الجهاز ثم من الخادم — ولا تُكتب في localStorage حتى لا تُرفع من جديد */
  function fetchDoc(k) {
    if (MEM[k] !== undefined) return Promise.resolve(MEM[k]);
    var local = origGet(k);
    if (local != null) return Promise.resolve(local);
    return fetch(API + '/' + encodeURIComponent(k), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.data != null) { MEM[k] = j.data; VER[k] = j.version; return j.data; } return null; })
      .catch(function () { return null; });
  }

  function readPending() { try { return JSON.parse(origGet(PKEY)) || {}; } catch (e) { return {}; } }
  function writePending(p) { try { if (Object.keys(p).length) origSet(PKEY, JSON.stringify(p)); else origRemove(PKEY); } catch (e) {} }
  function markPending(k, op) { var p = readPending(); p[k] = { op: op, version: VER[k] || 0 }; writePending(p); }
  function clearPending(k) { var p = readPending(); if (p[k]) { delete p[k]; writePending(p); } }

  function xsrf() {
    var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function toLogin() {
    window.ipaStore.status = 'login';
    location.replace('/login?next=' + encodeURIComponent(location.pathname + location.search + location.hash));
  }

  /* ---------- شريط الحالة ---------- */
  var BAR = null;
  function bar(msg, kind) {
    if (!msg) { if (BAR) { BAR.remove(); BAR = null; } return; }
    if (!BAR) {
      BAR = document.createElement('div');
      BAR.id = 'ipaStoreBar';
      /* على الشاشة الضيقة أعلى الصفحة حتى لا يغطي أزرار الجوال الثابتة أسفلها (مشكلة ٩) */
      var narrow = window.matchMedia && window.matchMedia('(max-width:680px)').matches;
      BAR.style.cssText = 'position:fixed;' + (narrow ? 'top:0' : 'bottom:0') + ';left:0;right:0;z-index:9999;padding:6px 12px;text-align:center;font:700 13px/1.4 Segoe UI,Tahoma,Arial,sans-serif;color:#fff;direction:rtl';
      (document.body || document.documentElement).appendChild(BAR);
    }
    BAR.style.background = kind === 'bad' ? '#9b1c1c' : '#5b4d00';
    BAR.textContent = msg;
  }

  /* ---------- ٠) إرسال الكتابات المعلقة من زيارة سابقة (متزامن، قبل الجلب) ---------- */
  function syncSend(k, op, version) {
    var raw = origGet(k);
    if (op === 'put' && raw == null) return { status: 0 };
    var y = new XMLHttpRequest();
    try {
      y.open(op === 'del' ? 'DELETE' : 'PUT', API + '/' + encodeURIComponent(k), false);
      y.setRequestHeader('Content-Type', 'application/json');
      y.setRequestHeader('Accept', 'application/json');
      y.setRequestHeader('X-XSRF-TOKEN', xsrf());
      y.send(op === 'del' ? null : JSON.stringify({ data: raw, version: version || 0 }));
      var j = null; try { j = JSON.parse(y.responseText); } catch (e) {}
      return { status: y.status, json: j };
    } catch (e) { return { status: 0 }; }
  }
  var pend = readPending();
  var pendKeys = Object.keys(pend);
  if (pendKeys.length) {
    pendKeys.forEach(function (k) {
      var r = syncSend(k, pend[k].op, pend[k].version);
      LOG.push({ k: k, op: pend[k].op, replay: true, status: r.status, body: (r.status >= 400 && r.json) ? (r.json.error || r.json.message || '') : '' });
      if (r.status === 200 || r.status === 404 || r.status === 409 || r.status === 422) clearPending(k);
      if (r.status === 401 || r.status === 419) { toLogin(); return; }
    });
  }

  /* ---------- ١) الجلب الأولي المتزامن ---------- */
  var x = new XMLHttpRequest();
  try {
    x.open('GET', API + '?all=1', false);
    x.setRequestHeader('Accept', 'application/json');
    x.send(null);
  } catch (e) { fail('offline'); return; }
  if (x.status === 401 || x.status === 419) { toLogin(); return; }
  if (x.status !== 200) { fail(x.status === 403 ? 'denied' : 'error'); return; }
  var res;
  try { res = JSON.parse(x.responseText); } catch (e) { fail('error'); return; }
  HAVE_SESSION = true;
  CSRF = res.csrf || '';

  var cur = null;
  try { cur = JSON.parse(origGet('ipa-session')); } catch (e) {}
  var s = { u: res.session.u, r: res.session.r, n: res.session.n, at: Date.now() };
  if (res.session.d) s.d = res.session.d; else if (cur && cur.d) s.d = cur.d;
  origSet('ipa-session', JSON.stringify(s));
  window.ipaStore.status = 'ok';

  var docs = res.docs || {};
  var stillPending = readPending();
  Object.keys(docs).forEach(function (k) {
    VER[k] = docs[k].version;
    if (docs[k].lazy) return; /* صورة: نسختها فقط؛ تُجلب عند فتح بلاغها ولا تُخزَّن في كل جهاز */
    if (stillPending[k]) return; /* كتابة محلية لم تصل بعد: لا تُكتب فوقها */
    origSet(k, docs[k].data);
  });
  /* مفاتيح على هذا الجهاز ليست في الخادم بعد (أول ربط): تُرفع */
  for (var i = 0; i < LS.length; i++) {
    var k0 = LS.key(i);
    if (isKey(k0) && !(k0 in docs)) queue(k0);
  }
  if (Object.keys(stillPending).length) { Object.keys(stillPending).forEach(function (k) { queue(k, stillPending[k].op === 'del'); }); }

  /* ---------- ٢) اعتراض الكتابة ---------- */
  /* على نموذج Storage لا على localStorage نفسه: في سفاري الإسناد `localStorage.setItem = fn` لا يستبدل الدالة
     بل يخزّن عنصراً اسمه "setItem" فلا يُرسل أي حفظ (ثبت بمحرك WebKit ٢٠٢٦-٠٩-١٤، قائم منذ المرحلة ٠).
     sessionStorage وأي Storage آخر يمرّ إلى الدالة الأصلية كما هو. */
  var SP = Storage.prototype;
  var nativeSet = SP.setItem, nativeRemove = SP.removeItem, nativeClear = SP.clear;
  /* ما خزّنه الإسناد القديم في سفاري: عناصر بأسماء الدوال قيمتها نص الدالة — تُحذف مرة */
  ['setItem', 'removeItem', 'clear'].forEach(function (n) {
    try { var sv = origGet(n); if (sv !== null && /^function\s*\(/.test(sv)) origRemove(n); } catch (e) {}
  });
  SP.setItem = function (k, v) {
    if (this !== LS) return nativeSet.call(this, k, v);
    origSet(k, v);
    if (isKey(k)) queue(k);
  };
  SP.removeItem = function (k) {
    if (this !== LS) return nativeRemove.call(this, k);
    if (k === 'ipa-session') { origRemove(k); serverLogout(); return; }
    origRemove(k);
    if (isKey(k)) queue(k, true);
  };
  SP.clear = function () {
    if (this !== LS) return nativeClear.call(this);
    var keys = [];
    for (var j = 0; j < LS.length; j++) keys.push(LS.key(j));
    keys.forEach(function (k) { LS.removeItem(k); });
  };

  function queue(k, del) {
    var op = del ? 'del' : 'put';
    Q[k] = op;
    markPending(k, op);
    clearTimeout(T);
    T = setTimeout(flush, 400);
  }

  /* حفظ واحد في كل مرة لكل مفتاح (٢٠٢٦-٠٩-١٤، ثبت بمحرك WebKit وشبكة بطيئة): كان الحفظ التالي يخرج قبل رد السابق
     بنسخته القديمة فيرفضه الخادم (409) ويُقفل النموذج كأن جهازاً آخر كتب. الآن ينتظر الرد ثم يُرسل آخر ما كُتب بالنسخة الجديدة. */
  function flush() {
    BACKOFF = false;
    Object.keys(Q).forEach(function (k) {
      if (INFLIGHT[k]) return;
      var op = Q[k]; delete Q[k];
      var raw = origGet(k);
      if (op === 'put' && raw == null) { clearPending(k); return; }
      var body = op === 'put' ? JSON.stringify({ data: raw, version: VER[k] || 0 }) : null;
      INFLIGHT[k] = true;
      fetch(API + '/' + encodeURIComponent(k), {
        method: op === 'del' ? 'DELETE' : 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body
      }).then(function (r) {
        var entry = { k: k, op: op, status: r.status };
        LOG.push(entry);
        if (r.status === 401 || r.status === 419) { toLogin(); return; }
        if (r.status >= 500) {
          return r.text().then(function (t) {
            try { var j = JSON.parse(t); entry.body = j.error || j.message || t.slice(0, 300); } catch (e) { entry.body = t.slice(0, 300); }
            retry(k, op, 'تعذّر الحفظ في الخادم — سيُعاد');
          });
        }
        if (r.status === 409) {
          return r.json().then(function (j) {
            delete Q[k];
            VER[k] = j.version;
            clearPending(k);
            origSet(k, j.data);
            bar('تغيّرت البيانات من جهاز آخر — أُعيد تحميلها من الخادم', 'warn');
            setTimeout(function () { bar(''); }, 6000);
            window.dispatchEvent(new StorageEvent('storage', { key: k }));
          });
        }
        if (!r.ok) { retry(k, op, 'تعذّر الحفظ في الخادم — سيُعاد'); return; }
        return r.json().then(function (j) {
          if (op === 'del') delete VER[k]; else VER[k] = j.version;
          delete MEM[k];
          /* كتابة أحدث تنتظر هذا الرد: يبقى معلّقها ويُحدَّث رقم نسختها المحفوظ، وإلا يُمسح */
          if (Q[k] === undefined) clearPending(k); else markPending(k, Q[k]);
          bar('');
        });
      }).catch(function () { retry(k, op, 'لا اتصال بالخادم — الحفظ محلي وسيُعاد'); })
        .then(function () {
          delete INFLIGHT[k];
          if (Q[k] !== undefined && !BACKOFF) { clearTimeout(T); T = setTimeout(flush, 0); }
        });
    });
  }
  function retry(k, op, msg) {
    bar(msg, 'bad');
    if (Q[k] === undefined) Q[k] = op;
    BACKOFF = true;
    clearTimeout(T);
    T = setTimeout(flush, 5000);
  }
  /* هل وصل الخادمَ كلُّ ما كُتب؟ للنموذج حتى لا يقول «تم» قبل تأكيد الخادم. only(k) يحصر الحكم في مفاتيح بعينها */
  function busy() { return Object.keys(Q).length > 0 || Object.keys(INFLIGHT).length > 0; }
  function whenIdle(ms, only) {
    var from = LOG.length, t0 = Date.now();
    return new Promise(function (resolve) {
      (function check() {
        if (!busy()) {
          var last = {};
          LOG.slice(from).forEach(function (e) { if (!only || only(e.k)) last[e.k] = e.status; });
          var bad = Object.keys(last).filter(function (k) { return last[k] !== 200; });
          resolve({ ok: bad.length === 0, conflict: bad.some(function (k) { return last[k] === 409; }), pending: false, failed: bad });
          return;
        }
        if (Date.now() - t0 > (ms || 15000)) { resolve({ ok: false, conflict: false, pending: true, failed: [] }); return; }
        setTimeout(check, 200);
      })();
    });
  }

  /* قبل مغادرة الصفحة: محاولة إرسال ما بقي (keepalive). ما لم يصل يبقى في الطابور المحفوظ ويُرسل في الصفحة التالية. */
  window.addEventListener('pagehide', function () {
    clearTimeout(T);
    Object.keys(Q).forEach(function (k) {
      if (INFLIGHT[k]) return; /* حفظه السابق لم يُرد عليه: يبقى معلّقاً ويُرسل عند الفتح التالي */
      var op = Q[k]; delete Q[k];
      var raw = origGet(k);
      if (op === 'put' && raw == null) return;
      var body = op === 'put' ? JSON.stringify({ data: raw, version: VER[k] || 0 }) : null;
      if (body && body.length > 60000) return; /* أكبر من حد keepalive: يبقى معلقاً للصفحة التالية */
      try {
        fetch(API + '/' + encodeURIComponent(k), {
          method: op === 'del' ? 'DELETE' : 'PUT', credentials: 'same-origin', keepalive: true,
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': xsrf() },
          body: body
        }).then(function (r) { if (r.ok) clearPending(k); }).catch(function () {});
      } catch (e) {}
    });
  });

  /* ---------- ٣) الاستطلاع: ما تغيّر من جهاز آخر ---------- */
  var POLLING = false;
  function poll() {
    if (POLLING || !HAVE_SESSION) return;
    POLLING = true;
    fetch(API + '?versions=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (r.status === 401 || r.status === 419) { toLogin(); } return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        var pendingNow = readPending();
        var changed = [];
        Object.keys(j.versions).forEach(function (k) {
          if (Q[k] !== undefined || pendingNow[k]) return;
          if ((VER[k] || 0) !== j.versions[k]) changed.push(k);
        });
        Object.keys(VER).forEach(function (k) { if (!(k in j.versions) && Q[k] === undefined && !pendingNow[k]) changed.push(k); });
        if (!changed.length) return;
        return fetch(API + '?all=1&keys=' + changed.map(encodeURIComponent).join(','), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (res2) {
            if (!res2) return;
            changed.forEach(function (k) {
              if (res2.docs[k] && res2.docs[k].lazy) { VER[k] = res2.docs[k].version; delete MEM[k]; return; }
              if (res2.docs[k]) { VER[k] = res2.docs[k].version; origSet(k, res2.docs[k].data); }
              else { origRemove(k); delete VER[k]; }
              window.dispatchEvent(new StorageEvent('storage', { key: k }));
            });
          });
      })
      .catch(function () {})
      .then(function () { POLLING = false; });
  }
  setInterval(poll, 30000);
  window.addEventListener('focus', poll);

  /* ---------- ٤) الخروج ---------- */
  function serverLogout() {
    var z = new XMLHttpRequest();
    try {
      z.open('POST', '/logout', false);
      z.setRequestHeader('X-XSRF-TOKEN', xsrf());
      z.setRequestHeader('Accept', 'application/json');
      z.send(null);
    } catch (e) {}
  }
})();
