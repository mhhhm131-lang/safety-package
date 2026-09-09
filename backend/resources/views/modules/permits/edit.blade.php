@extends('layouts.app')
@section('page_title', 'تعديل التصريح ' . $permit->code)
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="{{ route('permits.show', $permit) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <h1 class="h5 m-0">تعديل المسودة <span class="text-muted" dir="ltr">{{ $permit->code }}</span></h1>
</div>

<div class="card">
  <form method="post" action="{{ route('permits.update', $permit) }}">
    @csrf @method('PUT')
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">العنوان <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required maxlength="200" value="{{ old('title', $permit->title) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">المكان</label>
          <select name="place_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($places as $p)
              <option value="{{ $p->id }}" @selected(old('place_id', $permit->place_id) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">الموضع الدقيق</label>
          <input type="text" name="sub_location" class="form-control" maxlength="200" value="{{ old('sub_location', $permit->sub_location) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">المشروع</label>
          <select name="project_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($projects as $pr)
              <option value="{{ $pr->id }}" @selected(old('project_id', $permit->project_id) == $pr->id)>{{ $pr->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">المقاول</label>
          <select name="external_party_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($parties as $party)
              <option value="{{ $party->id }}" @selected(old('external_party_id', $permit->external_party_id) == $party->id)>{{ $party->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">الوحدة التنظيمية</label>
          <select name="organization_unit_id" class="form-select">
            <option value="">— غير محدد —</option>
            @foreach($orgUnits as $u)
              <option value="{{ $u->id }}" @selected(old('organization_unit_id', $permit->organization_unit_id) == $u->id)>{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">عدد العمال</label>
          <input type="number" name="workers_count" class="form-control" min="0" max="9999" value="{{ old('workers_count', $permit->workers_count) }}">
        </div>
        <div class="col-md-3">
          <label class="form-label">عدد المعدات</label>
          <input type="number" name="equipment_count" class="form-control" min="0" max="9999" value="{{ old('equipment_count', $permit->equipment_count) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">يبدأ</label>
          <input type="datetime-local" name="starts_at" class="form-control" value="{{ old('starts_at', $permit->starts_at?->format('Y-m-d\TH:i')) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">ينتهي</label>
          <input type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at', $permit->expires_at?->format('Y-m-d\TH:i')) }}">
        </div>
        <div class="col-12">
          <label class="form-label">وصف العمل</label>
          <textarea name="description" class="form-control" rows="2" maxlength="2000">{{ old('description', $permit->description) }}</textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">وصف الموقع</label>
          <textarea name="location_description" class="form-control" rows="2" maxlength="1000">{{ old('location_description', $permit->location_description) }}</textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">الاحتياطات</label>
          <textarea name="precautions" class="form-control" rows="2" maxlength="2000">{{ old('precautions', $permit->precautions) }}</textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">اسم مقدّم الطلب</label>
          <input type="text" name="requester_name" class="form-control" maxlength="150" value="{{ old('requester_name', $permit->requester_name) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">هاتف مقدّم الطلب</label>
          <input type="text" name="requester_phone" class="form-control" maxlength="30" dir="ltr" value="{{ old('requester_phone', $permit->requester_phone) }}">
        </div>
        @if($trades->isNotEmpty())
          @php($selected = old('trade_ids', $permit->trades->pluck('id')->all()))
          <div class="col-12">
            <label class="form-label">المهن المعنية</label>
            <div class="border rounded p-2" style="max-height:160px;overflow:auto">
              <div class="row g-1">
                @foreach($trades as $tr)
                  <div class="col-md-3 col-6">
                    <label class="d-flex align-items-center gap-1 small">
                      <input type="checkbox" name="trade_ids[]" value="{{ $tr->id }}" class="form-check-input mt-0" @checked(in_array($tr->id, $selected))>
                      {{ $tr->name }}
                    </label>
                  </div>
                @endforeach
              </div>
            </div>
          </div>
        @endif
        <div class="col-12">
          <label class="form-label">ملاحظات إضافية</label>
          <textarea name="additional_notes" class="form-control" rows="2" maxlength="2000">{{ old('additional_notes', $permit->additional_notes) }}</textarea>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-g"><i class="bi bi-save"></i> حفظ</button>
      <a href="{{ route('permits.show', $permit) }}" class="btn btn-outline-secondary">إلغاء</a>
    </div>
  </form>
</div>
@endsection
