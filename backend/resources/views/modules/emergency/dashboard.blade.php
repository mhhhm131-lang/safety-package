@extends('layouts.app')
@section('page_title', 'مركز السلامة وإدارة الطوارئ')
@section('content')
{{-- ٢٦-٧ (قرار ٦٦، بكلمته «انقل كل ما في الطوارئ إلى مركز السلامة» ثم «ابدأ»): صفحة المركز الواحدة — الآن ← الاستعداد ← السجلات والأجهزة ← المركز كمكان.
     كل شاشات الطوارئ لها رابط هنا (قرار ٦١: لا يُخفى شيء)؛ ما ليس للشخص يظهر باهتاً وعليه اسم صاحبه. يفتحها مربع «مركز السلامة» والبحث. --}}
@php
  $role = auth()->user()->role();
  $P = \App\Core\Permissions\PermissionRegistry::class;
  $can = fn (string $perm) => $P::hasPermission($role, $perm);
  /* رابط باب: [العنوان، الرابط، الصلاحية، صاحبها، الأيقونة] — بلا صلاحية يظهر باهتاً باسم صاحبه */
  $door = function (string $label, string $url, ?string $perm, string $who, string $icon = 'bi-arrow-left-circle') use ($can) {
      $ok = $perm === null || $can($perm);
      if (!$ok) return '<span class="btn btn-sm btn-o dim" data-door="'.e($label).'" title="'.e($who).'"><i class="bi '.$icon.'"></i> '.e($label).' <span class="small">— '.e($who).'</span></span>';
      return '<a class="btn btn-sm btn-o" href="'.e($url).'" data-door="'.e($label).'"><i class="bi '.$icon.'"></i> '.e($label).'</a>';
  };
  $I = \App\Modules\Incident\Models\Incident::class;
@endphp
<style>
  .btn-o.dim{opacity:.45;pointer-events:none}
  .c-sec{margin-bottom:1.25rem}
  .c-sec .sec-h{margin-bottom:.5rem}
  .c-doors{display:flex;flex-wrap:wrap;gap:.4rem}
</style>

