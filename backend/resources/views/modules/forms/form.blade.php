@extends('layouts.app')
@section('page_title', $form->exists ? 'تعديل نموذج' : 'نموذج جديد')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="{{ $form->exists ? route('forms.show', $form) : route('forms.index') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-right"></i>
  </a>
  <h1 class="h5 m-0">{{ $form->exists ? 'تعديل: '.$form->title : 'نموذج جديد' }}</h1>
</div>

<div class="card">
  <form method="post" action="{{ $form->exists ? route('forms.update', $form) : route('forms.store') }}">
    @csrf
    @if($form->exists)@method('PUT')@endif
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label">عنوان النموذج <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" required maxlength="200"
                 value="{{ old('title', $form->title) }}" placeholder="مثال: إقرار بمخاطر غرف الكهرباء">
        </div>
        <div class="col-md-4">
          <label class="form-label">النوع <span class="text-danger">*</span></label>
          <select name="form_type" class="form-select" required>
            @foreach(\App\Modules\Form\Models\FormTemplate::TYPE_LABELS as $k => $v)
              <option value="{{ $k }}" @selected(old('form_type', $form->form_type ?? 'custom') === $k)>{{ $v }}</option>
            @endforeach
          </select>
          <div class="form-text">الإقرار الموقَّع يستلزم حقل توقيع.</div>
        </div>

        <div class="col-12">
          <label class="form-label">وصف مختصر</label>
          <input type="text" name="description" class="form-control" maxlength="2000"
                 value="{{ old('description', $form->description) }}">
        </div>

        <div class="col-12">
          <label class="form-label">نص المقدمة</label>
          <textarea name="intro" class="form-control" rows="6" maxlength="8000"
                    placeholder="ما يقرؤه المكلَّف قبل الحقول: وصف الخطر وضوابطه، أو تعليمات التعبئة.">{{ old('intro', $form->intro) }}</textarea>
          <div class="form-text">في النماذج المولَّدة من المخاطر يُملأ هذا آلياً بالمخاطر وضوابطها.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">الوحدة التنظيمية</label>
          <select name="organization_unit_id" class="form-select">
            <option value="">— لا تخصيص —</option>
            @foreach($units as $u)
              <option value="{{ $u->id }}" @selected(old('organization_unit_id', $form->organization_unit_id) == $u->id)>{{ $u->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">المكان</label>
          <select name="place_id" class="form-select">
            <option value="">— لا تخصيص —</option>
            @foreach($places as $p)
              <option value="{{ $p->id }}" @selected(old('place_id', $form->place_id) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-g"><i class="bi bi-save"></i> حفظ</button>
      <a href="{{ $form->exists ? route('forms.show', $form) : route('forms.index') }}" class="btn btn-outline-secondary">إلغاء</a>
    </div>
  </form>
</div>
@endsection
