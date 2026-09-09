@extends('layouts.app')
@section('page_title', 'التصاريح')
@section('content')
@php
  $me = auth()->user();
  $cats = [
    null => ['الكل', 'bi-collection'],
    'qualification' => ['تأهيل', 'bi-patch-check'],
    'work' => ['عمل', 'bi-tools'],
    'special' => ['خاص (عالي الخطورة)', 'bi-shield-exclamation'],
    'worker' => ['عامل', 'bi-person-badge'],
    'equipment' => ['معدة', 'bi-truck'],
  ];
@endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-file-earmark-check me-2"></i>التصاريح</h1>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="{{ route('permits.queue') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-inbox"></i> طابور الإجراء</a>
    <a href="{{ route('permits.dashboard') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-speedometer2"></i> اللوحة</a>
    @if(\App\Core\Permissions\PermissionRegistry::hasPermission($me->role(), 'permit.create'))
      <a href="{{ route('permits.create') }}" class="btn btn-sm btn-g"><i class="bi bi-plus-lg"></i> تصريح جديد</a>
    @endif
  </div>
</div>

{{-- بطاقات الفئات --}}
<div class="row g-2 mb-3">
  @foreach($cats as $key => [$label, $icon])
    @php($count = $key === null ? $totalFacet : ($facets[$key] ?? 0))
    @php($url = $key ? route('permits.index', ['category' => $key]) : route('permits.index'))
    <div class="col-6 col-md-2">
      <a href="{{ $url }}" class="text-decoration-none">
        <div class="card h-100 {{ ($activeCategory ?? null) === $key ? 'border-2' : '' }}"
             style="{{ ($activeCategory ?? null) === $key ? 'border-color:var(--g)' : '' }}">
          <div class="card-body text-center py-2">
            <i class="bi {{ $icon }} fs-5 text-muted"></i>
            <div class="h5 m-0" data-facet="{{ $key ?? 'all' }}">{{ $count }}</div>
            <small class="text-muted">{{ $label }}</small>
          </div>
        </div>
      </a>
    </div>
  @endforeach
</div>

{{-- المرشّحات --}}
<form method="get" class="row g-2 align-items-end mb-3">
  @if($activeCategory)<input type="hidden" name="category" value="{{ $activeCategory }}">@endif
  <div class="col-md-3">
    <label class="form-label small mb-1">الحالة</label>
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">كل الحالات</option>
      @foreach(\App\Modules\Permit\Models\Permit::STATUS_LABELS as $k => $v)
        <option value="{{ $k }}" @selected(($activeStatus ?? '') === $k)>{{ $v }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">المكان</label>
    <select name="place_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">كل الأماكن</option>
      @foreach($places as $p)
        <option value="{{ $p->id }}" @selected(($activePlace ?? 0) === $p->id)>{{ $p->code }} — {{ $p->name }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">النطاق</label>
    <select name="scope" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">كل النطاقات</option>
      @foreach(\App\Modules\Permit\Models\Permit::SCOPE_LABELS as $k => $v)
        <option value="{{ $k }}" @selected(($activeScope ?? '') === $k)>{{ $v }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3">
    @if($activeStatus || $activePlace || $activeScope || $activeCategory)
      <a href="{{ route('permits.index') }}" class="btn btn-sm btn-outline-secondary">مسح المرشّحات</a>
    @endif
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>الرمز</th><th>العنوان</th><th>النوع</th><th>المكان</th><th>الجهة</th><th>الحالة</th><th>ينتهي</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($permits as $p)
          <tr data-permit="{{ $p->code }}">
            <td class="fw-bold text-nowrap" dir="ltr">{{ $p->code }}</td>
            <td>{{ \Illuminate\Support\Str::limit($p->title, 55) }}</td>
            <td class="small text-muted">{{ $p->type?->name ?? '—' }}</td>
            <td class="small">{{ $p->place?->name ?? '—' }}</td>
            <td class="small text-muted">
              @if($p->externalParty)<i class="bi bi-building"></i> {{ $p->externalParty->name }}
              @elseif($p->project)<i class="bi bi-kanban"></i> {{ $p->project->name }}
              @else—@endif
            </td>
            <td>@include('modules.permits._status', ['status' => $p->status])</td>
            <td class="small {{ $p->expires_at && $p->expires_at->isPast() ? 'text-danger' : 'text-muted' }}">
              {{ $p->expires_at?->format('Y-m-d') ?? '—' }}
            </td>
            <td><a href="{{ route('permits.show', $p) }}" class="btn btn-sm btn-outline-primary">عرض</a></td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-muted py-4">لا تصاريح مطابقة.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($permits->hasPages())<div class="card-footer">{{ $permits->links() }}</div>@endif
</div>
@endsection