<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="page-h m-0"><i class="bi bi-exclamation-octagon text-danger"></i> مركز السلامة وإدارة الطوارئ</h1>
  <span class="small text-muted">المناوب: <span dir="ltr">{{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}</span></span>
  @if($mainBuilding && $can('emergency.trigger'))
    <a class="btn btn-danger ms-auto" href="{{ route('emergency.buildings.control', $mainBuilding) }}" data-door="فعّل حالة طارئة"><i class="bi bi-bell-fill"></i> فعّل حالة طارئة</a>
  @endif
</div>

{{-- ١. الآن --}}
<section class="c-sec" id="cNow">
  <h2 class="sec-h sec-h-lg"><i class="bi bi-broadcast"></i> الآن</h2>
  @foreach($activeIncidents as $incident)
  <div class="alert alert-{{ $incident->status === 'contained' ? 'warning' : 'danger' }} d-flex align-items-center gap-3 mb-2" data-open-incident="{{ $incident->incident_code }}">
    <i class="bi bi-broadcast fs-3"></i>
    <div class="flex-grow-1">
      <strong>{{ $incident->is_drill ? 'تمرين' : 'حالة طارئة' }} {{ $incident->getStatusLabel() }}:</strong>
      {{ $incident->incident_code }} — {{ $incident->getTypeLabel() }} — {{ $incident->place?->name ?? $incident->building->name }}
      <span class="small text-muted">· بدأت {{ $incident->triggered_at->format('H:i') }} · {{ $incident->getDurationFormatted() }}</span>
    </div>
    <a class="btn btn-light btn-sm" href="{{ route('emergency.incidents.live', $incident) }}"><i class="bi bi-eye"></i> التتبع</a>
  </div>
  @endforeach
  <div class="row g-2">
    @foreach([
      ['حالات مفتوحة', $stats['active_incidents'], $stats['active_incidents'] ? 'danger' : 'success', route('emergency.incidents.index', ['status' => 'active']), null, 'now-incidents'],
      ['بلاغات شاغلين مفتوحة', $occupantOpen, $occupantOpen ? 'warning' : 'success', route('incidents.index', ['status' => 'open']), 'incident.list', 'now-occupant'],
      ['تنبيهات ذعر مفتوحة', $panicOpen, $panicOpen ? 'danger' : 'secondary', route('emergency.panic.dashboard'), null, 'now-panic'],
      ['تنبيهات أساور مفتوحة', $wearableOpen, $wearableOpen ? 'danger' : 'secondary', route('emergency.iot.wearables.dashboard'), null, 'now-wearable'],
      ['نداءات هاتفية معلّقة', $pendingCalls, $pendingCalls ? 'warning' : 'secondary', $activeIncidents->first() ? route('emergency.incidents.live', $activeIncidents->first()) : route('emergency.incidents.index'), null, 'now-calls'],
    ] as [$label, $n, $color, $url, $perm, $key])
      @php $ok = $perm === null || $can($perm); @endphp
      <div class="col-6 col-md">
        <a class="card text-decoration-none text-dark h-100 {{ $ok ? '' : 'dim' }}" style="border-top:3px solid var(--bs-{{ $color }}){{ $ok ? '' : ';opacity:.45;pointer-events:none' }}" href="{{ $url }}" data-now="{{ $key }}" data-n="{{ $n }}">
          <div class="card-body text-center py-2"><div class="fs-4 fw-bold">{{ $n }}</div><div class="small text-muted">{{ $label }}{{ $ok ? '' : ' — لمركز السلامة' }}</div></div>
        </a>
      </div>
    @endforeach
  </div>
</section>

{{-- ٢. الاستعداد --}}
<section class="c-sec" id="cReady">
  <h2 class="sec-h sec-h-lg"><i class="bi bi-shield-check"></i> الاستعداد</h2>
  <div class="card mb-2">
    <div class="table-responsive"><table class="table table-sm m-0 small align-middle" id="readyTable">
      <thead><tr><th>المكان</th><th>خطة الاستجابة</th><th>آخر تمرين</th><th>الفريق الأولي</th><th>الخطة في النظام</th><th>المعدات</th></tr></thead>
      <tbody>
      @foreach($places as $place)
        @php
          $hz = $place->code; $pl = $profiles[$hz] ?? [];
          $teams = $teamsByPlace->get($place->id, collect()); $derived = $teams->where('source', 'place_profile');
          $eq = $equipByPlace[$place->id] ?? 0;
          $drillDays = !empty($pl['drill']) && ($t = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $pl['drill'], 0, 10))) ? (int) $t->diff(now())->days : null;
        @endphp
        <tr data-place="{{ $hz }}">
          <td class="text-nowrap"><a class="text-decoration-none fw-bold" href="{{ $hz === 'HZ-00' ? '#cPlace' : route('app.places.units.file', $place) }}">{{ $place->name }}</a> <span class="text-muted" dir="ltr">{{ $hz }}</span></td>
          <td>
            @if($hz === 'HZ-00')<span class="text-muted">مركز القيادة</span>
            @elseif(!empty($pl['ra']))<a class="badge text-bg-success text-decoration-none" href="/{{ \App\Modules\Governance\Models\Place::FOLDERS[$hz] ?? $hz }}/response-plan.html">معتمدة {{ $pl['ra'] }}</a>
            @else<a class="badge text-bg-danger text-decoration-none" href="/{{ \App\Modules\Governance\Models\Place::FOLDERS[$hz] ?? $hz }}/response-plan.html">غير معتمدة</a>@endif
          </td>
          <td>
            @if($hz === 'HZ-00')<span class="text-muted">—</span>
            @elseif($drillDays === null)<span class="badge text-bg-warning">لم يُنفَّذ</span>
            @elseif($drillDays > 365)<span class="badge text-bg-warning">قديم · {{ $pl['drill'] }}</span>
            @else<span class="badge text-bg-success">{{ $pl['drill'] }}</span>@endif
          </td>
          <td>
            @if($derived->isEmpty())<span class="badge text-bg-danger">لم يُرشَّح</span>
            @elseif($derived->every(fn ($t) => in_array($t->readiness, ['approved', 'referred'])))<span class="badge text-bg-success">معتمد{{ $derived->count() > 1 ? ' · '.$derived->count().' فرق' : '' }}</span>
            @else<span class="badge text-bg-warning">بانتظار الاعتماد</span>@endif
          </td>
          <td class="small" data-plan="{{ $hz }}">
            {{-- المرحلة ١٠-٤ (ز): الخطة المزامَنة من الوثيقة وأدوارها بلا شاغل --}}
            @php $pr = $planReadiness[$place->id] ?? null; @endphp
            @if($hz === 'HZ-00')<span class="text-muted">مركز القيادة — بلا خطة استجابة</span>
            @elseif(!$pr)<span class="badge text-bg-danger">لم تُزامَن من الوثيقة</span>
            @else
              <a href="{{ route('emergency.plans.show', $hz) }}" class="text-decoration-none">مزامَنة من الوثيقة: {{ $pr['steps'] }} خطوة</a>
              @if($pr['unstaffed'])<br><span class="badge text-bg-warning" title="بطاقات: {{ implode('، ', $pr['unstaffed']) }}">أدوار بلا شاغل: {{ count($pr['unstaffed']) }}</span> <span class="text-muted">بطاقات: {{ implode('، ', $pr['unstaffed']) }}</span>@endif
              @if($pr['no_card'])<span class="badge text-bg-light border text-dark" title="خطوات «من» فيها ليس بطاقة">بلا بطاقة: {{ $pr['no_card'] }}</span>@endif
            @endif
          </td>
          <td>
            @if($eq)<a class="badge text-bg-warning text-decoration-none" href="{{ route('emergency.equipment.index') }}?place={{ $hz }}">{{ $eq }} تحتاج فحصاً</a>
            @else<span class="text-muted">—</span>@endif
          </td>
        </tr>
      @endforeach
      </tbody></table></div>
  </div>
  <div class="c-doors">
    {!! $door('خطط الاستجابة', route('emergency.plans.index'), 'emergency.view', 'لمركز السلامة', 'bi-list-ol') !!}
    {!! $door('الفريق الأولي', route('emergency.teams.index'), 'emergency.view', 'لمركز السلامة', 'bi-people-fill') !!}
    {!! $door('التمارين', route('emergency.drills.index'), 'emergency.view', 'لمركز السلامة', 'bi-calendar-event') !!}
    {!! $door('معدات الطوارئ', route('emergency.equipment.index'), 'emergency.view', 'لمركز السلامة', 'bi-fire') !!}
    {!! $door('جهات الاتصال', route('emergency.contacts.index'), 'emergency.view', 'لمركز السلامة', 'bi-telephone') !!}
    {!! $door('المبنى ومخارجه ونقاط التجمع', $mainBuilding ? route('emergency.buildings.show', $mainBuilding) : route('emergency.buildings.index'), 'emergency.view', 'لمركز السلامة', 'bi-building') !!}
    @if($mainBuilding && ($mainBuilding->floors->isEmpty() || $mainBuilding->assemblyPoints->isEmpty()))
      <span class="badge text-bg-warning align-self-center">الطوابق والمخارج ونقاط التجمع لم تُدخل بعد</span>
    @endif
  </div>
