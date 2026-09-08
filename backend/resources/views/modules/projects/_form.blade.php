{{-- نموذج المشروع (إنشاء/تعديل). المعهد: المكان إلزامي --}}
@php($p = $project ?? null)
<div class="row g-3 mb-4">
  <div class="col-md-6"><label class="form-label">اسم المشروع <span class="text-danger">*</span></label><input type="text" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror" value="{{ old('name', $p?->name) }}" required placeholder="مثال: صيانة أنظمة التكييف — المبنى الرئيسي"></div>
  <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label><input type="text" name="name_en" class="form-control" dir="ltr" value="{{ old('name_en', $p?->name_en) }}"></div>
  <div class="col-md-3"><label class="form-label">رمز المشروع</label><input type="text" name="code" class="form-control" dir="ltr" value="{{ old('code', $p?->code) }}" placeholder="PRJ-001"></div>
  <div class="col-md-3"><label class="form-label">المكان (المعهد) <span class="text-danger">*</span></label>
    <select name="place_id" class="form-select @error('place_id') is-invalid @enderror" required>
      <option value="">— اختر —</option>
      @foreach($places as $pl)<option value="{{ $pl->id }}" @selected((int) old('place_id', $p?->place_id) === $pl->id)>{{ $pl->code }} · {{ $pl->name }}</option>@endforeach
    </select>
    @error('place_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
  <div class="col-md-3"><label class="form-label">الحالة</label>
    <select name="status" class="form-select">@foreach($statuses as $k => $v)<option value="{{ $k }}" @selected(old('status', $p?->status ?? 'planning') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="col-md-3"><label class="form-label">الإدارة المالكة</label>
    <select name="organization_unit_id" class="form-select"><option value="">—</option>@foreach($units as $un)<option value="{{ $un->id }}" @selected((int) old('organization_unit_id', $p?->organization_unit_id) === $un->id)>{{ $un->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">منسق السلامة المكلّف</label>
    <select name="assigned_coordinator_id" class="form-select"><option value="">—</option>@foreach($coordinators as $c)<option value="{{ $c->id }}" @selected((int) old('assigned_coordinator_id', $p?->assigned_coordinator_id) === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">تاريخ البداية</label><input type="date" name="start_date" class="form-control" value="{{ old('start_date', $p?->start_date?->toDateString()) }}"></div>
  <div class="col-md-4"><label class="form-label">تاريخ النهاية</label><input type="date" name="end_date" class="form-control" value="{{ old('end_date', $p?->end_date?->toDateString()) }}"></div>
  <div class="col-12"><label class="form-label">الوصف</label><textarea name="description" class="form-control" rows="3">{{ old('description', $p?->description) }}</textarea></div>
</div>
