<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('page_title', 'بلاغ عن خطر') — معهد الإدارة العامة</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
  :root{--g:#0f4c3a;--g2:#166a4f;--red:#9b1c1c;--gold:#b8860b;--ink:#1a2a24;--mut:#6b7a74;--line:#d9e2de;--bg:#f3f6f4}
  body{font-family:Cairo,"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}
  .top{background:var(--g);color:#fff;padding:10px 16px}
  .top a{color:#fff;text-decoration:none}
  .wrap{max-width:760px;margin:18px auto;padding:0 12px}
  .card{border:1px solid var(--line);border-radius:10px}
  .card-h{font-weight:900;color:var(--g);font-size:1.15rem}
  .btn-g{background:var(--g);color:#fff;font-weight:700}.btn-g:hover{background:var(--g2);color:#fff}
  .btn-red{background:var(--red);color:#fff;font-weight:700}.btn-red:hover{background:#7a1414;color:#fff}
  .type-card{display:block;text-decoration:none;color:var(--ink);border:2px solid var(--line);border-radius:12px;padding:16px;height:100%;transition:.15s}
  .type-card:hover{border-color:var(--g);background:#fff}
  .type-card .t{font-weight:900;font-size:1.05rem}
  .type-card.urgent{border-color:#f3c2c2;background:#fff7f7}.type-card.urgent .t{color:var(--red)}
  .type-card.secret .t{color:#4a3d8f}
  .foot{color:var(--mut);font-size:12px;text-align:center;margin:22px 0}
  .foot a{color:var(--g)}
  .code{font-family:monospace;font-size:1.5rem;letter-spacing:2px;font-weight:700;color:var(--g);direction:ltr;display:inline-block}
  .tl{position:relative;padding-inline-start:18px;border-inline-start:2px solid var(--line)}
  .tl .ev{position:relative;margin-bottom:12px}
  .tl .ev:before{content:"";position:absolute;inset-inline-start:-24px;top:6px;width:10px;height:10px;border-radius:50%;background:var(--g)}
  .tl .ev small{color:var(--mut)}
  .badge-st{font-size:.85rem}
  /* المرحلة ١٣-٢ (قرار ٣٩): ذهبي المعهد تحت الشريط، وزر أحمر ثابت أسفل شاشة الجوال يفتح البلاغ من أي صفحة عامة غير صفحته */
  .top{border-bottom:3px solid #d9b25a}.top .bi-shield-check{color:#d9b25a}
  .sos-bar{display:none}
  @media (max-width:767.98px){
    .sos-bar{display:block;position:fixed;bottom:0;inset-inline:0;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:var(--bg);border-top:1px solid var(--line);z-index:1030}
    .sos-bar .btn{width:100%;height:56px;font-size:1.15rem;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:.5rem}
    body.has-sos .wrap{padding-bottom:96px}
    .btn{min-height:44px}
  }
</style>
</head>
@php($showReportBar = !request()->routeIs('incident.landing') && !request()->routeIs('incident.form') && !request()->routeIs('incident.store'))
<body class="{{ $showReportBar ? 'has-sos' : '' }}">
@include('layouts._trial_banner')
<div class="top d-flex align-items-center gap-2">
  <i class="bi bi-shield-check fs-5"></i>
  <div><div class="fw-bold">منظومة السلامة والصحة المهنية</div><div class="small text-white-50">معهد الإدارة العامة — مركز السلامة</div></div>
  <a class="ms-auto small" href="/index.html">المنظومة ←</a>
</div>
<div class="wrap">
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger"><ul class="m-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  @yield('content')
  <div class="foot">حزمة السلامة والصحة المهنية — معهد الإدارة العامة · <a href="/index.html">المنظومة</a> · <a href="/HZ-00-safety-center/reporting-channels.html">قنوات الإبلاغ الأخرى</a> · <a href="{{ route('incident.track') }}">تتبع بلاغ برمزه</a></div>
</div>
@if($showReportBar)
<div class="sos-bar" id="sosBar"><a class="btn btn-red" href="{{ route('incident.landing') }}" data-intent="report"><i class="bi bi-megaphone-fill"></i> أبلّغ عن خطر</a></div>
@endif
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
