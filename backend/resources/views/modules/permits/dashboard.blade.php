@extends('layouts.app')
@section('page_title', 'لوحة التصاريح')
@section('content')
@php
  $sum = [];
  foreach ($counts as $byStatus) { foreach ($byStatus as $st => $n) { $sum[$st] = ($sum[$st] ?? 0) + $n; } }
  $active = $sum['active'] ?? 0;
  $pending = ($sum['submitted'] ?? 0) + ($sum['under_review'] ?? 0) + ($sum['safety_approved'] ?? 0);
@endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-speedometer2"></i> لوحة التصاريح</h1>
  <div class="ms-auto d-flex gap-2">
    <a href="{{ route('permits.report') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-bar-graph"></i> التقرير الشهري</a>
    @if(\App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'permit.zones'))
      <a href="{{ route('permits.settings') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-sliders"></i> السعة والتعارض</a>
    @endif
    <a href="{{ route('permits.index') }}" class="btn btn-sm btn-g">كل التصاريح</a>
  </div>
</div>

{{-- المؤشرات --}}
<div class="row g-2 mb-4">
  @foreach([
    ['نشط الآن', $active, 'bi-play-circle-fill', 'success', route('permits.index', ['status' => 'active'])],
    ['ينتظر قراراً', $pending, 'bi-hourglass-split', 'warning', route('permits.queue')],
    ['ينتهي خلال ٣٠ يوماً', count($expiring), 'bi-alarm', 'danger', route('permits.queue')],
    ['انحرافات مفتوحة', $deviations['open'], 'bi-exclamation-diamond', 'warning', route('permits.index', ['status' => 'active'])],
    ['فحص جاهزية اليوم', $gate['total_today'], 'bi-person-check', 'primary', route('permits.gate.logs')],
    ['معدات تجاوزت الفحص', $equipment['overdue_inspection'], 'bi-truck', 'danger', route('equipment.index', ['overdue' => 1])],
  ] as [$label, $value, $icon, $color, $url])
    <div class="col-6 col-md-2">
      <a href="{{ $url }}" class="text-decoration-none">
        <div class="card h-100 border-top border-3 border-{{ $color }}">
          <div class="card-body text-center py-2">
            <i class="bi {{ $icon }} text-{{ $color }}"></i>
            <div class="h4 m-0">{{ $value }}</div>
            <small class="text-muted">{{ $label }}</small>
          </div>
        </div>
      </a>
    </div>
  @endforeach
</div>

<div class="row g-3">
  {{-- سعة الأماكن --}}
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-geo-alt"></i> إشغال الأماكن الآن</div>
      <div class="card-body">
        @foreach($places as $p)
          <div class="mb-2" data-place="{{ $p['code'] }}">
            <div class="d-flex justify-content-between small">
              <span>{{ $p['name'] }} <span class="text-muted" dir="ltr">{{ $p['code'] }}</span></span>
              <span class="text-muted">
                {{ $p['active_permits'] }} تصريحاً ·
                {{ $p['active_workers'] }}@if($p['max_workers'])/{{ $p['max_workers'] }}@endif عاملاً
              </span>
            </div>
            @if($p['max_workers'])
              @php($pct = min(100, $p['workers_pct'] ?? 0))
              <div class="progress" style="height:5px">
                <div class="progress-bar bg-{{ $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success') }}" style="width:{{ $pct }}%"></div>
              </div>
            @endif
          </div>
        @endforeach
        <div class="form-text mt-2">الأماكن بلا حد مُدخَل لا تُفحص سعتها. الحدود تُدخل من شاشة السعة.</div>
      </div>
    </div>
  </div>

  {{-- التعارضات --}}
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-sign-stop text-danger"></i> تعارضات قائمة
        <span class="badge bg-danger ms-1" data-conflicts="{{ count($conflicts) }}">{{ count($conflicts) }}</span>
      </div>
      <div class="card-body p-0">
        @forelse($conflicts as $c)
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom small">
            <span class="badge {{ $c['severity'] === 'block' ? 'bg-danger' : 'bg-warning text-dark' }}">
              {{ $c['severity'] === 'block' ? 'مانع' : 'تنبيه' }}
            </span>
            <div>
              <span dir="ltr">{{ $c['permit_a'] }}</span> <i class="bi bi-x text-danger"></i> <span dir="ltr">{{ $c['permit_b'] }}</span>
              <div class="text-muted"><i class="bi bi-geo-alt"></i> {{ $c['place'] }} @if($c['reason'])· {{ $c['reason'] }}@endif</div>
            </div>
          </div>
        @empty
          <div class="text-center text-muted py-4 small"><i class="bi bi-check-circle-fill text-success d-block fs-4 mb-1"></i>لا تعارض بين التصاريح القائمة.</div>
        @endforelse
      </div>
    </div>
  </div>

  {{-- طابور المراجعة --}}
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-inbox"></i> ينتظر قرارك</span>
        <a href="{{ route('permits.queue') }}" class="small ms-auto">الكل</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>الرمز</th><th>العنوان</th><th>الحالة</th><th>منذ</th></tr></thead>
          <tbody>
            @forelse($queue as $row)
              <tr class="{{ $row['overdue'] ? 'table-warning' : '' }}">
                <td class="fw-bold" dir="ltr"><a href="{{ route('permits.show', $row['id']) }}">{{ $row['code'] }}</a></td>
                <td class="small">{{ \Illuminate\Support\Str::limit($row['title'], 40) }}</td>
                <td>@include('modules.permits._status', ['status' => $row['status']])</td>
                <td class="small text-muted">{{ $row['waiting_since'] ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-muted py-3">لا شيء ينتظر.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- ينتهي قريباً --}}
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> ينتهي خلال ٣٠ يوماً</div>
      <div class="card-body p-0" style="max-height:340px;overflow:auto">
        @forelse($expiring as $row)
          <a href="{{ route('permits.show', $row['id']) }}" class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small text-decoration-none">
            <div class="flex-grow-1">
              <div class="fw-bold" dir="ltr">{{ $row['code'] }}</div>
              <div class="text-muted">{{ \Illuminate\Support\Str::limit($row['title'], 32) }}</div>
            </div>
            <div class="text-start">
              <div class="{{ $row['days_left'] <= 7 ? 'text-danger fw-bold' : 'text-muted' }}">{{ $row['days_left'] }} يوم</div>
              <div class="text-muted" style="font-size:.72rem">{{ $row['expires_at'] }}</div>
            </div>
          </a>
        @empty
          <div class="text-center text-muted py-3 small">لا شيء ينتهي قريباً.</div>
        @endforelse
      </div>
    </div>
  </div>

  {{-- بنود التحكم التي تحتاج مراجعة --}}
  @if($controlsToReview->isNotEmpty())
    <div class="col-12">
      <div class="card border-warning">
        <div class="card-header bg-warning-subtle">
          <i class="bi bi-arrow-repeat"></i> بنود تحكم تحتاج مراجعة (تكرّر إخفاقها في تقييمات الإغلاق)
        </div>
        <div class="card-body p-0">
          @foreach($controlsToReview as $ctrl)
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
              <span class="badge bg-danger">{{ $ctrl->flag_count }} مرات</span>
              <span class="flex-grow-1">{{ $ctrl->description_ar }}</span>
              <span class="text-muted">{{ $ctrl->phaseLabel() }}</span>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  @endif
</div>
@endsection
