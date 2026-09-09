@extends('layouts.app')
@section('page_title', 'المعدات')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-truck"></i> المعدات</h1>
  @if($overdue > 0)
    <a href="{{ route('equipment.index', ['overdue' => 1]) }}" class="badge bg-danger text-decoration-none">
      {{ $overdue }} تجاوزت موعد الفحص
    </a>
  @endif
  <a href="{{ route('equipment.create') }}" class="btn btn-sm btn-g ms-auto"><i class="bi bi-plus-lg"></i> تسجيل معدة</a>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-md-3">
    <label class="form-label small mb-1">بحث</label>
    <input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="الاسم أو الرمز أو الرقم التسلسلي">
  </div>
  <div class="col-md-2">
    <label class="form-label small mb-1">الحالة</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">الكل</option>
      @foreach(\App\Modules\Permit\Models\Equipment::STATUS_LABELS as $k => $v)
        <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label small mb-1">المكان</label>
    <select name="place_id" class="form-select form-select-sm">
      <option value="">الكل</option>
      @foreach($places as $p)<option value="{{ $p->id }}" @selected(request('place_id') == $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach
    </select>
  </div>
  <div class="col-md-4 d-flex gap-1">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> تصفية</button>
    <a href="{{ route('equipment.index') }}" class="btn btn-sm btn-outline-secondary">مسح</a>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>المعدة</th><th>النوع</th><th>المكان</th><th>الجهة</th><th>الحالة</th><th>الفحص القادم</th><th></th></tr>
      </thead>
      <tbody>
        @forelse($equipment as $eq)
          <tr data-equipment="{{ $eq->id }}">
            <td>
              <span class="fw-bold">{{ $eq->name }}</span>
              @if($eq->code)<span class="text-muted" dir="ltr">({{ $eq->code }})</span>@endif
              @if($eq->serial_number)<div class="small text-muted" dir="ltr">{{ $eq->serial_number }}</div>@endif
            </td>
            <td class="small">{{ $eq->getTypeLabel() }}</td>
            <td class="small">{{ $eq->place?->name ?? '—' }}</td>
            <td class="small text-muted">{{ $eq->externalParty?->name ?? ($eq->project?->name ?? 'المعهد') }}</td>
            <td>
              <span class="badge bg-{{ $eq->status === 'active' ? 'success' : ($eq->status === 'out_of_service' ? 'danger' : 'secondary') }}">
                {{ $eq->getStatusLabel() }}
              </span>
            </td>
            <td class="small {{ $eq->isInspectionOverdue() ? 'text-danger fw-bold' : 'text-muted' }}">
              {{ $eq->next_inspection_date?->format('Y-m-d') ?? '—' }}
              @if($eq->isInspectionOverdue())<i class="bi bi-exclamation-circle"></i>@endif
            </td>
            <td><a href="{{ route('equipment.show', $eq) }}" class="btn btn-sm btn-outline-primary">عرض</a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">لا معدات مسجَّلة.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($equipment->hasPages())<div class="card-footer">{{ $equipment->links() }}</div>@endif
</div>
@endsection
