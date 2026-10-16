@extends('layouts.app')
@section('title', 'خطة السلامة — '.$place->name)
@section('content')
{{-- المرحلة ٣٠-١ (قرار ٨٩ «كلام المكان»): الجدولان كما في الورقة من صفوف المكان؛ بجانب خانات الكلام «تعديل» و«لا ينطبق هنا»
     لمنسق المكان ومديره؛ المهمة للصنف لا تُعدَّل؛ الوثيقة الأصلية تبقى للمراجعة؛ ومسؤول السلامة يرى «عدّله فلان في كذا يوم كذا». --}}
@php($T = \App\Modules\Governance\Models\SafetyPlanTask::class)
@php($A = \App\Modules\Governance\Support\PlaceWordsAccess::class)
<style>
  .sp-table td,.sp-table th{vertical-align:top}
  .sp-table .sp-task{font-weight:700;min-width:14rem}
  .sp-table td.sp-ch{background:#fff8e1}
  .sp-table tr.sp-na td{opacity:.55}
  .sp-table tr.sp-na td.sp-task{text-decoration:line-through}
  .sp-table tr.sp-na td.sp-task .badge{text-decoration:none}
  .sp-form td{background:var(--tint)}
  .sp-src{color:#6b7a74;font-size:.8rem}
  /* الجوال: بطاقة لكل مهمة بدل أربعة أعمدة ضيقة — كل خانة بعنوانها، والنموذج تحت بطاقته */
  @media(max-width:700px){
    .sp-table thead{display:none}
    .sp-table,.sp-table tbody,.sp-table tr,.sp-table td{display:block;width:100%}
    .sp-table tr[hidden]{display:none}
    .sp-table tr[data-task]{border:1px solid var(--line);border-radius:10px;padding:8px 10px;margin-bottom:8px;background:#fff}
    .sp-table tr[data-task] td{border:0;padding:3px 0}
    .sp-table .sp-task{min-width:0;border-bottom:1px dashed var(--line)!important;padding-bottom:6px;margin-bottom:4px}
    .sp-table td[data-label]::before{content:attr(data-label) ": ";font-weight:700;color:var(--g)}
    .sp-table td.sp-ch{background:transparent;border-inline-start:3px solid var(--gold)!important;padding-inline-start:6px}
    .sp-table tr.sp-form{margin:-6px 0 10px}
    .sp-table tr.sp-form td{display:block;padding:8px 10px;border-radius:10px}
  }
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
  <a href="{{ route('app.home') }}#places">الأماكن</a><span class="text-muted">›</span>
  <a href="{{ route('app.places.units.file', $place) }}">{{ $place->name }}</a><span class="text-muted">›</span>
</div>
<h1 class="page-h mb-1">خطة السلامة — {{ $place->name }} <span class="small text-muted fw-normal" dir="ltr">{{ $place->code }}</span></h1>
<p class="small text-muted mb-2">
  @if(!empty($pl['sa']))<span class="badge st-ok">معتمدة</span> بتاريخ {{ $pl['sa'] }}{{ !empty($pl['saBy']) ? ' · '.$pl['saBy'] : '' }}
  @else<span class="badge text-bg-danger">غير معتمدة</span> تُعتمد بقرار الإدارة العليا@endif
  @if($plan) · <b>{{ $plan->tasks_count }}</b> مهام ({{ $plan->proactive_count }} استباقية · {{ $plan->operational_count }} تشغيلية)
    @if($plan->edited_count) · <b style="color:#7a5a00">{{ $plan->edited_count }}</b> معدّلة في هذا المكان @endif
  @endif
</p>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  @if($plan)<a class="btn btn-o btn-sm" href="{{ $plan->documentUrl() }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> الوثيقة الأصلية (للصنف)</a>@endif
  @if($canEdit)<span class="small text-muted">الشكل للمعهد كله، والكلام لهذا المكان: عدّل المسؤول ومتى وكيف والتكرار والتوثيق، أو اضغط «لا ينطبق هنا». المهمة نفسها لا تُعدَّل.</span>
  @else<span class="small text-muted">التعديل {{ $A::EDITORS }}.</span>@endif
</div>

@if(!$plan)
  <div class="card"><div class="card-body small text-muted">لم تُقرأ وثيقة خطة هذا الصنف بعد — الوثيقة غير موجودة على الخادم.</div></div>
@else
@foreach($T::LAYERS as $layer => $layerLabel)
  @php($cols = $T::COLUMNS[$layer])
  <h2 class="sec-h mt-3"><i class="bi bi-shield-check"></i> {{ $layerLabel }} <span class="small text-muted fw-normal">{{ count($byLayer[$layer] ?? []) }} مهام</span></h2>
  <div class="table-responsive"><table class="table table-sm align-middle sp-table bg-white">
    <thead><tr><th>المهمة</th>@foreach($cols as $label)<th>{{ $label }}</th>@endforeach @if($canEdit)<th></th>@endif</tr></thead>
    <tbody>
    @forelse($byLayer[$layer] ?? [] as $t)
      @php($changed = $t->changedFields())
      <tr id="t{{ $t->id }}" class="{{ $t->not_applicable ? 'sp-na' : '' }}" data-task="{{ $t->id }}" data-na="{{ $t->not_applicable ? 1 : 0 }}" data-edited="{{ $t->isEdited() ? 1 : 0 }}">
        <td class="sp-task">{{ $t->task }}
          @if($t->not_applicable)<span class="badge text-bg-secondary">لا ينطبق هنا</span>@endif
          @if($t->isEdited())<div class="sp-src fw-normal">عدّله {{ $t->edited_by_name }} · {{ $t->edited_at->format('Y-m-d') }}@if($t->changedLabels()) · {{ implode('، ', $t->changedLabels()) }}@endif</div>@endif
        </td>
        @foreach($cols as $f => $label)
          <td class="{{ in_array($f, $changed, true) ? 'sp-ch' : '' }}" data-f="{{ $f }}" data-label="{{ $label }}">{{ $t->{$f} ?? '—' }}
            @if(in_array($f, $changed, true))<div class="sp-src">الأصل: {{ $t->source[$f] ?? '—' }}</div>@endif
          </td>
        @endforeach
        @if($canEdit)<td class="text-nowrap"><button type="button" class="btn btn-o btn-sm sp-edit" data-for="e{{ $t->id }}" aria-controls="e{{ $t->id }}" aria-expanded="false"><i class="bi bi-pencil"></i> تعديل</button></td>@endif
      </tr>
      @if($canEdit)
      <tr id="e{{ $t->id }}" class="sp-form" @if($openTask !== $t->id) hidden @endif>
        <td colspan="{{ count($cols) + 2 }}">
          <form method="post" action="{{ route('app.places.team.safety.update', [$place, $t]) }}" data-task-form="{{ $t->id }}">@csrf
            <input type="hidden" name="_task" value="{{ $t->id }}">
            <div class="row g-2">
              @foreach($cols as $f => $label)
                <div class="col-12 col-md-4"><label class="form-label small mb-0" for="f{{ $t->id }}{{ $f }}">{{ $label }}</label>
                  @if($f === 'how_text')<textarea id="f{{ $t->id }}{{ $f }}" name="{{ $f }}" class="form-control form-control-sm" rows="3" maxlength="2000">{{ old($f, $t->{$f}) }}</textarea>
                  @else<input id="f{{ $t->id }}{{ $f }}" name="{{ $f }}" class="form-control form-control-sm" maxlength="255" value="{{ old($f, $t->{$f}) }}">@endif
                  @if(in_array($f, $changed, true))<div class="sp-src">الأصل في الوثيقة: {{ $t->source[$f] ?? '—' }}</div>@endif
                  @error($f)<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
              @endforeach
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <div class="form-check me-2"><input class="form-check-input" type="checkbox" name="not_applicable" value="1" id="na{{ $t->id }}" @checked(old('not_applicable', $t->not_applicable))><label class="form-check-label" for="na{{ $t->id }}">لا ينطبق هنا</label></div>
              <button class="btn btn-g btn-sm">حفظ</button>
              <button type="button" class="btn btn-o btn-sm sp-cancel" data-for="e{{ $t->id }}">إلغاء</button>
            </div>
          </form>
          @if($t->isEdited())<form method="post" action="{{ route('app.places.team.safety.restore', [$place, $t]) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0" data-restore="{{ $t->id }}">استعادة الأصل من الوثيقة</button></form>@endif
        </td>
      </tr>
      @endif
    @empty
      <tr><td colspan="{{ count($cols) + 2 }}" class="small text-muted">لا صفوف في هذه الطبقة.</td></tr>
    @endforelse
    </tbody>
  </table></div>
@endforeach

@if($log->isNotEmpty())
  <h2 class="sec-h mt-3"><i class="bi bi-clock-history"></i> سجل التعديلات <span class="small text-muted fw-normal">من عدّل، وفي كذا، ومتى</span></h2>
  <div class="card"><div class="card-body py-2">
    @foreach($log as $l)
      <div class="small border-bottom py-1" data-log="{{ $l->id }}"><b>{{ $l->user?->name ?? '—' }}</b> · {{ $l->created_at?->format('Y-m-d H:i') }} · {{ $l->description }}</div>
    @endforeach
  </div></div>
@endif
@endif

<script>
(function(){
  function show(id, open){ var r=document.getElementById(id); if(!r) return; r.hidden=!open; var b=document.querySelector('[data-for="'+id+'"].sp-edit'); if(b) b.setAttribute('aria-expanded', open?'true':'false'); if(open){ var i=r.querySelector('input:not([type=hidden]),textarea'); if(i) i.focus(); } }
  document.querySelectorAll('.sp-edit').forEach(function(b){ b.addEventListener('click', function(){ var r=document.getElementById(b.getAttribute('data-for')); show(b.getAttribute('data-for'), r && r.hidden); }); });
  document.querySelectorAll('.sp-cancel').forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-for'), false); }); });
  if(location.hash && /^#t\d+$/.test(location.hash)){ var h=document.getElementById(location.hash.slice(1)); if(h) h.scrollIntoView({block:'center'}); }
})();
</script>
@endsection
