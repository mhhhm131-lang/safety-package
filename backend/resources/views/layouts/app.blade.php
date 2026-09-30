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
  /* المرحلة ١٣ (قرار ٣٩) — المظهر الموحد: أخضر المعهد وذهبيه؛ الأصفر والأحمر للتنبيه فقط. كل الشاشات تأخذ شكلها من هنا. */
  :root{--g:#0f4c3a;--g2:#166a4f;--gold:#d9b25a;--red:#c62828;--ink:#1a2a24;--mut:#6b7a74;--line:#d9e2de;--bg:#f3f6f4;--tint:#e3ede8;
    /* متغيرات شاشات OHSMS المنقولة (سمة فاتحة بدل الداكنة) */
    --bg-main:#f3f6f4;--bg-card:#ffffff;--bg-dark:#e9efec;--border-color:#d9e2de;--text-main:#1a2a24;--text-muted:#6b7a74;--accent:#0f4c3a;--accent-color:#0f4c3a}
  .btn-accent{background:var(--g);color:#fff}.btn-accent:hover{background:var(--g2);color:#fff}
  .container-fluid .card{background:var(--bg-card)}
  body{font-family:Cairo,"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}
  .text-g{color:var(--g)}
  /* الشريط: أخضر بخط ذهبي */
  .topbar{background:var(--g);color:#fff;border-bottom:3px solid var(--gold)}
  .topbar a{color:#fff;text-decoration:none}
  .topbar a.btn-light{color:var(--g);font-weight:700}
  .topbar .brand{font-weight:900}.topbar .brand i{color:var(--gold)}
  /* ألوان Bootstrap «الأساسية» تصير أخضر المعهد — فتتوحّد الشاشات المنقولة بلا تعديل فيها؛ الأحمر والأصفر والأخضر الفاتح تبقى للحالة */
  :root{--bs-primary:#0f4c3a;--bs-primary-rgb:15,76,58;--bs-info:#d9b25a;--bs-info-rgb:217,178,90;--bs-link-color:#0f4c3a;--bs-link-color-rgb:15,76,58;--bs-link-hover-color:#166a4f;--bs-link-hover-color-rgb:22,106,79}
  .text-primary{color:var(--g)!important}.bg-primary,.text-bg-primary{background-color:var(--g)!important}
  .btn-info{--bs-btn-bg:var(--tint);--bs-btn-border-color:#c7d3cd;--bs-btn-color:var(--g);--bs-btn-hover-bg:#d3e2da;--bs-btn-hover-border-color:#b5c6bd;--bs-btn-hover-color:var(--g)}
  .btn-success{--bs-btn-bg:var(--g);--bs-btn-border-color:var(--g);--bs-btn-hover-bg:var(--g2);--bs-btn-hover-border-color:var(--g2);--bs-btn-active-bg:var(--g2);--bs-btn-active-border-color:var(--g2)}
  .btn-outline-success{--bs-btn-color:var(--g);--bs-btn-border-color:var(--g);--bs-btn-hover-bg:var(--g);--bs-btn-hover-border-color:var(--g);--bs-btn-active-bg:var(--g);--bs-btn-active-border-color:var(--g)}
  .bg-info,.text-bg-info{background-color:var(--gold)!important;color:var(--ink)!important}.text-info{color:#8a6d1d!important}
  .border-info{border-color:var(--gold)!important}.border-primary{border-color:var(--g)!important}.border-success{border-color:var(--g)!important}
  .list-group-item.active{background:var(--g);border-color:var(--g)}
  .page-link{color:var(--g)}.active>.page-link,.page-link.active{background:var(--g);border-color:var(--g)}
  .side a{display:block;padding:8px 12px;border-radius:6px;color:var(--ink);text-decoration:none}
  .side a.active,.side a:hover{background:var(--tint);color:var(--g)}
  .side i{margin-inline-end:6px}
  /* بطاقة واحدة، زر واحد، عنوان واحد، شارة حالة بأربعة ألوان */
  .card{border:1px solid var(--line);border-radius:10px}
  .btn-g{background:var(--g);color:#fff;font-weight:700}.btn-g:hover,.btn-g:focus{background:var(--g2);color:#fff}
  .btn-o{background:#fff;color:var(--ink);border:1px solid #c7d3cd;font-weight:600}.btn-o:hover{background:var(--tint);color:var(--g)}
  .btn-red{background:var(--red);color:#fff;font-weight:700}.btn-red:hover{background:#a02020;color:#fff}
  .btn-primary{--bs-btn-bg:var(--g);--bs-btn-border-color:var(--g);--bs-btn-hover-bg:var(--g2);--bs-btn-hover-border-color:var(--g2);--bs-btn-active-bg:var(--g2);--bs-btn-active-border-color:var(--g2)}
  .btn-outline-primary{--bs-btn-color:var(--g);--bs-btn-border-color:var(--g);--bs-btn-hover-bg:var(--g);--bs-btn-hover-border-color:var(--g);--bs-btn-active-bg:var(--g);--bs-btn-active-border-color:var(--g)}
  .page-h{font-size:1.35rem;font-weight:900;margin-bottom:.75rem}
  h1.h4,h1.h5,h1.h3{font-weight:900}
  .sec-h{font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem}
  .sec-h>i{color:var(--gold)}.sec-h-lg{font-size:1.2rem}
  .st-done{background:var(--tint);color:var(--g)}.st-going{background:var(--gold);color:var(--ink)}.st-wait{background:#fff3cd;color:#7a5a00}.st-late{background:var(--red);color:#fff}
  .badge-role{background:var(--tint);color:var(--g);font-weight:600}
  .progress-bar{background:var(--g)}
  .nav-tabs .nav-link.active,.nav-pills .nav-link.active{color:var(--g);font-weight:700}.nav-pills .nav-link.active{background:var(--g);color:#fff}
  .form-control:focus,.form-select:focus{border-color:var(--g2);box-shadow:0 0 0 .2rem rgba(15,76,58,.15)}
  .table thead th{color:var(--mut);font-weight:600;font-size:.85rem;border-bottom-width:1px}
  table td,table th{vertical-align:middle}
  .bell{position:relative}.bell .n{position:absolute;top:-6px;inset-inline-start:-8px;background:var(--red);color:#fff;border-radius:10px;font-size:11px;padding:0 6px}
  /* الشاشة الأولى: الأرقام الكبيرة والرسم والخريطة والمهام وآخر الإجراءات */
  .tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
  .tile{display:flex;flex-direction:column;gap:2px;padding:14px 16px;border-top:3px solid var(--gold);color:var(--ink);text-decoration:none}
  .tile:hover{border-color:var(--gold);box-shadow:0 2px 8px rgba(15,76,58,.12);color:var(--ink)}
  .tile .lbl{color:var(--mut);font-size:.8rem}.tile .n{font-size:2.4rem;font-weight:900;line-height:1.15;color:var(--g)}.tile .n small{font-size:1rem;font-weight:700}
  /* ٢٥-١ (قرار ٦٤): مربعات الأماكن في الصفحة الأولى — تُضغط وتفتح ملف المكان، ولونها السفلي من حال الفحص */
  .pl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
  @media(max-width:575.98px){.pl-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  .pl-tile{display:flex;flex-direction:column;gap:2px;text-decoration:none;color:var(--ink);border:1px solid var(--line);border-bottom:5px solid #adb5bd;border-radius:10px;padding:8px 10px;background:#fff;min-height:84px}
  .pl-tile:hover{color:var(--ink);box-shadow:0 2px 8px rgba(15,76,58,.12)}
  .pl-tile.calm{border-bottom-color:var(--g)}.pl-tile.busy{border-bottom-color:var(--gold)}.pl-tile.late{border-bottom-color:var(--red)}.pl-tile.none{background:#f8f9fa}
  .pl-tile .nm{font-weight:700;font-size:.9rem;line-height:1.25}.pl-tile .st{line-height:1.3}
  .pl-tile.on{outline:2px solid var(--g);outline-offset:1px}
  /* ٢٥-٢: الرسم «حال الآن» — ستة أعمدة تُضغط */
  .bars{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:4px;align-items:stretch}
  .bar{display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:var(--ink);border-radius:8px;padding:4px 1px;min-width:0}
  .bar[href]:hover{background:var(--tint);color:var(--ink)}
  .bar .col{flex:1 1 auto;width:100%;min-height:130px;display:flex;align-items:flex-end;justify-content:center}
  .bar .fill{width:70%;max-width:44px;background:var(--g);border-radius:6px 6px 0 0;transition:height .3s;min-height:3px}
  .bar.zero .fill{background:var(--line)}.bar.warn .fill{background:var(--red)}
  .bar .n{font-weight:900;font-size:1.25rem;line-height:1.1}.bar.zero .n{color:var(--mut)}.bar.warn .n{color:var(--red)}
  .bar .lbl{font-size:.68rem;text-align:center;color:var(--mut);line-height:1.15;white-space:normal;overflow-wrap:anywhere;min-height:2.3em}
  @media(max-width:575.98px){.bar .col{min-height:100px}.bar .n{font-size:1.05rem}.bar .lbl{font-size:.62rem}}
  .task-late{border-color:var(--red)}
  /* ٢٥-٣-ب: «ما ينتظرك» منسدلة — سطر لكل مجموعة: أيقونة برقم (أحمر إن فيها متأخر)، وتُفتح بالنقر */
  .grp-h{display:flex;align-items:center;gap:.6rem;width:100%;background:#fff;border:1px solid var(--line);border-radius:10px;padding:.55rem .8rem;text-align:start;color:var(--ink);cursor:pointer;font-weight:700}
  .grp-h:hover{border-color:var(--g);color:var(--g)}.grp-h:not(.collapsed){border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom-color:transparent}
  .grp-ic{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;background:var(--tint);color:var(--g);font-size:1.1rem}
  .grp-ic .grp-n{position:absolute;top:-7px;inset-inline-start:-8px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:var(--g);color:#fff;font-size:.72rem;font-weight:900;display:inline-flex;align-items:center;justify-content:center}
  .grp-ic.late{background:#fde8e8;color:var(--red)}.grp-ic.late .grp-n{background:var(--red)}
  .grp-od{color:var(--red);font-size:.8rem;font-weight:600}
  .grp-h .grp-caret{transition:transform .2s;color:var(--mut)}.grp-h:not(.collapsed) .grp-caret{transform:rotate(180deg)}
  #inboxList .collapse>.d-grid{border:1px solid var(--line);border-top:0;border-radius:0 0 10px 10px;padding:.6rem;background:#fbfcfb}
  .task-actions{display:flex;gap:.5rem;flex-wrap:wrap}
  /* ٢٦-١١: الدفعة — بطاقة واحدة للمتطابق، وبنودها أسطر تحتها تُفتح بزرها */
  .batch-row{padding:.6rem 1rem;border-top:1px solid var(--line);background:#fbfcfb}.batch-row:last-child{border-radius:0 0 10px 10px}
  .task-actions .btn[data-bs-toggle="collapse"] .bi-chevron-down{display:inline-block;transition:transform .2s}.task-actions .btn[data-bs-toggle="collapse"]:not(.collapsed) .bi-chevron-down{transform:rotate(180deg)}
  .row-act{display:flex;gap:.75rem;padding:.5rem 0;border-bottom:1px solid #edf1ef;color:var(--ink);text-decoration:none;font-size:.95rem}
  .row-act:last-child{border-bottom:0}.row-act:hover{color:var(--g)}.row-act .when{color:var(--mut);min-width:3.5rem;font-size:.85rem}
  /* الجوال: نسخة واحدة تتشكل بحسب العرض — أرقام تُمرَّر، أزرار بعرض الشاشة، وزر أحمر ثابت أسفل الشاشة */
  /* ٢٦-٣-ب: الزر الأحمر «طوارئ الآن» في كل شاشة على الحاسب أيضاً — زر ثابت في زاوية الشاشة؛ وعلى الجوال شريط بعرض الشاشة */
  .sos-bar{display:block;position:fixed;bottom:18px;inset-inline-start:18px;z-index:1030}
  .sos-bar .btn{height:52px;padding:0 22px;font-size:1.05rem;font-weight:900;border-radius:14px;display:flex;align-items:center;gap:.5rem;box-shadow:0 4px 14px rgba(198,40,40,.35)}
  @media (max-width:767.98px){
    #navSearch{order:9;flex:1 1 100%}#navSearch input{max-width:none!important}
    .topbar .ms-auto{display:none}
    .tiles{display:flex;overflow-x:auto;gap:.6rem;padding-bottom:.25rem;margin-inline:-.5rem;padding-inline:.5rem;scroll-snap-type:x mandatory}
    .tile{min-width:150px;scroll-snap-align:start}.tile .n{font-size:2rem}
    .task-actions{width:100%}.task-actions>*,.task-actions .btn{flex:1 1 0}.task-actions form .btn{width:100%}
    .btn{min-height:44px}
    .sos-bar{display:block;position:fixed;bottom:0;inset-inline:0;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:var(--bg);border-top:1px solid var(--line);z-index:1030}
    .sos-bar .btn{width:100%;height:56px;font-size:1.15rem;font-weight:900;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:.5rem}
    body.has-sos main{padding-bottom:96px!important}
    body.has-sos .panic-button-floating{bottom:100px}
  }
</style>
</head>
@php
  /* المرحلة ١٣-٢: الزر الأحمر الثابت على الجوال — نية الطوارئ القائمة (فعّل حالة طارئة / أستغيث الآن) لمن يملكها؛ لا نية جديدة */
  $allIntents = isset($intents) ? $intents : \App\Core\Intents\IntentRegistry::forUser(auth()->user());
  $myEmergency = $allIntents->first(fn ($i) => $i->key === 'my_emergency');
  /* الزر الأحمر الثابت بأولوية واحدة (٢٢-٢ و٢٢-٣): القيادة تبقى على «فعّل»؛ ومن دونها
     تأخذ شاشتَها وقت حالة مفتوحة تخصّها، وإلا «أستغيث الآن». */
  $sosIntent = null;
  /* ٢٦-٣ (قرار ٦٦): زر أحمر واحد «طوارئ الآن» للجميع — التفعيل داخل صفحة الاستغاثة لمن يملكه، لا في الشريط */
  foreach (['my_emergency', 'sos'] as $k) {
      $sosIntent = $allIntents->first(fn ($i) => $i->key === $k);
      if ($sosIntent) break;
  }
  /* ٢٦-٩ (قرار ٦٦): «المزيد» للإعدادات فقط — ما يُضبط مرة. من لا إعداد له لا زر عنده ولا قائمة.
     كل سطر: [الاسم، الأيقونة، الرابط، نمط المسار الفعّال] */
  $moreRole = auth()->user()->role();
  $moreCan = fn (string $p) => \App\Core\Permissions\PermissionRegistry::hasPermission($moreRole, $p);
  $moreLinks = array_values(array_filter([
      $moreCan('system.settings') ? ['الإعدادات', 'bi-sliders', route('app.settings'), 'app.settings'] : null,
      $moreCan('system.users') ? ['المستخدمون', 'bi-people', route('app.users.index'), 'app.users.*'] : null,
      $moreCan('system.org') ? ['الهيكل التنظيمي', 'bi-diagram-3', route('app.org.index'), 'app.org.*'] : null,
      $moreCan('system.settings') ? ['الأماكن', 'bi-geo-alt', route('app.places.index'), 'app.places.*'] : null,
      $moreCan('system.audit') ? ['سجل التدقيق', 'bi-journal-text', route('app.audit'), 'app.audit'] : null,
      $moreCan('system.settings') ? ['البريد', 'bi-envelope-at', route('app.mail.index'), 'app.mail.*'] : null,
      $moreCan('system.settings') ? ['الإغلاق والتسليم', 'bi-box-seam', route('app.closeout.index'), 'app.closeout.*'] : null,
      $moreCan('system.settings') ? ['مهل البلاغات', 'bi-clock', route('incidents.settings'), 'incidents.settings'] : null,
      $moreCan('emergency.manage') ? ['مهل التصعيد', 'bi-clock-history', route('emergency.settings'), 'emergency.settings'] : null,
  ]));
@endphp
<body class="{{ $sosIntent ? 'has-sos' : '' }}">
@include('layouts._trial_banner')
{{-- المرحلة ١١-٤ (قرار ٣٤): ثلاثة أبواب في الشريط — ما ينتظرك · بحث · المزيد. القائمة كلها خلف «المزيد» ولا تتكدس فوق المحتوى --}}
{{-- ٢٦-٩ (قرار ٦٦): «المزيد» للإعدادات فقط، ويظهر لمن له إعداد --}}
<nav class="topbar px-3 py-2 d-flex align-items-center gap-2 flex-wrap">
  <a class="brand" href="{{ route('app.home') }}"><i class="bi bi-shield-check"></i> <span class="d-none d-sm-inline">منظومة السلامة</span></a>
  <a class="btn btn-sm {{ request()->routeIs('app.home') ? 'btn-light' : 'btn-outline-light' }}" href="{{ route('app.home') }}" id="navInbox"><i class="bi bi-inbox-fill"></i> ما ينتظرك <span class="badge text-bg-danger" id="inboxN" hidden>0</span></a>
  <form method="get" action="{{ route('app.search') }}" class="m-0 d-flex" role="search" id="navSearch">
    <input name="q" class="form-control form-control-sm" placeholder="بحث…" value="{{ request()->routeIs('app.search') ? request('q') : '' }}" style="max-width:180px" aria-label="بحث">
  </form>
  @if($moreLinks)
  <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#moreNav" id="navMore"><i class="bi bi-list"></i> المزيد</button>
  @endif
  <span class="ms-auto"></span>
  <a class="bell" href="{{ route('app.notifications.index') }}" title="الإشعارات"><i class="bi bi-bell fs-5"></i><span class="n" id="bellN" hidden>0</span></a>
  <span class="small d-none d-md-inline">{{ auth()->user()->name }} <span class="text-white-50">· {{ auth()->user()->roleName() }}</span></span>
  <form method="post" action="/logout" class="m-0">@csrf<button class="btn btn-sm btn-outline-light">خروج</button></form>
</nav>

<div class="container-fluid">
  <div class="row">
    @if($moreLinks)
    <aside class="offcanvas offcanvas-end side" tabindex="-1" id="moreNav" aria-labelledby="moreNavTitle">
      <div class="offcanvas-header"><h5 class="offcanvas-title" id="moreNavTitle">المزيد</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button></div>
      <div class="offcanvas-body py-2">
      @foreach($moreLinks as [$moreLabel, $moreIcon, $moreUrl, $morePattern])
        <a href="{{ $moreUrl }}" class="{{ request()->routeIs($morePattern) ? 'active' : '' }}"><i class="bi {{ $moreIcon }}"></i>{{ $moreLabel }}</a>
      @endforeach
      {{-- ١٩-٧ (قرار ٤٨) ثم ٢٥-١ (قرار ٦٤): «الأماكن وملفاتها» خرجت من القائمة — الأماكن في الصفحة الأولى لكل حساب --}}
      {{-- ٢٦-٧ (قرار ٦٦): الطوارئ (٢٠ رابطاً) في صفحة المركز الواحدة؛ بقيت هنا المهلتان لأنهما إعداد --}}
      {{-- ٢٦-٩ (قرار ٦٦): ما ليس إعداداً خرج من هنا وله بابه — الإشعارات (الجرس)، التقارير (الصفحة الأولى)، السجلات والنماذج والمقاولون («أريد أن»)،
           سجل التصاريح (عمود الرسم) ولوحته وطابوره ومعداته وسعته (من داخله)، مخاطر الإدارات والأماكن (ملف المكان) واعتمادها (من داخلها)،
           قنوات التحقق (صفحة الإعدادات وملف المقاول)، الوثائق («خطة مكاني» وصفحة الدخول) --}}
      </div>
    </aside>
    @endif
    <main class="col-12 py-3" style="max-width:1100px;margin:0 auto">
      {{-- ٢٢-٢ (د): حالة مفتوحة تخصّ صاحب الحساب — شريط ظاهر في كل صفحة حتى يجدها بلا بحث --}}
      @if($myEmergency && !request()->routeIs('emergency.me'))
        <a href="{{ $myEmergency->url }}" id="myEmergencyBanner" class="alert alert-danger d-flex align-items-center gap-2 py-2 text-decoration-none" style="border-width:2px">
          <i class="bi bi-exclamation-octagon-fill fs-4"></i>
          <span class="fw-bold">{{ $myEmergency->label }}</span>
          <span class="ms-auto small">افتح <i class="bi bi-chevron-left"></i></span>
        </a>
      @endif
      @if(session('ok'))<div class="alert alert-success py-2">{{ session('ok') }}</div>@endif
      @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
      @if(session('err'))<div class="alert alert-danger py-2">{{ session('err') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif
      @if($errors->any())<div class="alert alert-danger py-2"><ul class="m-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
      @yield('content')
    </main>
  </div>
</div>
@if($sosIntent)
<div class="sos-bar" id="sosBar"><a class="btn btn-red" href="{{ $sosIntent->url }}" data-intent="{{ $sosIntent->key }}"><i class="bi {{ $sosIntent->icon }}"></i> {{ $sosIntent->label }}</a></div>
@endif
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  function poll(){fetch('{{ route('app.notifications.count') }}',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.ok?r.json():null).then(j=>{if(!j)return;var b=document.getElementById('bellN');b.textContent=j.count;b.hidden=!j.count;}).catch(()=>{});
    fetch('{{ route('app.inbox.count') }}',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.ok?r.json():null).then(j=>{if(!j)return;var b=document.getElementById('inboxN');if(b){b.textContent=j.count;b.hidden=!j.count;}
      /* ٢٢-١٤: حالة فُتحت وأنت على الصفحة ← الشريط الأحمر يظهر بلا إعادة تحميل (ليس على شاشتك نفسها) */
      if(j.emergency&&!document.getElementById('myEmergencyBanner')&&{{ request()->routeIs('emergency.me') ? 'false' : 'true' }}){var a=document.createElement('a');a.id='myEmergencyBanner';a.href=j.emergency.url;a.className='alert alert-danger d-flex align-items-center gap-2 py-2 text-decoration-none';a.style.borderWidth='2px';
        var i=document.createElement('i');i.className='bi bi-exclamation-octagon-fill fs-4';var s=document.createElement('span');s.className='fw-bold';s.textContent=j.emergency.label;var o=document.createElement('span');o.className='ms-auto small';o.textContent='افتح';
        a.append(i,s,o);var m=document.querySelector('main');if(m)m.prepend(a);}
    }).catch(()=>{});}
  poll();setInterval(poll,60000);
})();
</script>
@stack('scripts')
</body>
</html>
