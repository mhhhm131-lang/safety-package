@extends('layouts.app')
@section('page_title', 'سجل فحص الجاهزية')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-list-ul"></i> سجل فحص الجاهزية</h1>
  <a href="{{ route('permits.gate') }}" class="btn btn-sm btn-g ms-auto"><i class="bi bi-person-check"></i> شاشة الفحص</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-md-2">
    <label class="form-label small mb-1">النتيجة</label>
    <select name="result" class="form-select form-select-sm">
      <option value="">الكل</option>
      <option value="allowed" @selected(request('result') === 'allowed')>سُمح</option>
      <option value="denied" @selected(request('result') === 'denied')>مُنع</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">المكان</label>
    <select name="place_id" class="form-select form-select-sm">
      <option value="">الكل</option>
      @foreach($places as $p)<option value="{{ $p->id }}" @selected(request('place_id') == $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label small mb-1">من</label>
    <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
  </div>
  <div class="col-md-2">
    <label class="form-label small mb-1">إلى</label>
    <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
  </div>
  <div class="col-md-3 d-flex gap-1">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> تصفية</button>
    <a href="{{ route('permits.gate.logs') }}" class="btn btn-sm btn-outline-secondary">مسح</a>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>العامل</th><th>المكان</th><th>التصريح</th><th>النتيجة</th><th>سبب المنع</th><th>الوقت</th></tr>
      </thead>
      <tbody>
        @forelse($logs as $log)
          <tr data-log="{{ $log->id }}">
            <td>
              <span class="fw-bold">{{ $log->worker?->full_name ?? 'غير مسجَّل' }}</span>
              @if($log->worker?->national_id)<div class="small text-muted" dir="ltr">{{ $log->worker->national_id }}</div>@endif
            </td>
            <td class="small">{{ $log->place?->name ?? '—' }}</td>
            <td class="small">
              @if($log->permit)
                <a href="{{ route('permits.show', $log->permit) }}" dir="ltr">{{ $log->permit->code }}</a>
              @else — @endif
            </td>
            <td>
              <span class="badge bg-{{ $log->result === 'allowed' ? 'success' : 'danger' }}">
                {{ $log->result === 'allowed' ? 'سُمح' : 'مُنع' }}
              </span>
            </td>
            <td class="small text-muted">
              @if($log->denial_reason)
                {{ collect(explode(', ', $log->denial_reason))->map(fn($r) => \App\Modules\Permit\Models\GateLog::denialLabel($r))->implode('، ') }}
              @else — @endif
            </td>
            <td class="small text-nowrap" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4">لا سجلات.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($logs->hasPages())<div class="card-footer">{{ $logs->links() }}</div>@endif
</div>
@endsection
