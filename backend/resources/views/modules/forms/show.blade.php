@extends('layouts.app')
@section('page_title', $form->title)
@section('content')
@php $me = auth()->user(); @endphp

<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <div>
    <div class="small"><a href="{{ route('forms.index') }}" class="text-muted text-decoration-none"><i class="bi bi-arrow-right"></i> النماذج</a></div>
    <h1 class="h4 m-0">{{ $form->title }}</h1>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
      @include('modules.forms._type', ['type' => $form->form_type])
      @unless($form->is_active)<span class="badge bg-secondary">موقوف</span>@endunless
      @if($form->allows_anonymous)<span class="badge bg-dark" data-anonymous><i class="bi bi-incognito"></i> سري باختيار المعبِّئ</span>@endif
      @if($form->place)<span class="small text-muted"><i class="bi bi-geo-alt"></i> {{ $form->place->name }}</span>@endif
      @if($form->organizationUnit)<span class="small text-muted"><i class="bi bi-diagram-3"></i> {{ $form->organizationUnit->name }}</span>@endif
      <span class="small text-muted">أنشأه {{ $form->createdBy?->name ?? '—' }}</span>
    </div>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    @if($me->can_('form.send') && $form->fields->isNotEmpty())
      <a href="{{ route('forms.send', $form) }}" class="btn btn-sm btn-g"><i class="bi bi-send"></i> تكليف</a>
    @endif
    @if($me->can_('form.track') && $stats['total'] > 0)
      <a href="{{ route('forms.tracking', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-check"></i> المتابعة</a>
    @endif
    @if($me->can_('form.results') && $form->submissions()->exists())
      <a href="{{ route('forms.results', $form) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-bar-chart"></i> النتائج</a>
    @endif
    @if($me->can_('form.edit'))
      <a href="{{ route('forms.edit', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> تعديل</a>
      <form method="post" action="{{ route('forms.toggle', $form) }}" class="d-inline">@csrf
        <button class="btn btn-sm btn-outline-{{ $form->is_active ? 'warning' : 'success' }}">
          {{ $form->is_active ? 'إيقاف' : 'تفعيل' }}
        </button>
      </form>
    @endif
  </div>
</div>

@if($stats['total'] > 0)
  <div class="row g-2 mb-3">
    @foreach([
      ['مكلَّف', $stats['total'], 'secondary'], ['عبّأ', $stats['completed'], 'success'],
      ['بانتظار', $stats['pending'], 'warning'], ['متأخر', $stats['overdue'], 'danger'],
    ] as [$label, $value, $color])
      <div class="col-6 col-md-3">
        <div class="card"><div class="card-body text-center py-2">
          <div class="h4 m-0 text-{{ $color }}" data-stat="{{ $label }}">{{ $value }}</div>
          <small class="text-muted">{{ $label }}</small>
        </div></div>
      </div>
    @endforeach
  </div>
