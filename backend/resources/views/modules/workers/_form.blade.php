{{-- نموذج العامل (إنشاء/تعديل). المعهد: الطرف إلزامي، المكان بدل «المنطقة»؛ OHSMS لم يكن يملأ نموذج التعديل --}}
@php($w = $worker ?? null)
<div class="row g-3 mb-4">
  <div class="col-md-6"><label class="form-label">الاسم الكامل <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control form-control-lg" value="{{ old('full_name', $w?->full_name) }}" required></div>
  <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label><input type="text" name="full_name_en" class="form-control" dir="ltr" value="{{ old('full_name_en', $w?->full_name_en) }}"></div>
  <div class="col-md-4"><label class="form-label">رقم الهوية/الإقامة <span class="text-danger">*</span></label><input type="text" name="national_id" class="form-control @error('national_id') is-invalid @enderror" dir="ltr" value="{{ old('national_id', $w?->national_id) }}" required>@error('national_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
  <div class="col-md-4"><label class="form-label">الهاتف</label><input type="text" name="phone" class="form-control" dir="ltr" value="{{ old('phone', $w?->phone) }}"></div>
  <div class="col-md-4"><label class="form-label">المهنة <span class="text-danger">*</span></label>
    <select name="trade_id" class="form-select" required><option value="">— اختر —</option>@foreach($trades as $t)<option value="{{ $t->id }}" @selected((int) old('trade_id', $w?->trade_id) === $t->id)>{{ $t->code }} · {{ $t->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">الطرف الخارجي (المقاول) <span class="text-danger">*</span></label>
    <select name="external_party_id" class="form-select" required @if($externalParties->count() === 1) readonly @endif><option value="">— اختر —</option>@foreach($externalParties as $pt)<option value="{{ $pt->id }}" @selected((int) old('external_party_id', $w?->external_party_id ?? ($externalParties->count() === 1 ? $externalParties->first()->id : null)) === $pt->id)>{{ $pt->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">المشروع</label>
    <select name="project_id" class="form-select"><option value="">—</option>@foreach($projects as $pr)<option value="{{ $pr->id }}" @selected((int) old('project_id', $w?->project_id) === $pr->id)>{{ $pr->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">مكان العمل (المعهد)</label>
    <select name="place_id" class="form-select"><option value="">—</option>@foreach($places as $pl)<option value="{{ $pl->id }}" @selected((int) old('place_id', $w?->place_id) === $pl->id)>{{ $pl->code }} · {{ $pl->name }}</option>@endforeach</select></div>
  <div class="col-md-4"><label class="form-label">انتهاء الفحص الطبي</label><input type="date" name="medical_expiry" class="form-control" value="{{ old('medical_expiry', $w?->medical_expiry?->toDateString()) }}"></div>
  <div class="col-md-4"><label class="form-label">انتهاء الإقامة</label><input type="date" name="iqama_expiry" class="form-control" value="{{ old('iqama_expiry', $w?->iqama_expiry?->toDateString()) }}"></div>
  <div class="col-md-4"><label class="form-label">تاريخ الالتحاق</label><input type="date" name="joined_date" class="form-control" value="{{ old('joined_date', $w?->joined_date?->toDateString()) }}"></div>
</div>
