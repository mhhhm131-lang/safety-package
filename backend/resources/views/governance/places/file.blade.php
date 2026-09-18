@extends('layouts.app')
@section('title', 'ملف '.$place->name)
@section('content')
{{-- المرحلة ١٩-١ (قرار ٤٨): ملف المكان — ما كان في dashboard.html#place= بالبيانات نفسها، داخل الخلفية. قراءة؛ التحرير في مواضعه. --}}
@php($R = \App\Modules\Store\Services\InspectionDocReader::class)
@php($L = \App\Modules\Governance\Models\PlaceUnit::TYPE_LABELS)
@php($ST = ['ok' => 'st-ok', 'late' => 'st-late', 'fault' => 'st-wait', 'none' => 'text-bg-secondary'])
<style>
  .pf-sys{display:grid;grid-template-columns:1.6fr 1fr 1fr auto auto;gap:6px 12px;align-items:center;padding:10px 12px;border:1px solid var(--bs-border-color);border-radius:10px;background:#fff}
  .pf-sys small{color:#6b7a74;display:block}
  .pf-plan{display:block;text-decoration:none;color:inherit;border:1px solid var(--bs-border-color);border-inline-start:5px solid #adb5bd;border-radius:10px;padding:10px 12px;background:#fff;height:100%}
  .pf-plan.ok{border-inline-start-color:#0f4c3a}.pf-plan.warn{border-inline-start-color:#d9b25a}.pf-plan.bad{border-inline-start-color:#c62828}
  .pf-plan .v{font-weight:700}.pf-plan .d{font-size:.82rem;color:#6b7a74}
  @media(max-width:700px){.pf-sys{grid-template-columns:1fr 1fr}.pf-sys .nm{grid-column:1/-1}
    /* الأرقام الأربعة مربعان في صفين على الجوال — كلها ظاهرة بلا تمرير أفقي */
    #pfKpi.tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible;margin-inline:0;padding-inline:0;gap:.6rem}
    #pfKpi.tiles .tile{min-width:0}}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
  <a class="small" href="{{ route('app.places.units.hub') }}">الأماكن</a><span class="text-muted">›</span>
  <h1 class="page-h m-0">{{ $place->name }}</h1><span class="small text-muted" dir="ltr">{{ $place->code }}</span>
</div>
<p class="small text-muted mb-2" id="pfSum" data-sum-ok="{{ $sum['ok'] }}" data-sum-late="{{ $sum['late'] }}" data-sum-fault="{{ $sum['fault'] }}">
  @if(count($systems))
    <b>{{ $sum['ok'] }}</b> من {{ count($systems) }} نظاماً فُحص في موعده وسليم@if($sum['late']) · <b class="text-danger">{{ $sum['late'] }}</b> فحصه متأخر أو لم يُفحص@endif @if($sum['fault']) · <b style="color:#b8860b">{{ $sum['fault'] }}</b> في آخر فحصه ✗@endif
  @else لم تُفتح جولة لهذا المكان بعد @endif
</p>
<div class="d-flex flex-wrap gap-2 mb-3" id="pfActs">
  @if($ui)@foreach($forms as $f)<a class="btn btn-g btn-sm" href="/{{ $f['file'] }}"><i class="bi bi-clipboard-check"></i> {{ str_contains($f['key'], 'fire') ? 'فحص أنظمة الحريق' : (str_contains($f['key'], 'center') ? 'فحص الجاهزية' : 'نموذج الفحص') }}</a>@endforeach @endif
  <a class="btn btn-o btn-sm" href="{{ route('incident.form', 'normal') }}?place={{ $place->code }}"><i class="bi bi-megaphone"></i> أبلغ عن خطر هنا</a>
  @if($canRisks)<a class="btn btn-o btn-sm" href="{{ route('risk.active.index') }}?place={{ $place->code }}">مخاطر المكان</a>@endif
  @if($canIncidents)<a class="btn btn-o btn-sm" href="{{ route('incidents.index') }}?place={{ $place->code }}">سجل بلاغات المكان</a>@endif
</div>

{{-- الأرقام الأربعة --}}
@php($KL = ['open' => 'بلاغ فحص مفتوح', 'overdue' => 'متجاوز المهلة', 'cat-a' => 'فئة أ — حماية معطّلة', 'closed' => 'عطل أُصلح وأُغلق'])
<div class="tiles mb-3" id="pfKpi">
  @foreach($kpi as $k => $v)
    <div class="card tile" data-kpi="{{ $k }}" data-v="{{ $v }}"><span class="n {{ $v && in_array($k, ['overdue', 'cat-a']) ? 'text-danger' : '' }}">{{ $v }}</span><span class="lbl">{{ $KL[$k] }}</span></div>
  @endforeach
</div>

{{-- الجاهزية: البطاقة تفتح الخطة نفسها --}}
<h2 class="sec-h"><i class="bi bi-shield-check"></i> الجاهزية <span class="small text-muted fw-normal">اضغط الخطة لقراءتها</span></h2>
<div class="row g-2 mb-3" id="pfPlans">
  @foreach($plans as $p)
    <div class="col-md-6"><a class="pf-plan {{ $p['cls'] }}" href="{{ $p['url'] }}"><div class="small text-muted">{{ $p['label'] }} <i class="bi bi-box-arrow-up-left"></i></div><div class="v">{{ $p['v'] }}</div><div class="d">{{ $p['d'] }}</div></a></div>
  @endforeach
</div>

{{-- الوحدات --}}
<h2 class="sec-h"><i class="bi bi-grid-3x3-gap"></i> الوحدات <span class="badge text-bg-dark">{{ $units->count() }}</span>
  @if($canUnits)<a class="btn btn-o btn-sm ms-auto" href="{{ route('app.places.units.index', $place) }}">تعديل الوحدات</a>@endif</h2>
@if($units->isEmpty())
  <div class="card mb-3"><div class="card-body small text-muted py-2">لم تُدخل وحدات هذا المكان بعد.@if($canUnits) <a href="{{ route('app.places.units.index', $place) }}">أدخلها الآن</a>@endif</div></div>
@else
  <div class="d-flex flex-wrap gap-2 mb-3" id="pfUnits">
    @foreach($units as $u)
      <span class="border rounded px-2 py-1 bg-white small"><b>{{ $u->name }}</b> <span class="text-muted">{{ $L[$u->type] ?? $u->type }}{{ $u->floor ? ' · الدور '.$u->floor : '' }}{{ $u->capacity ? ' · '.$u->capacity.' شخصاً' : '' }}{{ $u->operator ? ' · '.$u->operator : '' }}</span></span>
    @endforeach
  </div>
@endif

{{-- الأنظمة --}}
<h2 class="sec-h"><i class="bi bi-cpu"></i> الأنظمة <span class="badge text-bg-dark">{{ count($systems) }}</span></h2>
@if(!count($systems))
  <div class="card mb-3"><div class="card-body small text-muted py-2">لا بيانات — افتح نموذج الفحص وسجّل جولة.</div></div>
@else
  <div class="d-grid gap-2 mb-3" id="pfSystems">
    @foreach($systems as $s)
      {{-- ١٩-٢: الصف يفتح ملف النظام (جولاته وبنوده وبلاغاته) --}}
      <a class="pf-sys text-decoration-none text-reset" data-sys="{{ $s['k'] }}" data-st="{{ $s['st'] }}" href="{{ route('app.places.units.system', [$place, $s['form']['key'], $s['k']]) }}">
        <div class="nm"><b>{{ $s['name'] }}</b><small>{{ $s['code'] ? 'النظام '.$s['code'] : '' }}</small></div>
        <div><small>آخر فحص</small>{{ $s['last']['d'] ?? 'لم يُفحص' }}@if($s['last']) <small>{{ $s['last']['ok'] ?? 0 }}✓ {{ !empty($s['last']['no']) ? $s['last']['no'].'✗' : '' }}</small>@endif</div>
        <div><small>المستحق القادم{{ $s['freq'] ? ' ('.$s['freq'].')' : '' }}</small>{{ $s['next'] ?? ($s['days'] ? 'الآن' : '—') }}</div>
        <span class="badge {{ $s['open'] ? 'text-bg-danger' : 'text-bg-light border' }}" title="بلاغات مفتوحة">{{ $s['open'] }}</span>
        <span class="badge {{ $ST[$s['st']] }}">{{ $R::STATE_LABELS[$s['st']] }}</span>
      </a>
    @endforeach
  </div>
@endif

{{-- بلاغات الفحص المفتوحة --}}
<h2 class="sec-h"><i class="bi bi-clipboard-x"></i> بلاغات الفحص المفتوحة <span class="badge {{ $kpi['overdue'] ? 'text-bg-danger' : 'text-bg-dark' }}">{{ count($open) }}</span></h2>
<div class="d-grid gap-2 mb-3" id="pfReports">
  @forelse($open as $r)
    @php($over = $R::overdueHours($r))
    <div class="card task {{ $over !== null && $over > 0 ? 'task-late' : '' }}"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1"><b>{{ $r['id'] ?? $r['row'] ?? '' }}</b> · {{ $r['sys'] ?? '' }}@if(!empty($r['unit'])) · {{ $r['unit'] }}@endif<div class="small">{{ $r['item'] ?? '' }}</div>
        <div class="small text-muted">@if($over !== null && $over > 0)<span class="badge st-late">متأخر</span> @endif المهلة: {{ $r['due'] ?? '—' }} · عند المستوى {{ $R::holder($r) }}</div></div>
      @if($ui)<a class="btn btn-o btn-sm" href="/{{ $r['_form']['file'] }}#open={{ rawurlencode((string) ($r['row'] ?? '')) }}">افتحه</a>@endif
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2"><i class="bi bi-check-circle text-success"></i> لا بلاغات فحص مفتوحة.</div></div>
  @endforelse
</div>

{{-- بلاغات الشاغلين المفتوحة في المكان --}}
<h2 class="sec-h"><i class="bi bi-megaphone-fill"></i> بلاغات الشاغلين المفتوحة <span class="badge text-bg-dark">{{ $incidents->count() }}</span></h2>
<div class="d-grid gap-2 mb-3" id="pfIncidents">
  @forelse($incidents as $i)
    <div class="card"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1"><b>{{ $i->code }}</b> · {{ $i->title }}@if($i->placeUnit) · {{ $i->placeUnit->type_label }} {{ $i->placeUnit->name }}@endif
        <div class="small text-muted">{{ $i->status_label }} · {{ $i->created_at?->diffForHumans() }}</div></div>
      @if($canIncidents)<a class="btn btn-o btn-sm" href="{{ route('incidents.show', $i) }}">افتحه</a>@endif
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2"><i class="bi bi-check-circle text-success"></i> لا بلاغات شاغلين مفتوحة.</div></div>
  @endforelse
</div>

{{-- الفريق الأولي بهواتفه --}}
<h2 class="sec-h"><i class="bi bi-people-fill"></i> الفريق الأولي <span class="badge text-bg-dark">{{ $teams->count() }}</span></h2>
<div class="d-grid gap-2 mb-3" id="pfTeams">
  @forelse($teams as $t)
    <div class="card"><div class="card-body py-2">
      <div class="fw-bold small mb-1">{{ $t->name }}</div>
      <div class="d-flex flex-wrap gap-2">
        @foreach($t->members as $m)
          <span class="border rounded px-2 py-1 small bg-white">{{ $m->role }}: <b>{{ $m->name }}</b>
            @if($m->phone) <a href="tel:{{ preg_replace('/[^0-9+]/', '', $m->phone) }}" class="text-decoration-none"><i class="bi bi-telephone-fill"></i> {{ $m->phone }}</a>@endif</span>
        @endforeach
      </div>
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2">لا فريق أولي مرشَّح لهذا المكان بعد.</div></div>
  @endforelse
</div>
@endsection