@endif

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-list-ol"></i> الحقول</span>
        <span class="badge bg-light text-dark border ms-2" data-fields-count>{{ $form->fields->count() }}</span>
        @if($canEditFields)
          <button class="btn btn-sm btn-outline-primary ms-auto py-0" data-bs-toggle="collapse" data-bs-target="#addField">
            <i class="bi bi-plus"></i> إضافة حقل
          </button>
        @endif
      </div>

      @if($canEditFields)
        <div class="collapse" id="addField">
          <div class="card-body border-bottom">
            <form method="post" action="{{ route('forms.fields.store', $form) }}" class="row g-2">
              @csrf
              <div class="col-md-7">
                <label class="form-label small mb-1">نص الحقل <span class="text-danger">*</span></label>
                <input type="text" name="label" class="form-control form-control-sm" required maxlength="300">
              </div>
              <div class="col-md-5">
                <label class="form-label small mb-1">النوع <span class="text-danger">*</span></label>
                <select name="field_type" class="form-select form-select-sm" required id="newFieldType">
                  @foreach($fieldTypes as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small mb-1">شرح تحت الحقل</label>
                <input type="text" name="help_text" class="form-control form-control-sm" maxlength="500">
              </div>
              <div class="col-md-6">
                <label class="form-label small mb-1">نص إرشادي داخل الحقل</label>
                <input type="text" name="placeholder" class="form-control form-control-sm" maxlength="190">
              </div>
              <div class="col-12" id="optionsWrap" style="display:none">
                <label class="form-label small mb-1" id="optionsLabel">الخيارات — خيار في كل سطر</label>
                <textarea name="options" class="form-control form-control-sm @error('options') is-invalid @enderror" rows="3">{{ old('options') }}</textarea>
                {{-- ٢٩-١: تعريف الجدول من الشاشة --}}
                <div class="form-text" id="tableHelp" style="display:none">سطر لكل عمود: <code dir="rtl">الاسم | نص</code> أو <code dir="rtl">الاسم | اختيار | نعم، جزئياً، لا</code> أو <code dir="rtl">الاسم | ثابت</code>؛ وسطر يبدأ بـ<code>+</code> لكل صف ثابت (مثل الآليات). بلا صفوف ثابتة يضيف المعبِّئ صفوفه.</div>
                @error('options')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <label class="small"><input type="checkbox" name="is_required" value="1" class="form-check-input"> حقل إلزامي</label>
              </div>
              <div class="col-md-6 text-start"><button class="btn btn-sm btn-g">إضافة</button></div>
            </form>
          </div>
        </div>
      @endif

      <div class="card-body p-0">
        @forelse($form->fields as $field)
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom" data-field="{{ $field->id }}">
            <span class="badge bg-light text-muted border">{{ $loop->iteration }}</span>
            <div class="flex-grow-1 small">
              <div class="fw-bold">{{ $field->label }}
                @if($field->is_required)<span class="text-danger">*</span>@endif
              </div>
              @if($field->help_text)<div class="text-muted">{{ $field->help_text }}</div>@endif
              <div class="d-flex gap-1 mt-1 flex-wrap">
                <span class="badge bg-light text-dark border" style="font-size:.65rem">{{ $field->getTypeLabel() }}</span>
                @if($summary = $field->optionsSummary())
                  <span class="badge bg-light text-muted border text-wrap text-start" style="font-size:.65rem">{{ $summary }}</span>
                @endif
              </div>
            </div>
            @if($canEditFields)
              <form method="post" action="{{ route('forms.fields.destroy', [$form, $field]) }}"
                    onsubmit="return confirm('حذف الحقل؟')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
              </form>
            @endif
          </div>
        @empty
          <div class="text-center text-muted py-4 small">لا حقول بعد. أضف حقلاً أو ولّد النموذج من المخاطر.</div>
        @endforelse
      </div>

      @unless($canEditFields)
        @if($form->fields->isNotEmpty())
          <div class="card-footer small text-muted">
            <i class="bi bi-lock"></i> بدأت التعبئة — الحقول لا تُعدَّل بعدها حتى تبقى النتائج قابلة للمقارنة.
          </div>
        @endif
      @endunless
    </div>
  </div>

  <div class="col-lg-5">
    @if($form->intro)
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-card-text"></i> المقدمة التي يقرؤها المكلَّف</div>
        <div class="card-body small" style="white-space:pre-line">{{ $form->intro }}</div>
      </div>
    @endif

    @if($form->risks->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-exclamation-triangle text-warning"></i> المخاطر المغطاة ({{ $form->risks->count() }})</div>
        <div class="card-body p-0">
          @foreach($form->risks as $risk)
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
              <span class="text-muted" dir="ltr">{{ $risk->code }}</span>
              <span class="flex-grow-1">{{ $risk->title }}</span>
              <x-risk-score-badge :score="$risk->risk_score" />
            </div>
          @endforeach
        </div>
      </div>
    @endif

    @if($form->description)
      <div class="card"><div class="card-body small">{{ $form->description }}</div></div>
    @endif
  </div>
</div>

@push('scripts')
<script>
// إظهار مربع الخيارات للأنواع التي تحتاجها فقط — والجدول (٢٩-١) بعنوانه وشرحه
(function () {
  var withOptions = ['select', 'radio', 'checkbox', 'table'];
  var sel = document.getElementById('newFieldType');
  var wrap = document.getElementById('optionsWrap');
  var label = document.getElementById('optionsLabel');
  var help = document.getElementById('tableHelp');
  if (!sel || !wrap) return;
  function sync() {
    var isTable = sel.value === 'table';
    wrap.style.display = withOptions.indexOf(sel.value) >= 0 ? '' : 'none';
    if (label) label.textContent = isTable ? 'تعريف الجدول — سطر لكل عمود، وسطر يبدأ بـ+ لكل صف ثابت' : 'الخيارات — خيار في كل سطر';
    if (help) help.style.display = isTable ? '' : 'none';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
@endpush
@endsection
