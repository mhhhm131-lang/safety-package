@extends('layouts.app')
@section('page_title', 'طابور التصاريح')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-inbox"></i> ما ينتظر إجراءً</h1>
  <a href="{{ route('permits.index') }}" class="btn btn-sm btn-outline-secondary ms-auto">كل التصاريح</a>
</div>

<h2 class="h6 mb-2"><i class="bi bi-lightning-charge-fill text-warning"></i> ينتظر قراراً
  <span class="badge bg-warning text-dark" data-pending="{{ $pending->count() }}">{{ $pending->count() }}</span>
</h2>
<div class="card mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>الرمز</th><th>العنوان</th><th>المكان</th><th>مقدّمه</th><th>الحالة</th><th>منذ</th><th></th></tr></thead>
      <tbody>
        @forelse($pending as $p)
          <tr data-permit="{{ $p->code }}">
            <td class="fw-bold text-nowrap" dir="ltr">{{ $p->code }}</td>
            <td>{{ \Illuminate\Support\Str::limit($p->title, 45) }}<div class="small text-muted">{{ $p->type?->name }}</div></td>
            <td class="small">{{ $p->place?->name ?? '—' }}</td>
            <td class="small text-muted">{{ $p->requestedBy?->name ?? '—' }}</td>
            <td>@include('modules.permits._status', ['status' => $p->status])</td>
            <td class="small {{ $p->submitted_at && $p->submitted_at->lt(now()->subDay()) ? 'text-danger fw-bold' : 'text-muted' }}">
              {{ $p->submitted_at?->diffForHumans() ?? '—' }}
            </td>
            <td><a href="{{ route('permits.show', $p) }}" class="btn btn-sm btn-outline-primary">فتح</a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle-fill text-success"></i> لا شيء ينتظر قراراً.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

@if($expiring->isNotEmpty())
  <h2 class="h6 mb-2"><i class="bi bi-alarm-fill text-danger"></i> تنتهي خلال أسبوع
    <span class="badge bg-danger">{{ $expiring->count() }}</span>
  </h2>
  <div class="row g-2 mb-4">
    @foreach($expiring as $p)
      @php($days = (int) now()->startOfDay()->diffInDays($p->expires_at->startOfDay(), false))
      <div class="col-md-4">
        <div class="card h-100 border-{{ $days <= 2 ? 'danger' : 'warning' }}">
          <div class="card-body py-2">
            <div class="d-flex align-items-start">
              <div>
                <div class="fw-bold small">{{ \Illuminate\Support\Str::limit($p->title, 35) }}</div>
                <div class="small text-muted" dir="ltr">{{ $p->code }}</div>
              </div>
              <span class="badge bg-{{ $days <= 2 ? 'danger' : 'warning text-dark' }} ms-auto">{{ $days }} يوم</span>
            </div>
            <div class="small text-muted mt-1">
              <i class="bi bi-geo-alt"></i> {{ $p->place?->name ?? '—' }} ·
              <i class="bi bi-calendar-x"></i> {{ $p->expires_at->format('Y-m-d') }}
            </div>
            <a href="{{ route('permits.show', $p) }}" class="btn btn-sm btn-outline-secondary w-100 mt-2">عرض</a>
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif

@if($recent->isNotEmpty())
  <h2 class="h6 mb-2 text-muted"><i class="bi bi-clock-history"></i> عولج خلال ٤٨ ساعة</h2>
  <div class="card">
    <div class="card-body p-0">
      @foreach($recent as $p)
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
          <span class="fw-bold" dir="ltr">{{ $p->code }}</span>
          <span class="flex-grow-1">{{ \Illuminate\Support\Str::limit($p->title, 50) }}</span>
          @include('modules.permits._status', ['status' => $p->status])
          <span class="text-muted">{{ $p->updated_at->diffForHumans() }}</span>
          <a href="{{ route('permits.show', $p) }}" class="btn btn-sm btn-outline-secondary py-0">عرض</a>
        </div>
      @endforeach
    </div>
  </div>
@endif
@endsection
