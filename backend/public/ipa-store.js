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
 *  - من file:// أو بلا خادم: لا يفعل شيئاً، والصفحة تعمل كما كانت.
 */
(function () {
  'use strict';
  if (location.protocol === 'file:' || !window.localStorage) return;

  var API = '/api/store';
  var PKEY = 'ipa-store-pending';
  var SKIP = { 'ipa-session': 1, 'ipa-store-pending': 1 };
  var LS = window.localStorage;
  var origSet = LS.setItem.bind(LS), origGet = LS.getItem.bind(LS), origRemove = LS.removeItem.bind(LS);
  var VER = {}, Q = {}, T = null, HAVE_SESSION = false, CSRF = '';
  var LOG = [];
  window.ipaStore = { log: LOG, versions: VER, pending: function () { return readPending(); } };
  var isKey = function (k) { return typeof k === 'string' && k.indexOf('ipa-') === 0 && !SKIP[k]; };

  function readPending() { try { return JSON.parse(origGet(PKEY)) || {}; } catch (e) { return {}; } }
  function writePending(p) { try { if (Object.keys(p).length) origSet(PKEY, JSON.stringify(p)); else origRemove(PKEY); } catch (e) {} }
  function markPending(k, op) { var p = readPending(); p[k] = { op: op, version: VER[k] || 0 }; writePending(p); }
  function clearPending(k) { var p = readPending(); if (p[k]) { delete p[k]; writePending(p); } }

  function xsrf() {
    var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  function toLogin() {
    location.replace('/login?next=' + encodeURIComponent(location.pathname + location.search + location.hash));
  }

  /* ---------- شريط الحالة ---------- */
  var BAR = null;
  function bar(msg, kind) {
    if (!msg) { if (BAR) { BAR.remove(); BAR = null; } return; }
    if (!BAR) {
      BAR = document.createElement('div');
      BAR.id = 'ipaStoreBar';
      BAR.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9999;padding:6px 12px;text-align:center;font:700 13px/1.4 Segoe UI,Tahoma,Arial,sans-serif;color:#fff;direction:rtl';
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
  } catch (e) { return; }
  if (x.status === 401 || x.status === 419) { toLogin(); return; }
  if (x.status !== 200) return;
  var res;
  try { res = JSON.parse(x.responseText); } catch (e) { return; }
  HAVE_SESSION = true;
  CSRF = res.csrf || '';

  var cur = null;
  try { cur = JSON.parse(origGet('ipa-session')); } catch (e) {}
  var s = { u: res.session.u, r: res.session.r, n: res.session.n, at: Date.now() };
  if (res.session.d) s.d = res.session.d; else if (cur && cur.d) s.d = cur.d;
  origSet('ipa-session', JSON.stringify(s));

  var docs = res.docs || {};
  var stillPending = readPending();
  Object.keys(docs).forEach(function (k) {
    VER[k] = docs[k].version;
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
  LS.setItem = function (k, v) {
    origSet(k, v);
    if (isKey(k)) queue(k);
  };
  LS.removeItem = function (k) {
    if (k === 'ipa-session') { origRemove(k); serverLogout(); return; }
    origRemove(k);
    if (isKey(k)) queue(k, true);
  };
  LS.clear = function () {
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

  function flush() {
    Object.keys(Q).forEach(function (k) {
      var op = Q[k]; delete Q[k];
      var raw = origGet(k);
      if (op === 'put' && raw == null) { clearPending(k); return; }
      var body = op === 'put' ? JSON.stringify({ data: raw, version: VER[k] || 0 }) : null;
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
          clearPending(k);
          bar('');
        });
      }).catch(function () { retry(k, op, 'لا اتصال بالخادم — الحفظ محلي وسيُعاد'); });
    });
  }
  function retry(k, op, msg) {
    bar(msg, 'bad');
    if (Q[k] === undefined) Q[k] = op;
    clearTimeout(T);
    T = setTimeout(flush, 5000);
  }

  /* قبل مغادرة الصفحة: محاولة إرسال ما بقي (keepalive). ما لم يصل يبقى في الطابور المحفوظ ويُرسل في الصفحة التالية. */
  window.addEventListener('pagehide', function () {
    clearTimeout(T);
    Object.keys(Q).forEach(function (k) {
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
