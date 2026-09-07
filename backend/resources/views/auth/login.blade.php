<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تسجيل الدخول — منظومة السلامة والصحة المهنية</title>
<style>
  :root{--g:#0f4c3a;--g2:#166a4f;--ink:#1a2a24;--mut:#6b7a74;--line:#d9e2de;--bg:#f3f6f4;--bad:#9b1c1c}
  *{box-sizing:border-box}
  body{margin:0;font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{background:#fff;border:1px solid var(--line);border-radius:6px;width:100%;max-width:400px;padding:28px 26px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
  .brand{color:var(--g);font-weight:800;font-size:18px;margin:0 0 4px}
  .sub{color:var(--mut);font-size:13px;margin:0 0 22px}
  label{display:block;font-size:13px;font-weight:700;margin:12px 0 6px}
  input{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:4px;font-size:15px;font-family:inherit}
  input:focus{outline:2px solid var(--g2);border-color:var(--g2)}
  button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:4px;background:var(--g);color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
  button:hover{background:var(--g2)}
  .err{background:#fdecec;color:var(--bad);border:1px solid #f3c2c2;padding:8px 10px;border-radius:4px;font-size:13px;margin-top:10px}
  .foot{color:var(--mut);font-size:12px;margin-top:18px;text-align:center}
  .foot a{color:var(--g)}
</style>
</head>
<body>
<form class="card" method="post" action="/login" autocomplete="on">
  @csrf
  <input type="hidden" name="next" value="{{ $next }}">
  <h1 class="brand">منظومة السلامة والصحة المهنية</h1>
  <p class="sub">معهد الإدارة العامة — العمل اليومي خلف تسجيل الدخول</p>

  <label for="username">اسم المستخدم</label>
  <input id="username" name="username" value="{{ old('username') }}" autofocus autocapitalize="none" autocomplete="username" required>

  <label for="password">كلمة المرور</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>

  @error('username')
    <div class="err">{{ $message }}</div>
  @enderror

  <button type="submit">دخول</button>
  <div class="foot"><a href="/index.html">← المنظومة (الوثائق مفتوحة للجميع)</a></div>
</form>
</body>
</html>
