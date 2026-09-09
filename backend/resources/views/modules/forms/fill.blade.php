@extends('layouts.app')
@section('page_title', $form->title)
@section('content')

<div class="row justify-content-center">
  <div class="col-lg-8">

    <div class="mb-3">
      <div class="small"><a href="{{ route('forms.mine') }}" class="text-muted text-decoration-none"><i class="bi bi-arrow-right"></i> نماذجي</a></div>
      <h1 class="h4 m-0">{{ $form->title }}</h1>
      <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
        @include('modules.forms._type', ['type' => $form->form_type])
        @if($assignment?->due_date)
          <span class="small {{ $assignment->due_date->isPast() ? 'text-danger fw-bold' : 'text-muted' }}">
            <i class="bi bi-calendar-event"></i> المهلة: {{ $assignment->due_date->format('Y-m-d') }}
          </span>
        @endif
      </div>
      @if($form->description)<p class="text-muted small mt-2 mb-0">{{ $form->description }}</p>@endif
    </div>

    @if($form->intro)
      <div class="card mb-3 border-warning">
        <div class="card-header bg-warning-subtle"><i class="bi bi-card-text"></i> اقرأ قبل التعبئة</div>
        <div class="card-body" style="white-space:pre-line">{{ $form->intro }}</div>
      </div>
    @endif

    <form method="post" action="{{ route('forms.submit', $form) }}" enctype="multipart/form-data" id="fillForm">
      @csrf
      <div class="card">
        <div class="card-body">
          @forelse($form->fields as $field)
            @php $key = 'fields.'.$field->id; @endphp
            <div class="mb-4" data-field="{{ $field->id }}" data-field-type="{{ $field->field_type }}">
              <label class="form-label fw-bold">
                {{ $field->label }}
                @if($field->is_required)<span class="text-danger">*</span>@endif
              </label>
              @if($field->help_text)<div class="form-text mb-1">{{ $field->help_text }}</div>@endif

              @switch($field->field_type)
                @case('text')
                  <input type="text" name="fields[{{ $field->id }}]" class="form-control @error($key) is-invalid @enderror"
                         value="{{ old('fields.'.$field->id) }}" placeholder="{{ $field->placeholder }}"
                         @required($field->is_required) maxlength="5000">
                  @break

                @case('number')
                  <input type="number" step="any" name="fields[{{ $field->id }}]"
                         class="form-control @error($key) is-invalid @enderror"
                         value="{{ old('fields.'.$field->id) }}" placeholder="{{ $field->placeholder }}"
                         @required($field->is_required)>
                  @break

                @case('textarea')
                  <textarea name="fields[{{ $field->id }}]" rows="4" maxlength="5000"
                            class="form-control @error($key) is-invalid @enderror"
                            placeholder="{{ $field->placeholder }}"
                            @required($field->is_required)>{{ old('fields.'.$field->id) }}</textarea>
                  @break

                @case('date')
                  <input type="date" name="fields[{{ $field->id }}]" class="form-control @error($key) is-invalid @enderror"
                         value="{{ old('fields.'.$field->id) }}" @required($field->is_required)>
                  @break

                @case('select')
                  <select name="fields[{{ $field->id }}]" class="form-select @error($key) is-invalid @enderror"
                          @required($field->is_required)>
                    <option value="">— اختر —</option>
                    @foreach($field->options ?? [] as $opt)
                      <option value="{{ $opt }}" @selected(old('fields.'.$field->id) === $opt)>{{ $opt }}</option>
                    @endforeach
                  </select>
                  @break

                @case('radio')
                  @foreach($field->options ?? [] as $i => $opt)
                    <div class="form-check">
                      <input type="radio" class="form-check-input" id="f{{ $field->id }}_{{ $i }}"
                             name="fields[{{ $field->id }}]" value="{{ $opt }}"
                             @checked(old('fields.'.$field->id) === $opt) @required($field->is_required)>
                      <label class="form-check-label" for="f{{ $field->id }}_{{ $i }}">{{ $opt }}</label>
                    </div>
                  @endforeach
                  @break

                @case('checkbox')
                  @foreach($field->options ?? [] as $i => $opt)
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="f{{ $field->id }}_{{ $i }}"
                             name="fields[{{ $field->id }}][]" value="{{ $opt }}"
                             @checked(in_array($opt, (array) old('fields.'.$field->id, [])))>
                      <label class="form-check-label" for="f{{ $field->id }}_{{ $i }}">{{ $opt }}</label>
                    </div>
                  @endforeach
                  @break

                @case('acknowledge')
                  <div class="form-check p-3 rounded" style="background:#f6faf7;border:1px solid #cfe3d8">
                    {{-- القيمة "1" لأن قاعدة التحقق `accepted` لا تقبل نصاً؛ وتُخزَّن «أقرّ بذلك» في الخدمة --}}
                    <input type="checkbox" class="form-check-input" id="f{{ $field->id }}"
                           name="fields[{{ $field->id }}]" value="1"
                           @checked(old('fields.'.$field->id)) @required($field->is_required)>
                    <label class="form-check-label fw-bold" for="f{{ $field->id }}">أقرّ بذلك</label>
                  </div>
                  @break

                @case('signature')
                  <div class="border rounded p-2" style="background:#fff">
                    <canvas class="sig-pad w-100" data-target="sig{{ $field->id }}" height="160"
                            style="touch-action:none;border:1px dashed #adb5bd;border-radius:6px;cursor:crosshair"></canvas>
                    <input type="hidden" name="signatures[{{ $field->id }}]" id="sig{{ $field->id }}">
                    <div class="d-flex align-items-center gap-2 mt-2">
                      <button type="button" class="btn btn-sm btn-outline-secondary sig-clear" data-target="sig{{ $field->id }}">
                        <i class="bi bi-eraser"></i> مسح
                      </button>
                      <small class="text-muted sig-state" data-target="sig{{ $field->id }}">لم يُوقَّع بعد</small>
                    </div>
                  </div>
                  @break

                @case('photo')
                  <input type="file" name="photos[{{ $field->id }}]" accept="image/*" capture="environment"
                         class="form-control @error('photos.'.$field->id) is-invalid @enderror"
                         @required($field->is_required)>
                  <div class="form-text">صورة واحدة، ٥ ميجابايت حداً أقصى.</div>
                  @break

                @default
                  <input type="text" name="fields[{{ $field->id }}]" class="form-control"
                         value="{{ old('fields.'.$field->id) }}">
              @endswitch

              @error($key)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              @error('photos.'.$field->id)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
              @error('signatures.'.$field->id)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
          @empty
            <div class="text-center text-muted py-4">لا حقول في هذا النموذج بعد.</div>
          @endforelse
        </div>
        @if($form->fields->isNotEmpty())
          <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">تُسجَّل تعبئتك باسمك ووقتها. لا يمكن التعديل بعد الإرسال.</small>
            <button class="btn btn-g"><i class="bi bi-send"></i> إرسال</button>
          </div>
        @endif
      </div>
    </form>
  </div>
