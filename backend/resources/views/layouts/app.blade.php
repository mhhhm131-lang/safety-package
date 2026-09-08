<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', View::hasSection('page_title') ? trim(View::getSection('page_title')) : 'منظومة السلامة') — معهد الإدارة العامة</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--g:#0f4c3a;--g2:#166a4f;--bg:#f3f6f4;
    /* متغيرات شاشات OHSMS المنقولة (سمة فاتحة بدل الداكنة) */
    --bg-main:#f3f6f4;--bg-card:#ffffff;--bg-dark:#e9efec;--border-color:#d9e2de;--text-main:#1a2a24;--text-muted:#6b7a74;--accent:#0f4c3a;--accent-color:#0f4c3a}
  .btn-accent{background:var(--g);color:#fff}.btn-accent:hover{background:var(--g2);color:#fff}
  .container-fluid .card{background:var(--bg-card)}
  body{font-family:Cairo,"Segoe UI",Tahoma,sans-serif;background:var(--bg);min-height:100vh}
  .topbar{background:var(--g);color:#fff}
  .topbar a{color:#fff;text-decoration:none}
  .topbar .brand{font-weight:700}
  .side a{display:block;padding:8px 12px;border-radius:6px;color:#1a2a24;text-decoration:none}
  .side a.active,.side a:hover{background:#e3ede8;color:var(--g)}
  .side i{margin-inline-end:6px}
  .card{border:1px solid #d9e2de}
  .btn-g{background:var(--g);color:#fff}.btn-g:hover{background:var(--g2);color:#fff}
  .badge-role{background:#e3ede8;color:var(--g);font-weight:600}
  table td,table th{vertical-align:middle}
  .bell{position:relative}.bell .n{position:absolute;top:-6px;inset-inline-start:-8px;background:#c62828;color:#fff;border-radius:10px;font-size:11px;padding:0 6px}
</style>
</head>
<body>
<nav class="topbar px-3 py-2 d-flex align-items-center gap-3">
  <a class="brand" href="{{ route('app.home') }}"><i class="bi bi-shield-check"></i> منظومة السلامة والصحة المهنية</a>
  <span class="text-white-50 small d-none d-md-inline">معهد الإدارة العامة</span>
  <span class="ms-auto"></span>
  <a class="bell" href="{{ route('app.notifications.index') }}" title="الإشعارات"><i class="bi bi-bell fs-5"></i><span class="n" id="bellN" hidden>0</span></a>
  <span class="small">{{ auth()->user()->name }} <span class="text-white-50">· {{ auth()->user()->roleName() }}</span></span>
  <form method="post" action="/logout" class="m-0">@csrf<button class="btn btn-sm btn-outline-light">خروج</button></form>
</nav>

<div class="container-fluid">
  <div class="row">
    <aside class="col-md-2 py-3 side">
      @php($role = auth()->user()->role())
      <a href="{{ route('app.home') }}" class="{{ request()->routeIs('app.home') ? 'active' : '' }}"><i class="bi bi-house"></i>الرئيسية</a>
      @if(\App\Core\Permissions\PermissionRegistry::uiRole($role))
        <a href="/dashboard.html"><i class="bi bi-speedometer2"></i>العمل اليومي</a>
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'system.users'))
        <a href="{{ route('app.users.index') }}" class="{{ request()->routeIs('app.users.*') ? 'active' : '' }}"><i class="bi bi-people"></i>المستخدمون</a>
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'system.org'))
        <a href="{{ route('app.org.index') }}" class="{{ request()->routeIs('app.org.*') ? 'active' : '' }}"><i class="bi bi-diagram-3"></i>الهيكل التنظيمي</a>
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'system.settings'))
        <a href="{{ route('app.places.index') }}" class="{{ request()->routeIs('app.places.*') ? 'active' : '' }}"><i class="bi bi-geo-alt"></i>الأماكن</a>
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'system.audit'))
        <a href="{{ route('app.audit') }}" class="{{ request()->routeIs('app.audit') ? 'active' : '' }}"><i class="bi bi-journal-text"></i>سجل التدقيق</a>
      @endif
      <a href="{{ route('app.notifications.index') }}" class="{{ request()->routeIs('app.notifications.*') ? 'active' : '' }}"><i class="bi bi-bell"></i>الإشعارات</a>
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'incident.list'))
        <hr>
        <div class="small text-muted px-2 mb-1">بلاغات الشاغلين</div>
        <a href="{{ route('incidents.index') }}" class="{{ request()->routeIs('incidents.index') || request()->routeIs('incidents.show') ? 'active' : '' }}"><i class="bi bi-megaphone"></i>سجل مركز السلامة</a>
        <a href="{{ route('incident.landing') }}" target="_blank"><i class="bi bi-box-arrow-up-left"></i>صفحة البلاغ العامة</a>
        @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'system.settings'))
          <a href="{{ route('incidents.settings') }}" class="{{ request()->routeIs('incidents.settings') ? 'active' : '' }}"><i class="bi bi-clock"></i>المهل</a>
        @endif
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'risk.list'))
        <hr>
        <div class="small text-muted px-2 mb-1">المخاطر</div>
        <a href="{{ route('risk.master.index') }}" class="{{ request()->routeIs('risk.master.*') ? 'active' : '' }}"><i class="bi bi-book-half"></i>كتاب المخاطر</a>
        <a href="{{ route('risk.reference.index') }}" class="{{ request()->routeIs('risk.reference.*') ? 'active' : '' }}"><i class="bi bi-bookmark"></i>السجل العام للمعهد</a>
        <a href="{{ route('risk.active.index') }}" class="{{ request()->routeIs('risk.active.*') || request()->routeIs('risk.index') ? 'active' : '' }}"><i class="bi bi-lightning-charge"></i>مخاطر الإدارات والأماكن</a>
        @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'risk.approve'))
          <a href="{{ route('risk.approval.queue') }}" class="{{ request()->routeIs('risk.approval.*') ? 'active' : '' }}"><i class="bi bi-check2-square"></i>اعتماد المخاطر</a>
        @endif
      @endif
      @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.view') || \App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.respond'))
        <hr>
        <div class="small text-muted px-2 mb-1">الطوارئ</div>
        <a href="{{ route('emergency.dashboard') }}" class="{{ request()->routeIs('emergency.dashboard') ? 'active' : '' }}"><i class="bi bi-exclamation-octagon"></i>مركز الطوارئ</a>
        <a href="{{ route('emergency.incidents.index') }}" class="{{ request()->routeIs('emergency.incidents.*') ? 'active' : '' }}"><i class="bi bi-broadcast"></i>الحالات الطارئة</a>
        <a href="{{ route('emergency.teams.index') }}" class="{{ request()->routeIs('emergency.teams.*') ? 'active' : '' }}"><i class="bi bi-people-fill"></i>الفرق</a>
        <a href="{{ route('emergency.drills.index') }}" class="{{ request()->routeIs('emergency.drills.*') ? 'active' : '' }}"><i class="bi bi-calendar-event"></i>التمارين</a>
        <a href="{{ route('emergency.equipment.index') }}" class="{{ request()->routeIs('emergency.equipment.*') ? 'active' : '' }}"><i class="bi bi-fire"></i>معدات الطوارئ</a>
        <a href="{{ route('emergency.contacts.index') }}" class="{{ request()->routeIs('emergency.contacts.*') ? 'active' : '' }}"><i class="bi bi-telephone"></i>جهات الاتصال</a>
        <a href="{{ route('emergency.buildings.index') }}" class="{{ request()->routeIs('emergency.buildings.*') ? 'active' : '' }}"><i class="bi bi-building"></i>المبنى</a>
        <a href="{{ route('emergency.analytics.index') }}" class="{{ request()->routeIs('emergency.analytics.*') ? 'active' : '' }}"><i class="bi bi-graph-up"></i>مؤشرات الطوارئ</a>
        <a href="{{ route('emergency.iot.dashboard') }}" class="{{ request()->routeIs('emergency.iot.dashboard') || request()->routeIs('emergency.iot.events') ? 'active' : '' }}"><i class="bi bi-cpu"></i>أنظمة المبنى</a>
        @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'integration.manage'))
          <a href="{{ route('emergency.iot.devices.index') }}" class="{{ request()->routeIs('emergency.iot.devices.*') ? 'active' : '' }}"><i class="bi bi-hdd-network"></i>الأجهزة الموصولة</a>
        @endif
        @if(\App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.manage'))
          <a href="{{ route('emergency.settings') }}" class="{{ request()->routeIs('emergency.settings') ? 'active' : '' }}"><i class="bi bi-clock-history"></i>مهل التصعيد</a>
        @endif
      @endif
      <hr>
      <a href="/index.html"><i class="bi bi-folder2-open"></i>الوثائق (المنظومة)</a>
    </aside>
    <main class="col-md-10 py-3">
      @if(session('ok'))<div class="alert alert-success py-2">{{ session('ok') }}</div>@endif
      @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
      @if(session('err'))<div class="alert alert-danger py-2">{{ session('err') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif
      @if($errors->any())<div class="alert alert-danger py-2"><ul class="m-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
      @yield('content')
    </main>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  function poll(){fetch('{{ route('app.notifications.count') }}',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.ok?r.json():null).then(j=>{if(!j)return;var b=document.getElementById('bellN');b.textContent=j.count;b.hidden=!j.count;}).catch(()=>{});}
  poll();setInterval(poll,60000);
})();
</script>
@stack('scripts')
</body>
</html>
