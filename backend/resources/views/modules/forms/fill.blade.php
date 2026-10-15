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
            @if($field->isSection())
              {{-- ٢٩-١: القسم عنوان بلا إجابة --}}
              <div class="mt-4 mb-3 pt-3 border-top" data-field="{{ $field->id }}" data-field-type="section">
                <h2 class="h6 fw-bold text-g m-0">{{ $field->label }}</h2>
                @if($field->help_text)<div class="form-text">{{ $field->help_text }}</div>@endif
              </div>
              @continue
            @endif
            <div class="mb-4" data-field="{{ $field->id }}" data-field-type="{{ $field->field_type }}">
              <label class="form-label fw-bold">
                {{ $field->label }}
                @if($field->is_required)<span class="text-danger">*</span>@endif
              </label>
              @if($field->help_text)<div class="form-text mb-1">{{ $field->help_text }}</div>@endif

              @switch($field->field_type)
                @case('table')
                  {{-- ٢٩-١: جدول بأعمدة التعريف؛ صفوف ثابتة من التعريف أو صفوف يضيفها المعبِّئ --}}
                  @php
                    $def = $field->tableDef();
                    $oldRows = (array) old('fields.'.$field->id, []);
                    $n = $def['rows'] ? count($def['rows']) : max($def['min_rows'], count($oldRows));
                  @endphp
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-1 form-table @error($key) border-danger @enderror" data-table="{{ $field->id }}">
                      <thead class="table-light">
                        <tr>
                          @foreach($def['columns'] as $col)<th class="small">{{ $col['label'] }}</th>@endforeach
                          @if($def['can_add'])<th style="width:2.2rem"></th>@endif
                        </tr>
                      </thead>
                      <tbody>
                        @for($r = 0; $r < $n; $r++)
                          <tr>
                            @foreach($def['columns'] as $c => $col)
                              @php
                                $name = "fields[{$field->id}][{$r}][{$c}]";
                                $val = ($def['rows'] && $col['type'] === 'fixed') ? $def['rows'][$r] : (string) ($oldRows[$r][$c] ?? '');
                              @endphp
                              <td>
                                @if($col['type'] === 'fixed')
                                  <span class="small fw-bold">{{ $val }}</span>
                                  <input type="hidden" name="{{ $name }}" value="{{ $val }}">
                                @elseif($col['type'] === 'select')
                                  <select name="{{ $name }}" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($col['options'] as $opt)<option value="{{ $opt }}" @selected($val === $opt)>{{ $opt }}</option>@endforeach
                                  </select>
                                @else
                                  <input type="text" name="{{ $name }}" class="form-control form-control-sm" value="{{ $val }}" maxlength="2000">
                                @endif
                              </td>
                            @endforeach
                            @if($def['can_add'])
                              <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 row-del" title="حذف الصف"><i class="bi bi-x-lg"></i></button></td>
                            @endif
                          </tr>
                        @endfor
                      </tbody>
                    </table>
                  </div>
                  @if($def['can_add'])
                    <button type="button" class="btn btn-sm btn-outline-secondary row-add" data-table="{{ $field->id }}"><i class="bi bi-plus"></i> صف</button>
                  @endif
                  @break

                @case('claim')
                  {{-- قرار ٨٨: ادعاء بدليل — نعم تعني «أرني» (سطر أو وثيقة)، لا جواب مقبول؛ وما يقرؤه النظام من السجل يُعرض قراءةً --}}
                  @php $cd = $field->claimDef(); $oldC = (array) old('fields.'.$field->id, []); $fact = $facts[$field->id] ?? null; @endphp
                  @if($fact)
                    <div class="rounded p-2 d-flex align-items-center gap-2 flex-wrap" style="background:#e8f0ed;border:1px solid #c5d9ce" data-claim-fact="{{ $field->id }}">
                      <span class="badge {{ $fact['claim'] === 'yes' ? 'bg-success' : 'bg-secondary' }}">{{ $fact['claim'] === 'yes' ? 'نعم' : 'لا' }}</span>
                      <span class="small">{{ $fact['evidence'] }}</span>
                      <span class="small text-muted">— يقرؤه النظام من سجل الإدارة</span>
                    </div>
                  @else
                    <div class="claim rounded p-2 @error($key.'.claim') border border-danger @enderror" style="background:#f6faf7;border:1px solid #cfe3d8" data-claim="{{ $field->id }}">
                      <div class="d-flex align-items-center gap-2 flex-wrap">
                        <label class="btn btn-sm btn-outline-success claim-btn {{ ($oldC['claim'] ?? '') === 'yes' ? 'active' : '' }}">
                          <input type="radio" class="claim-yn d-none" name="fields[{{ $field->id }}][claim]" value="yes" @checked(($oldC['claim'] ?? '') === 'yes') @required($field->is_required)> نعم
                        </label>
                        <label class="btn btn-sm btn-outline-secondary claim-btn {{ ($oldC['claim'] ?? '') === 'no' ? 'active' : '' }}">
                          <input type="radio" class="claim-yn d-none" name="fields[{{ $field->id }}][claim]" value="no" @checked(($oldC['claim'] ?? '') === 'no')> لا
                        </label>
                        <small class="text-muted">نعم تعني: أرني. لا جواب مقبول.</small>
                      </div>
                      <div class="claim-ev mt-2" @if(($oldC['claim'] ?? '') !== 'yes') hidden @endif>
                        <textarea name="fields[{{ $field->id }}][evidence]" rows="2" maxlength="2000"
                                  class="form-control form-control-sm @error($key.'.evidence') is-invalid @enderror"
                                  placeholder="أرني: {{ $cd['hint'] }}">{{ $oldC['evidence'] ?? '' }}</textarea>
                        @if($cd['attach'])
                          <input type="file" name="photos[{{ $field->id }}]" class="form-control form-control-sm mt-1 @error('photos.'.$field->id) is-invalid @enderror"
                                 accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,image/*">
                          <div class="form-text">أو ارفع الوثيقة: ملف واحد، ١٠ ميجابايت حداً أقصى.</div>
                        @endif
                        @error($key.'.evidence')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                      </div>
                      @error($key.'.claim')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                  @endif
                  @break

                @case('ack_item')
                  {{-- ٢٩-١: بند إقرار ثلاثي — صحيح؟ أفهمه؟ مطبَّق؟ و«ما الواقع» إلزامية عند أي «لا» --}}
                  @php $oldAck = (array) old('fields.'.$field->id, []); @endphp
                  <div class="ack-item rounded p-2 @error($key) border border-danger @enderror" style="background:#f6faf7;border:1px solid #cfe3d8" data-ack="{{ $field->id }}">
                    <div class="row g-2">
                      @foreach(\App\Modules\Form\Models\FormField::ACK_QUESTIONS as $k => $q)
                        <div class="col-md-4 d-flex align-items-center gap-2 flex-wrap">
                          <span class="small fw-bold" style="min-width:4.6rem">{{ $q }}</span>
                          @foreach(['نعم', 'لا'] as $yn)
                            <div class="form-check form-check-inline m-0">
                              <input type="radio" class="form-check-input ack-yn" name="fields[{{ $field->id }}][{{ $k }}]"
                                     id="f{{ $field->id }}_{{ $k }}_{{ $loop->index }}" value="{{ $yn }}"
                                     @checked(($oldAck[$k] ?? '') === $yn) @required($field->is_required)>
                              <label class="form-check-label" for="f{{ $field->id }}_{{ $k }}_{{ $loop->index }}">{{ $yn }}</label>
                            </div>
                          @endforeach
                        </div>
                      @endforeach
                    </div>
                    <div class="mt-2">
                      <label class="small text-muted" for="f{{ $field->id }}_note">ما الواقع؟ <span class="ack-note-req text-danger" hidden>— إلزامي عند أي «لا»</span></label>
                      <textarea name="fields[{{ $field->id }}][note]" id="f{{ $field->id }}_note" rows="2" maxlength="2000"
                                class="form-control form-control-sm @error($key.'.note') is-invalid @enderror">{{ $oldAck['note'] ?? '' }}</textarea>
                      @error($key.'.note')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                  </div>
                  @break

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
          @if($form->allows_anonymous)
            {{-- ٢٩-٢ (قرار ٨٨ §٤): سري افتراضاً، وله «أُعلن اسمي» --}}
            <div class="mx-3 mb-3 rounded p-3" style="background:#f6faf7;border:1px solid #cfe3d8" data-anonymous-box>
              <div class="small"><i class="bi bi-incognito"></i> <strong>إجابتك سرية.</strong> لا يُنسب جوابك إليك: لا يُحفظ معه اسم ولا رقم تكليف ولا جهاز، والتاريخ باليوم لا بالساعة. يُعلَّم تكليفك «مكتملاً» فقط.</div>
              <label class="small d-block mt-2">
                <input type="checkbox" name="reveal_name" value="1" class="form-check-input" @checked(old('reveal_name'))> أُعلن اسمي مع إجاباتي (اختياري)
              </label>
            </div>
          @endif
          <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">{{ $form->allows_anonymous ? 'لا يمكن التعديل بعد الإرسال.' : 'تُسجَّل تعبئتك باسمك ووقتها. لا يمكن التعديل بعد الإرسال.' }}</small>
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

// ٢٩-١: الجدول الحر — إضافة صف (نسخة من الأخير بلا قيم) وحذفه؛ أسماء الحقول fields[id][صف][عمود]
document.querySelectorAll('.row-add').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var body = document.querySelector('.form-table[data-table="' + btn.dataset.table + '"] tbody');
    var last = body.lastElementChild;
    var clone = last.cloneNode(true);
    var r = body.children.length;
    clone.querySelectorAll('input, select').forEach(function (el) {
      el.name = el.name.replace(/\[(\d+)\]\[(\d+)\]$/, '[' + r + '][$2]');
      if (el.tagName === 'SELECT') el.selectedIndex = 0; else if (el.type !== 'hidden') el.value = '';
    });
    body.appendChild(clone);
    var first = clone.querySelector('input[type=text], select'); if (first) first.focus();
  });
});
document.addEventListener('click', function (e) {
  var del = e.target.closest('.row-del'); if (!del) return;
  var body = del.closest('tbody');
  if (body.children.length > 1) del.closest('tr').remove();
  else del.closest('tr').querySelectorAll('input[type=text], select').forEach(function (el) { if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = ''; });
});

// قرار ٨٨: الادعاء — «نعم» تُظهر «أرني» وتجعل الزر ظاهراً مختاراً
document.querySelectorAll('.claim').forEach(function (box) {
  var ev = box.querySelector('.claim-ev');
  function sync() {
    var yes = box.querySelector('.claim-yn[value=yes]').checked;
    box.querySelectorAll('.claim-btn').forEach(function (l) { l.classList.toggle('active', l.querySelector('input').checked); });
    if (ev) ev.hidden = !yes;
  }
  box.querySelectorAll('.claim-yn').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
});

// ٢٩-١: بند الإقرار — أي «لا» يجعل «ما الواقع» إلزامية
document.querySelectorAll('.ack-item').forEach(function (box) {
  var note = box.querySelector('textarea'), flag = box.querySelector('.ack-note-req');
  function sync() {
    var anyNo = Array.prototype.some.call(box.querySelectorAll('.ack-yn:checked'), function (r) { return r.value === 'لا'; });
    note.required = anyNo; if (flag) flag.hidden = !anyNo;
  }
  box.querySelectorAll('.ack-yn').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
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
