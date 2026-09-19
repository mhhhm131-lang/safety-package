<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>انتقل العمل اليومي</title>
{{-- المرحلة ١٩-٧ (قرار ٤٨): الرابط القديم للوحة يصل مقابله في الخلفية. ملف المكان: /app/places/{id}/file، وملف النظام: …/systems/{form}/{sys} --}}
<noscript><meta http-equiv="refresh" content="0;url=/app"></noscript>
<style>body{font-family:system-ui,sans-serif;background:#f3f5f4;color:#0f4c3a;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:16px}a{color:#0f4c3a;font-weight:700}</style>
</head>
<body>
<p>انتقل «العمل اليومي» إلى المنظومة. <a href="/app">افتح «ما ينتظرك»</a></p>
<script>
(function(){
  var M = @json($map), q = {}, h = location.hash.replace(/^#/, '');
  h.split('&').forEach(function(p){ var i = p.indexOf('='); if (i > 0) q[decodeURIComponent(p.slice(0, i))] = decodeURIComponent(p.slice(i + 1)); });
  var to = M.home, file = q.place && M.places[String(q.place).toUpperCase()];
  if (file) {
    to = file;
    var s = (q.sys || '').split(':');
    if (s.length === 2 && /^[a-z0-9-]+$/i.test(s[0]) && /^[a-z0-9_-]+$/i.test(s[1])) to = file.replace(/\/file$/, '/systems/' + s[0] + '/' + s[1]);
  } else if ((h === 'depts' || 'depts' in q) && M.depts) to = M.depts;
  location.replace(to);
})();
</script>
</body>
</html>
