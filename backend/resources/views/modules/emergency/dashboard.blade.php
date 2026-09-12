@extends('layouts.app')
@section('page_title', 'مركز الطوارئ')
@section('content')
@php($role = auth()->user()->role())
@php($P = \App\Core\Permissions\PermissionRegistry::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-exclamation-octagon"></i> مركز الطوارئ</h1>
  <span class="small text-muted">التفعيل ← تنبيه الفريق الأولي ← تسجيل الوصول ← انتهاء الخطر ← التقرير</span>
  @if($mainBuilding && $P::hasPermission($role, 'emergency.trigger'))
    <a class="btn btn-sm btn-danger ms-auto" href="{{ route('emergency.buildings.control', $mainBuilding) }}"><i class="bi bi-bell-fill"></i> تفعيل حالة طارئة</a>
  @endif
</div>

@if($activeIncidents->isNotEmpty())
  @foreach($activeIncidents as $incident)
  <div class="alert alert-{{ $incident->status === 'contained' ? 'warning' : 'danger' }} d-flex align-items-center gap-3 mb-3">
    <i class="bi bi-broadcast fs-3"></i>
    <div class="flex-grow-1">
      <strong>{{ $incident->is_drill ? 'تمرين' : 'حالة طارئة' }} {{ $incident->getStatusLabel() }}:</strong>
      {{ $incident->incident_code }} — {{ $incident->getTypeLabel() }} — {{ $incident->place?->name ?? $incident->building->name }}
      <span class="small text-muted">· بدأت {{ $incident->triggered_at->format('H:i') }} · {{ $incident->getDurationFormatted() }}</span>
    </div>
    <a class="btn btn-light btn-sm" href="{{ route('emergency.incidents.live', $incident) }}"><i class="bi bi-eye"></i> التتبع المباشر</a>
  </div>
  @endforeach
@endif

<div class="row g-2 mb-3">
  @foreach([
    ['حالات مفتوحة', $stats['active_incidents'], $stats['active_incidents'] ? 'danger' : 'success', route('emergency.incidents.index', ['status' => 'active'])],
    ['نداءات هاتفية معلّقة', $pendingCalls, $pendingCalls ? 'warning' : 'secondary', $activeIncidents->first() ? route('emergency.incidents.live', $activeIncidents->first()) : route('emergency.incidents.index')],
    ['تنبيهات ذعر مفتوحة', $panicOpen, $panicOpen ? 'danger' : 'secondary', route('emergency.panic.dashboard')],
    ['تنبيهات أساور مفتوحة', $wearableOpen, $wearableOpen ? 'danger' : 'secondary', route('emergency.iot.wearables.dashboard')],
    ['تمارين قادمة (٣٠ يوماً)', $stats['upcoming_drills'], 'info', route('emergency.drills.index')],
    ['تمارين متأخرة', $stats['overdue_drills'], $stats['overdue_drills'] ? 'warning' : 'secondary', route('emergency.drills.index')],
    ['معدات تحتاج فحصاً', $stats['equipment_needs_inspection'], $stats['equipment_needs_inspection'] ? 'warning' : 'secondary', route('emergency.equipment.index')],
  ] as [$label, $n, $color, $url])
    <div class="col-6 col-md">
      <a class="card text-decoration-none text-dark h-100" style="border-top:3px solid var(--bs-{{ $color }})" href="{{ $url }}">
        <div class="card-body text-center py-2"><div class="fs-4 fw-bold">{{ $n }}</div><div class="small text-muted">{{ $label }}</div></div>
      </a>
    </div>
  @endforeach
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong>جاهزية الأماكن التسعة</strong><span class="small text-muted ms-2">الفريق الأولي من ملف المكان في اللوحة</span><a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.teams.index') }}">الفرق</a></div>
      <div class="table-responsive"><table class="table table-sm m-0 small">
        <thead><tr><th>المكان</th><th>الفريق الأولي</th><th>الأعضاء</th><th>الجاهزية</th><th>خطة الاستجابة</th><th></th></tr></thead>
        <tbody>
        @foreach($places as $place)
          @php($teams = $teamsByPlace->get($place->id, collect()))
          @php($derived = $teams->where('source', 'place_profile'))
          <tr>
            <td class="text-nowrap"><strong>{{ $place->code }}</strong> {{ $place->name }}</td>
            <td>{{ $derived->count() ?: '—' }}{{ $derived->count() > 1 ? ' (إدارة لكل فريق)' : '' }}</td>
            <td>{{ $teams->sum(fn ($t) => $t->members->count()) ?: '—' }}</td>
            <td>
              @if($derived->isEmpty())<span class="badge text-bg-danger">لم يُرشَّح</span>
              @elseif($derived->every(fn ($t) => in_array($t->readiness, ['approved', 'referred'])))<span class="badge text-bg-success">معتمد</span>
              @else<span class="badge text-bg-warning">بانتظار الاعتماد</span>@endif
            </td>
            <td class="small" data-plan="{{ $place->code }}">
              @php($pr = $planReadiness[$place->id] ?? null)
              @if($place->code === 'HZ-00')<span class="text-muted">مركز القيادة — بلا خطة استجابة</span>
              @elseif(!$pr)<span class="badge text-bg-danger">لم تُزامَن من الوثيقة</span>
              @else
                <a href="{{ route('emergency.plans.show', $place->code) }}" class="text-decoration-none">مزامَنة من الوثيقة: {{ $pr['steps'] }} خطوة</a>
                @if($pr['unstaffed'])<br><span class="badge text-bg-warning" title="بطاقات: {{ implode('، ', $pr['unstaffed']) }}">أدوار بلا شاغل: {{ count($pr['unstaffed']) }}</span>@else<br><span class="badge text-bg-success">كل الأدوار لها شاغل</span>@endif
                @if($pr['no_card'])<span class="badge text-bg-light border text-dark" title="خطوات «من» فيها ليس بطاقة">بلا بطاقة: {{ $pr['no_card'] }}</span>@endif
              @endif
            </td>
            <td class="text-nowrap">
              @if($mainBuilding && $P::hasPermission($role, 'emergency.trigger'))
                <a class="btn btn-sm btn-outline-danger" href="{{ route('emergency.buildings.control', ['building' => $mainBuilding, 'place' => $place->code]) }}" title="تفعيل في هذا المكان"><i class="bi bi-bell"></i></a>
              @endif
              <a class="btn btn-sm btn-outline-secondary" href="/dashboard.html#place={{ $place->code }}" title="ملف المكان في اللوحة"><i class="bi bi-folder2-open"></i></a>
            </td>
          </tr>
        @endforeach
        </tbody></table></div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center"><strong>آخر الحالات</strong><a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.incidents.index') }}">الكل</a></div>
      <div class="table-responsive"><table class="table table-sm table-hover m-0 small">
        <thead><tr><th>الرمز</th><th>النوع</th><th>المكان</th><th>الحالة</th><th>بدأت</th><th>المدة</th><th></th></tr></thead>
        <tbody>
        @forelse($recentIncidents as $i)
          <tr>
            <td class="fw-bold">{{ $i->incident_code }}</td>
            <td>{{ $i->getTypeLabel() }}@if($i->is_drill) <span class="badge text-bg-light border">تمرين</span>@endif</td>
            <td>{{ $i->place?->name ?? '—' }}</td>
            <td><span class="badge text-bg-{{ $i->getStatusColor() }}">{{ $i->getStatusLabel() }}</span></td>
            <td class="text-nowrap text-muted">{{ $i->triggered_at->format('m/d H:i') }}</td>
            <td>{{ $i->getDurationFormatted() }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('emergency.incidents.report', $i) }}"><i class="bi bi-file-text"></i></a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-3">لا حالات سابقة</td></tr>
        @endforelse
        </tbody></table></div>
    </div>
  </div>

  <div class="col-lg-5">
    @if($mainBuilding)
    <div class="card mb-3">
      <div class="card-header"><strong>{{ $mainBuilding->name }}</strong></div>
      <div class="card-body small">
        <div class="row g-2 text-center">
          <div class="col-4"><div class="fs-5 fw-bold">{{ $mainBuilding->floors->count() }}</div><div class="text-muted">طوابق</div></div>
          <div class="col-4"><div class="fs-5 fw-bold">{{ $mainBuilding->assemblyPoints->count() }}</div><div class="text-muted">نقاط تجمع</div></div>
          <div class="col-4"><div class="fs-5 fw-bold">{{ $mainBuilding->exits()->count() }}</div><div class="text-muted">مخارج</div></div>
        </div>
        @if($mainBuilding->floors->isEmpty() || $mainBuilding->assemblyPoints->isEmpty())
          <div class="alert alert-warning py-2 mt-2 mb-0">الطوابق والمخارج ونقاط التجمع لم تُدخل بعد (الفجوتان ٣ و٦ في الخطة) — تُدخل من <a href="{{ route('emergency.buildings.show', $mainBuilding) }}">شاشة المبنى</a>.</div>
        @endif
        <div class="mt-2 d-flex gap-1 flex-wrap">
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.buildings.show', $mainBuilding) }}">المبنى</a>
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.contacts.index') }}">جهات الاتصال</a>
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.equipment.index') }}">المعدات</a>
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.analytics.index') }}">المؤشرات</a>
        </div>
      </div>
    </div>
    @endif

    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong>التمارين القادمة</strong>@if($P::hasPermission($role, 'emergency.drill'))<a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.drills.create') }}">جدولة</a>@endif</div>
      <ul class="list-group list-group-flush small">
        @forelse($upcomingDrills as $drill)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>{{ $drill->getTypeLabel() }} — {{ $drill->place?->name ?? $drill->building->name }}</span>
            <span class="badge text-bg-info">{{ $drill->scheduled_at->format('Y-m-d H:i') }}</span>
          </li>
        @empty
          <li class="list-group-item text-muted">لا تمارين مجدولة</li>
        @endforelse
      </ul>
    </div>

    <div class="card">
      <div class="card-header"><strong>مهل التصعيد الآلي</strong></div>
      <ul class="list-group list-group-flush small">
        @foreach($escalationRules as $r)
          <li class="list-group-item d-flex justify-content-between"><span>{{ $r['label'] }}</span><span>{{ $r['minutes'] !== null ? $r['minutes'].' دقيقة' : '—' }}</span></li>
        @endforeach
        @if(collect($escalationRules)->every(fn ($r) => $r['minutes'] === null))
          <li class="list-group-item text-muted">لم تُحدَّد المهل بعد — لا تصعيد آلي حتى تُدخل من <a href="{{ route('emergency.settings') }}">الإعدادات</a> (قرار المستخدم، بلا قيم افتراضية).</li>
        @endif
      </ul>
    </div>
  </div>
</div>
@include('modules.emergency.components.panic-button', ['floating' => true])
@endsection