</section>

{{-- ٣. السجلات والأجهزة --}}
<section class="c-sec" id="cRecords">
  <h2 class="sec-h sec-h-lg"><i class="bi bi-journal-text"></i> السجلات والأجهزة</h2>
  <div class="c-doors mb-2">
    {!! $door('سجل الحالات الطارئة', route('emergency.incidents.index'), 'emergency.view', 'لمركز السلامة', 'bi-broadcast') !!}
    {!! $door('سجل بلاغات الشاغلين', route('incidents.index'), 'incident.list', 'لمركز السلامة ومنسقي الإدارات', 'bi-megaphone') !!}
    {!! $door('تقارير ما بعد الحادث', route('emergency.aar.index'), 'emergency.view', 'لمركز السلامة', 'bi-journal-check') !!}
    {!! $door('الزوار', route('emergency.visitors.dashboard'), 'emergency.view', 'لمركز السلامة', 'bi-person-vcard') !!}
    {!! $door('الملفات الطبية', route('emergency.medical.dashboard'), 'medical.read', 'لطبيب العيادة', 'bi-heart-pulse') !!}
    {!! $door('مؤشرات الطوارئ', route('emergency.analytics.index'), 'emergency.view', 'لمركز السلامة', 'bi-graph-up') !!}
  </div>
  <div class="small text-muted mb-1">تنتظر التركيب</div>
  <div class="c-doors">
    {!! $door('أنظمة المبنى', route('emergency.iot.dashboard'), 'emergency.view', 'لمركز السلامة', 'bi-cpu') !!}
    {!! $door('الأساور', route('emergency.iot.wearables.dashboard'), 'emergency.view', 'لمركز السلامة', 'bi-smartwatch') !!}
    {!! $door('الكاميرات', route('emergency.iot.cameras.dashboard'), 'emergency.view', 'لمركز السلامة', 'bi-camera-video') !!}
    {!! $door('الأجهزة الموصولة', route('emergency.iot.devices.index'), 'integration.manage', 'لمسؤول السلامة', 'bi-hdd-network') !!}
  </div>
  <div class="small text-muted mt-2">آخر الحالات:
    @forelse($recentIncidents as $i)<a class="text-decoration-none" href="{{ route('emergency.incidents.report', $i) }}">{{ $i->incident_code }}</a>@if(!$loop->last) · @endif @empty لا حالات سابقة @endforelse
  </div>
