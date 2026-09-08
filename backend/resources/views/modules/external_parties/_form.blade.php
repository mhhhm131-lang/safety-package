{{-- نموذج الطرف الخارجي (إنشاء/تعديل) — OHSMS كان يكرر النموذج ولا يملأ التعديل --}}
@php($p = $externalParty ?? null)
<div class="row g-3 mb-4">
  <div class="col-md-6"><label class="form-label">الاسم <span class="text-danger">*</span></label><input type="text" name="name" class="form-control form-control-lg @error('name') is-invalid @enderror" value="{{ old('name', $p?->name) }}" required></div>
  <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label><input type="text" name="name_en" class="form-control" dir="ltr" value="{{ old('name_en', $p?->name_en) }}"></div>
  <div class="col-md-4"><label class="form-label">النوع <span class="text-danger">*</span></label>
    <select name="party_type" class="form-select" required>
      @foreach($types as $k => $v)<option value="{{ $k }}" @selected(old('party_type', $p?->party_type ?? 'contractor') === $k)>{{ $v }}</option>@endforeach
    </select></div>
  @isset($statuses)
  <div class="col-md-4"><label class="form-label">الحالة</label>
    <select name="status" class="form-select">
      @foreach($statuses as $k => $v)<option value="{{ $k }}" @selected(old('status', $p?->status) === $k)>{{ $v }}</option>@endforeach
    </select></div>
  @endisset
  <div class="col-md-4"><label class="form-label">السجل التجاري</label><input type="text" name="cr_number" class="form-control" dir="ltr" value="{{ old('cr_number', $p?->cr_number) }}"></div>
  <div class="col-md-4"><label class="form-label">جهة الاتصال</label><input type="text" name="contact_person" class="form-control" value="{{ old('contact_person', $p?->contact_person) }}"></div>
  <div class="col-md-4"><label class="form-label">الهاتف</label><input type="text" name="phone" class="form-control" dir="ltr" value="{{ old('phone', $p?->phone) }}"></div>
  <div class="col-md-4"><label class="form-label">البريد</label><input type="email" name="email" class="form-control" dir="ltr" value="{{ old('email', $p?->email) }}"></div>
  <div class="col-12"><label class="form-label">العنوان</label><textarea name="address" class="form-control" rows="2">{{ old('address', $p?->address) }}</textarea></div>
  <div class="col-md-6"><label class="form-label">الموقع الإلكتروني</label><input type="url" name="website_url" class="form-control" dir="ltr" value="{{ old('website_url', $p?->website_url) }}" placeholder="https://"></div>
  <div class="col-md-6"><label class="form-label">رابط التسجيل / بوابة المقاولين</label><input type="url" name="registration_url" class="form-control" dir="ltr" value="{{ old('registration_url', $p?->registration_url) }}" placeholder="https://"></div>
  <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes', $p?->notes) }}</textarea></div>
</div>
