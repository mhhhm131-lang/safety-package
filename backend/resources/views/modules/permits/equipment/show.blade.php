@extends('layouts.app')
@section('page_title', 'المعدة: ' . $equipment->name)
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="{{ route('equipment.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">{{ $equipment->name }}
      @if($equipment->code)<span class="text-muted" dir="ltr">({{ $equipment->code }})</span>@endif
    </h1>
    <div class="small text-muted">{{ $equipment->getTypeLabel() }} · {{ $equipment->place?->name ?? 'بلا مكان' }}</div>
  </div>
  <span class="badge bg-{{ $equipment->status === 'active' ? 'success' : ($equipment->status === 'out_of_service' ? 'danger' : 'secondary') }}">
    {{ $equipment->getStatusLabel() }}
  </span>
  <a href="{{ route('equipment.edit', $equipment) }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-pencil"></i> تعديل</a>
</div>

@if($equipment->isInspectionOverdue())
  <div class="alert alert-danger py-2 small">
    <i class="bi bi-exclamation-triangle-fill"></i>
    تجاوزت موعد الفحص ({{ $equipment->next_inspection_date->format('Y-m-d') }}) — لا يُصدَر لها تصريح تشغيل حتى تُفحص.
  </div>
@endif

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-info-circle"></i> البيانات</div>
      <div class="card-body">
        <div class="row g-2 small">
          @foreach([
            'الرقم التسلسلي' => $equipment->serial_number,
            'الصانع' => $equipment->manufacturer,
            'الطراز' => $equipment->model_number,
            'المالك' => $equipment->externalParty?->name ?? 'المعهد',
            'المشروع' => $equipment->project?->name,
            'الموضع' => $equipment->location,
            'دورية الفحص' => $equipment->inspection_frequency_days ? $equipment->inspection_frequency_days.' يوماً' : null,
            'آخر فحص' => $equipment->last_inspection_date?->format('Y-m-d'),
            'الفحص القادم' => $equipment->next_inspection_date?->format('Y-m-d'),
          ] as $label => $value)
            @if($value)
              <div class="col-6"><div class="text-muted">{{ $label }}</div><div class="fw-bold" dir="auto">{{ $value }}</div></div>
            @endif
          @endforeach
          @if($equipment->notes)
            <div class="col-12"><div class="text-muted">ملاحظات</div><div>{{ $equipment->notes }}</div></div>
          @endif
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clipboard-check"></i> تسجيل فحص</div>
      <div class="card-body">
        <form method="post" action="{{ route('equipment.inspections.store', $equipment) }}" class="row g-2">
          @csrf
          <div class="col-md-6">
            <label class="form-label small mb-1">تاريخ الفحص <span class="text-danger">*</span></label>
            <input type="date" name="inspection_date" class="form-control form-control-sm" required value="{{ now()->toDateString() }}">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">النتيجة <span class="text-danger">*</span></label>
            <select name="result" class="form-select form-select-sm" required>
              @foreach(\App\Modules\Permit\Models\EquipmentInspection::RESULT_LABELS as $k => $v)
                <option value="{{ $k }}">{{ $v }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">الملاحظات</label>
            <textarea name="findings" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">الفحص القادم</label>
            <input type="date" name="next_inspection" class="form-control form-control-sm">
            <div class="form-text">يُحسب من الدورية إن تُرك فارغاً.</div>
          </div>
          <div class="col-12"><button class="btn btn-sm btn-g">تسجيل الفحص</button></div>
        </form>
        <div class="form-text mt-2">نتيجة «غير مطابق» تُخرج المعدة من الخدمة تلقائياً.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clock-history"></i> سجل الفحوص ({{ $equipment->inspections->count() }})</div>
      <div class="card-body p-0">
        @forelse($equipment->inspections as $ins)
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
            <span class="badge bg-{{ $ins->result === 'pass' ? 'success' : ($ins->result === 'fail' ? 'danger' : 'warning text-dark') }}">
              {{ $ins->getResultLabel() }}
            </span>
            <div class="flex-grow-1">
              <div>{{ $ins->inspection_date->format('Y-m-d') }}
                @if($ins->next_inspection)<span class="text-muted">— القادم {{ $ins->next_inspection->format('Y-m-d') }}</span>@endif
              </div>
              @if($ins->findings)<div class="text-muted">{{ $ins->findings }}</div>@endif
            </div>
            <span class="text-muted">{{ $ins->inspector?->name }}</span>
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا فحوص مسجَّلة.</div>
        @endforelse
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-file-earmark-check"></i> تصاريح هذه المعدة</div>
      <div class="card-body p-0">
        @forelse($permits as $p)
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
            <a href="{{ route('permits.show', $p) }}" class="fw-bold" dir="ltr">{{ $p->code }}</a>
            <span class="flex-grow-1">{{ \Illuminate\Support\Str::limit($p->title, 40) }}</span>
            <span class="text-muted">{{ $p->type?->name }}</span>
            @include('modules.permits._status', ['status' => $p->status])
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا تصاريح لهذه المعدة.</div>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
