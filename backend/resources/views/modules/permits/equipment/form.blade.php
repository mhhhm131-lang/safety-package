@extends('layouts.app')
@section('page_title', $item->exists ? 'تعديل معدة' : 'تسجيل معدة')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="{{ $item->exists ? route('equipment.show', $item) : route('equipment.index') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-right"></i>
  </a>
  <h1 class="h5 m-0">{{ $item->exists ? 'تعديل: '.$item->name : 'تسجيل معدة جديدة' }}</h1>
</div>

<div class="card">
  <form method="post" action="{{ $item->exists ? route('equipment.update', $item) : route('equipment.store') }}">
    @csrf
    @if($item->exists)@method('PUT')@endif
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">اسم المعدة <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="200" value="{{ old('name', $item->name) }}">
        </div>
        <div class="col-md-3">
          <label class="form-label">الرمز</label>
          <input type="text" name="code" class="form-control" maxlength="60" dir="ltr" value="{{ old('code', $item->code) }}">
        </div>
        <div class="col-md-3">
          <label class="form-label">النوع</label>
          <select name="equipment_type" class="form-select">
            <option value="">— اختر —</option>
            @foreach(\App\Modules\Permit\Models\Equipment::TYPE_LABELS as $k => $v)
              <option value="{{ $k }}" @selected(old('equipment_type', $item->equipment_type) === $k)>{{ $v }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">الرقم التسلسلي</label>
          <input type="text" name="serial_number" class="form-control" maxlength="120" dir="ltr" value="{{ old('serial_number', $item->serial_number) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">الصانع</label>
          <input type="text" name="manufacturer" class="form-control" maxlength="120" value="{{ old('manufacturer', $item->manufacturer) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">الطراز</label>
          <input type="text" name="model_number" class="form-control" maxlength="120" dir="ltr" value="{{ old('model_number', $item->model_number) }}">
        </div>

        <div class="col-md-4">
          <label class="form-label">الحالة <span class="text-danger">*</span></label>
          <select name="status" class="form-select" required>
            @foreach(\App\Modules\Permit\Models\Equipment::STATUS_LABELS as $k => $v)
              <option value="{{ $k }}" @selected(old('status', $item->status ?? 'active') === $k)>{{ $v }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">المكان</label>
          <select name="place_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($places as $p)
              <option value="{{ $p->id }}" @selected(old('place_id', $item->place_id) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">الموضع الدقيق</label>
          <input type="text" name="location" class="form-control" maxlength="200" value="{{ old('location', $item->location) }}">
        </div>

        <div class="col-md-4">
          <label class="form-label">المالك (مقاول)</label>
          <select name="external_party_id" class="form-select">
            <option value="">— المعهد —</option>
            @foreach($parties as $party)
              <option value="{{ $party->id }}" @selected(old('external_party_id', $item->external_party_id) == $party->id)>{{ $party->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">المشروع</label>
          <select name="project_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($projects as $pr)
              <option value="{{ $pr->id }}" @selected(old('project_id', $item->project_id) == $pr->id)>{{ $pr->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">دورية الفحص (أيام)</label>
          <input type="number" name="inspection_frequency_days" class="form-control" min="1" max="3650"
                 value="{{ old('inspection_frequency_days', $item->inspection_frequency_days) }}" placeholder="بلا دورية">
          <div class="form-text">يُحسب منها موعد الفحص القادم آلياً عند تسجيل فحص.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">آخر فحص</label>
          <input type="date" name="last_inspection_date" class="form-control" value="{{ old('last_inspection_date', $item->last_inspection_date?->format('Y-m-d')) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">الفحص القادم</label>
          <input type="date" name="next_inspection_date" class="form-control" value="{{ old('next_inspection_date', $item->next_inspection_date?->format('Y-m-d')) }}">
        </div>

        <div class="col-12">
          <label class="form-label">ملاحظات</label>
          <textarea name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes', $item->notes) }}</textarea>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-g"><i class="bi bi-save"></i> حفظ</button>
      <a href="{{ $item->exists ? route('equipment.show', $item) : route('equipment.index') }}" class="btn btn-outline-secondary">إلغاء</a>
    </div>
  </form>
</div>
@endsection