</div>

@push('scripts')
<script>
// لوحة توقيع بسيطة: رسم بالفأرة أو باللمس، وتُحفظ صورة PNG في حقل مخفي عند كل رفع للقلم.
document.querySelectorAll('.sig-pad').forEach(function (canvas) {
  var target = document.getElementById(canvas.dataset.target);
  var state = document.querySelector('.sig-state[data-target="' + canvas.dataset.target + '"]');
  var ctx = canvas.getContext('2d');
  var drawing = false, dirty = false;

  function resize() {
    var data = dirty ? canvas.toDataURL() : null;
    canvas.width = canvas.offsetWidth;
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f4c3a';
    if (data) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0); }; img.src = data; }
  }
  window.addEventListener('resize', resize);
  resize();

  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  }
  function start(e) { e.preventDefault(); drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
  function move(e) { if (!drawing) return; e.preventDefault(); var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); dirty = true; }
  function end() {
    if (!drawing) return;
    drawing = false;
    if (dirty) { target.value = canvas.toDataURL('image/png'); if (state) { state.textContent = 'تم التوقيع ✓'; state.className = 'text-success sig-state'; } }
  }

  canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move);
  document.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, {passive: false});
  canvas.addEventListener('touchmove', move, {passive: false});
  canvas.addEventListener('touchend', end);
});

document.querySelectorAll('.sig-clear').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var canvas = document.querySelector('.sig-pad[data-target="' + btn.dataset.target + '"]');
    var state = document.querySelector('.sig-state[data-target="' + btn.dataset.target + '"]');
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    document.getElementById(btn.dataset.target).value = '';
    if (state) { state.textContent = 'لم يُوقَّع بعد'; state.className = 'text-muted sig-state'; }
  });
});

// منع الإرسال بلا توقيع في الحقول الإلزامية (المتصفح لا يتحقق من حقل مخفي)
document.getElementById('fillForm')?.addEventListener('submit', function (e) {
  var missing = null;
  document.querySelectorAll('[data-field-type="signature"]').forEach(function (wrap) {
    var input = wrap.querySelector('input[type=hidden]');
    var required = wrap.querySelector('.form-label .text-danger');
    if (required && input && !input.value) { missing = wrap; }
  });
  if (missing) {
    e.preventDefault();
    missing.scrollIntoView({behavior: 'smooth', block: 'center'});
    alert('التوقيع مطلوب قبل الإرسال.');
  }
});
</script>
@endpush
@endsection