</section>

{{-- ٤. المركز كمكان --}}
<section class="c-sec" id="cPlace">
  <h2 class="sec-h sec-h-lg"><i class="bi bi-geo-alt"></i> المركز كمكان <span class="small text-muted fw-normal" dir="ltr">HZ-00</span></h2>
  <div class="row g-2">
    <div class="col-md-4"><div class="card h-100"><div class="card-body py-2" id="pfSystems">
      <div class="fw-bold mb-1">نماذج الفحص</div>
      @forelse($centerFile['forms'] as $f)<a class="btn btn-o btn-sm mb-1" href="/{{ $f['file'] }}"><i class="bi bi-clipboard-check"></i> {{ $f['name'] }}</a>@empty <span class="text-muted small">لا نموذج</span>@endforelse
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body py-2" id="pfReports">
      <div class="fw-bold mb-1">بلاغات الفحص المفتوحة <span class="badge text-bg-dark">{{ count($centerFile['open']) }}</span></div>
      @forelse(array_slice($centerFile['open'], 0, 5) as $r)<div class="small">{{ $r['id'] ?? $r['row'] ?? '' }} · {{ $r['item'] ?? '' }}</div>@empty <span class="text-success small">لا شيء مفتوح</span>@endforelse
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body py-2" id="pfTeams">
      <div class="fw-bold mb-1">فريق المركز</div>
      @forelse($centerFile['members'] as $m)<div class="small">{{ $m['role'] }}: {{ $m['name'] }}@if(!empty($m['phone'])) · <a href="tel:{{ $m['phone'] }}" dir="ltr">{{ $m['phone'] }}</a>@endif</div>@empty <span class="text-muted small">لم يُرشَّح فريق</span>@endforelse
      <a class="small" href="{{ route('app.places.units.file', $centerPlace) }}">ملف المكان كاملاً</a>
    </div></div></div>
  </div>
</section>

<div class="card">
  <div class="card-body small text-muted">مهل التصعيد الآلي:
    @foreach($escalationRules as $r){{ $r['label'] }} {{ $r['minutes'] !== null ? $r['minutes'].' دقيقة' : '—' }}@if(!$loop->last) · @endif @endforeach
    @if($can('emergency.manage')) <a href="{{ route('emergency.settings') }}">تعديل</a>@endif
  </div>
</div>
@endsection
