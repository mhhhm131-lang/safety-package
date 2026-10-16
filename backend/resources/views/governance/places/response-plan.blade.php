@extends('layouts.app')
@section('title', 'خطة الاستجابة — '.$place->name)
@section('content')
{{-- المرحلة ٣٠-٢ (قرار ٨٩ «كلام المكان»): المسارات والخطوات كما في الورقة من صفوف المكان؛ بجانب كلام المكان (من، أين، كيف، الهاتف)
     «تعديل» و«لا ينطبق هنا» لمنسق المكان ومديره؛ متى والنافذة والعنوان للصنف؛ القائمة الحية وقت الطوارئ تقرأ هذه الصفوف. --}}
@php($S = \App\Modules\Emergency\Models\ResponsePlanStep::class)
@php($RP = \App\Modules\Emergency\Models\ResponsePlan::class)
@php($A = \App\Modules\Governance\Support\PlaceWordsAccess::class)
@php($cols = $S::WORD_LABELS)
<style>
  .rp-table td,.rp-table th{vertical-align:top}
  .rp-table .rp-title{font-weight:700;min-width:12rem}
  .rp-table td.rp-ch{background:#fff8e1}
  .rp-table tr.rp-na td{opacity:.55}
  .rp-table tr.rp-na td.rp-title{text-decoration:line-through}
  .rp-table tr.rp-na td.rp-title .badge{text-decoration:none}
  .rp-form td{background:var(--tint)}
  .rp-src{color:#6b7a74;font-size:.8rem}
  @media(max-width:700px){
    .rp-table thead{display:none}
    .rp-table,.rp-table tbody,.rp-table tr,.rp-table td{display:block;width:100%}
    .rp-table tr[hidden]{display:none}
    .rp-table tr[data-step]{border:1px solid var(--line);border-radius:10px;padding:8px 10px;margin-bottom:8px;background:#fff}
    .rp-table tr[data-step] td{border:0;padding:3px 0}
    .rp-table .rp-title{min-width:0;border-bottom:1px dashed var(--line)!important;padding-bottom:6px;margin-bottom:4px}
    .rp-table td[data-label]::before{content:attr(data-label) ": ";font-weight:700;color:var(--g)}
    .rp-table td.rp-ch{background:transparent;border-inline-start:3px solid var(--gold)!important;padding-inline-start:6px}
    .rp-table tr.rp-form{margin:-6px 0 10px}
    .rp-table tr.rp-form td{display:block;padding:8px 10px;border-radius:10px}
  }
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
  <a href="{{ route('app.home') }}#places">الأماكن</a><span class="text-muted">›</span>
  <a href="{{ route('app.places.units.file', $place) }}">{{ $place->name }}</a><span class="text-muted">›</span>
</div>
<h1 class="page-h mb-1">خطة الاستجابة — {{ $place->name }} <span class="small text-muted fw-normal" dir="ltr">{{ $place->code }}</span></h1>
<p class="small text-muted mb-2">
  @if(!empty($pl['ra']))<span class="badge st-ok">معتمدة</span> {{ $pl['ra'] }}{{ !empty($pl['drill']) ? ' · آخر تمرين '.$pl['drill'] : ' · لم يُنفَّذ تمرين' }}
  @else<span class="badge text-bg-danger">غير معتمدة</span>@endif
  · <b>{{ $plan->steps_count }}</b> خطوة تدخل القائمة الحية · الكشف {{ $plan->detection_count }}@if($plan->scenario_count) · سيناريوهات {{ $plan->scenario_count }}@endif
  @if($plan->no_card_count) · <span class="text-danger">{{ $plan->no_card_count }} بلا بطاقة</span>@endif
  @if($plan->edited_count) · <b style="color:#7a5a00">{{ $plan->edited_count }}</b> معدّلة في هذا المكان @endif
</p>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <a class="btn btn-o btn-sm" href="{{ $plan->documentUrl() }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> الوثيقة الأصلية (للصنف)</a>
  <a class="btn btn-o btn-sm" href="{{ route('app.role-cards', ['b' => $place->building_id]) }}"><i class="bi bi-person-vcard"></i> بطاقات المبنى — المكلّفون وهواتفهم</a>
  @if($canEdit)<span class="small text-muted">الشكل للمعهد كله، والكلام لهذا المكان: عدّل من وأين وكيف والهاتف، أو اضغط «لا ينطبق هنا». العنوان ومتى والنافذة لا تُعدَّل. ما تعدّله هنا هو ما تقرؤه القائمة الحية وقت الطوارئ.</span>
  @else<span class="small text-muted">التعديل {{ $A::EDITORS }}.</span>@endif
</div>

@foreach(['detection', 'scenario', 'medical', 'fire', 'other'] as $key)
  @continue(empty($byPath[$key]))
  @php($steps = $byPath[$key])
  <h2 class="sec-h mt-3"><i class="bi bi-list-ol"></i> {{ $steps[0]->path_title }}
    <span class="badge text-bg-{{ in_array($key, $RP::LIVE_PATHS) ? 'success' : 'secondary' }} fw-normal">{{ $RP::PATHS[$key] }}</span>
    <span class="small text-muted fw-normal">{{ count($steps) }} خطوة{{ in_array($key, $RP::LIVE_PATHS) ? ' · تدخل القائمة الحية عند التفعيل' : '' }}</span></h2>
  <div class="table-responsive"><table class="table table-sm align-middle rp-table bg-white">
    <thead><tr><th>#</th><th>متى</th><th>النافذة</th><th>الخطوة</th>@foreach($cols as $label)<th>{{ $label }}</th>@endforeach<th>البطاقة</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>
    @foreach($steps as $s)
      @php($changed = $s->changedFields())
      <tr id="s{{ $s->id }}" class="{{ $s->not_applicable ? 'rp-na' : '' }}" data-step="{{ $s->id }}" data-na="{{ $s->not_applicable ? 1 : 0 }}" data-edited="{{ $s->isEdited() ? 1 : 0 }}">
        <td><b>{{ $s->label }}</b></td>
        <td data-label="متى">{{ $s->when_text ?? '—' }}</td>
        <td data-label="النافذة" class="text-muted">{{ $s->windowLabel() }}</td>
        <td class="rp-title">{{ $s->title }}
          @if($s->not_applicable)<span class="badge text-bg-secondary">لا ينطبق هنا</span>@endif
          @if($s->isEdited())<div class="rp-src fw-normal">عدّله {{ $s->edited_by_name }} · {{ $s->edited_at->format('Y-m-d') }}@if($s->changedLabels()) · {{ implode('، ', $s->changedLabels()) }}@endif</div>@endif
        </td>
        @foreach($cols as $f => $label)
          <td class="{{ in_array($f, $changed, true) ? 'rp-ch' : '' }}" data-f="{{ $f }}" data-label="{{ $label }}">@if($f === 'phone_text' && $s->phone_text)<a href="tel:{{ $s->phone_text }}" dir="ltr">{{ $s->phone_text }}</a>@else{{ $s->{$f} ?? '—' }}@endif
            @if(in_array($f, $changed, true))<div class="rp-src">الأصل: {{ $s->source[$f] ?? '—' }}</div>@endif
          </td>
        @endforeach
        <td data-label="البطاقة">
          @forelse($s->cards() as $no => $c)
            {{-- ٣٠-٣: بطاقة المبنى — اسمها وشاغلها وهاتفه من صفّها --}}
            <div class="mb-1"><a href="{{ $c['url'] }}" target="_blank" class="badge text-bg-{{ $no === $s->role_card_no ? 'dark' : 'light border text-dark' }} text-decoration-none" title="{{ $c['name'] }}">{{ $no }} {{ $c['name'] }}</a>
              @if($c['not_applicable'])<span class="badge text-bg-secondary">لا ينطبق هنا</span>@endif
              @if($c['holder_name'] || $c['holder_phone'])<small class="d-block">{{ $c['holder_name'] ?? '' }}@if($c['holder_phone']) · <a href="tel:{{ $c['holder_phone'] }}" dir="ltr">{{ $c['holder_phone'] }}</a>@endif</small>@endif</div>
          @empty
            <span class="badge {{ $s->isLive() && !$s->not_applicable ? 'text-bg-danger' : 'text-bg-light border text-muted' }}">بلا بطاقة</span>
          @endforelse
        </td>
        @if($canEdit)<td class="text-nowrap"><button type="button" class="btn btn-o btn-sm rp-edit" data-for="e{{ $s->id }}" aria-controls="e{{ $s->id }}" aria-expanded="false"><i class="bi bi-pencil"></i> تعديل</button></td>@endif
      </tr>
      @if($canEdit)
      <tr id="e{{ $s->id }}" class="rp-form" @if($openStep !== $s->id) hidden @endif>
        <td colspan="{{ 5 + count($cols) + 1 }}">
          <form method="post" action="{{ route('app.places.team.response.update', [$place, $s]) }}" data-step-form="{{ $s->id }}">@csrf
            <input type="hidden" name="_step" value="{{ $s->id }}">
            <div class="row g-2">
              @foreach($cols as $f => $label)
                <div class="col-12 col-md-3"><label class="form-label small mb-0" for="f{{ $s->id }}{{ $f }}">{{ $label }}</label>
                  @if($f === 'how_text')<textarea id="f{{ $s->id }}{{ $f }}" name="{{ $f }}" class="form-control form-control-sm" rows="3" maxlength="2000">{{ old($f, $s->{$f}) }}</textarea>
                  @else<input id="f{{ $s->id }}{{ $f }}" name="{{ $f }}" class="form-control form-control-sm" maxlength="{{ $f === 'phone_text' ? 60 : 255 }}" value="{{ old($f, $s->{$f}) }}" @if($f === 'phone_text') dir="ltr" inputmode="tel" placeholder="رقم يُنادى وقت الحالة" @endif>@endif
                  @if(in_array($f, $changed, true))<div class="rp-src">الأصل في الوثيقة: {{ $s->source[$f] ?? '—' }}</div>@endif
                  @error($f)<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
              @endforeach
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <div class="form-check me-2"><input class="form-check-input" type="checkbox" name="not_applicable" value="1" id="na{{ $s->id }}" @checked(old('not_applicable', $s->not_applicable))><label class="form-check-label" for="na{{ $s->id }}">لا ينطبق هنا — لا تدخل القائمة الحية</label></div>
              <button class="btn btn-g btn-sm">حفظ</button>
              <button type="button" class="btn btn-o btn-sm rp-cancel" data-for="e{{ $s->id }}">إلغاء</button>
            </div>
          </form>
          @if($s->isEdited())<form method="post" action="{{ route('app.places.team.response.restore', [$place, $s]) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0" data-restore="{{ $s->id }}">استعادة الأصل من الوثيقة</button></form>@endif
        </td>
      </tr>
      @endif
    @endforeach
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

<script>
(function(){
  function show(id, open){ var r=document.getElementById(id); if(!r) return; r.hidden=!open; var b=document.querySelector('[data-for="'+id+'"].rp-edit'); if(b) b.setAttribute('aria-expanded', open?'true':'false'); if(open){ var i=r.querySelector('input:not([type=hidden]),textarea'); if(i) i.focus(); } }
  document.querySelectorAll('.rp-edit').forEach(function(b){ b.addEventListener('click', function(){ var r=document.getElementById(b.getAttribute('data-for')); show(b.getAttribute('data-for'), r && r.hidden); }); });
  document.querySelectorAll('.rp-cancel').forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-for'), false); }); });
  if(location.hash && /^#s\d+$/.test(location.hash)){ var h=document.getElementById(location.hash.slice(1)); if(h) h.scrollIntoView({block:'center'}); }
})();
</script>
@endsection
