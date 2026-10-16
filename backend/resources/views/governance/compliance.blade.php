@extends('layouts.app')
@section('title', 'المقارنة مع اللائحة — '.$building->name)
@section('content')
{{-- المرحلة ٣١-١ (قرار ٩٠): جدول المقارنة لمبنى الجلسة من الصفوف — المواد الست عشرة وبنود ١٢ و١٣ و١٤؛ نص اللائحة وما أعدّه مسؤول السلامة للصنف؛
     الحالة والوضع الحالي والإجراء والمكلّف والموعد والملاحظات كلام المبنى؛ الجاهزية من الصفوف. --}}
@php($M = \App\Modules\Emergency\Models\BuildingComplianceItem::class)
@php($AR = fn ($n) => strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']))
<style>
  .cp-art{border:1px solid var(--line);border-radius:10px;background:#fff;padding:10px 12px;margin-bottom:10px}
  .cp-no{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--g);color:#fff;font-weight:900;flex:none}
  .cp-title{font-weight:800;font-size:1.05rem}
  .cp-req{font-size:.9rem;color:#33373e;margin-top:4px}
  .cp-prep{font-size:.85rem;color:#6b7a74;margin-top:2px}
  .cp-row{border-top:1px dashed var(--line);padding:8px 0;margin-top:8px}
  .cp-row.item{margin-inline-start:14px}
  .cp-src{color:#6b7a74;font-size:.8rem}
  .cp-ch{background:#fff8e1;border-radius:6px;padding:1px 6px}
  .cp-form{background:var(--tint);border-radius:10px;padding:10px 12px;margin-top:8px}
  .cp-words{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 16px;font-size:.92rem}
  .cp-words b{color:var(--g)}
  .cp-ready{font-size:1.6rem;font-weight:900;color:var(--g)}
  @media(max-width:700px){.cp-words{grid-template-columns:1fr}.cp-row.item{margin-inline-start:6px}}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
  <a href="{{ route('emergency.buildings.show', $building) }}">{{ $building->name }}</a><span class="text-muted">›</span>
</div>
<h1 class="page-h mb-1">المقارنة مع اللائحة — {{ $building->name }}</h1>
<p class="mb-2" data-readiness="{{ $readiness['percent'] }}">
  <span class="cp-ready">{{ $readiness['percent'] }}%</span>
  <span class="small text-muted">· مستوفى {{ $readiness['met'] }} من {{ $readiness['ratable'] - $readiness['na'] }} · قيد الإعداد {{ $readiness['in_progress'] }} · غير مستوفى {{ $readiness['not_met'] }} · لم يُقيَّم {{ $readiness['unrated'] }} · لا ينطبق {{ $readiness['na'] }}</span>
</p>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <a class="btn btn-o btn-sm" href="/comparison.html" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> الوثيقة الأصلية</a>
  <a class="btn btn-o btn-sm" href="{{ route('app.role-cards', ['b' => $building->id]) }}"><i class="bi bi-person-vcard"></i> بطاقات المبنى</a>
  @if($canEdit)<span class="small text-muted">اللائحة واحدة للمعهد كله. لكل مادة وبند في مبناك: الحالة بضغطة، والوضع الحالي والإجراء والمكلّف والموعد والملاحظات. ما لم يُقيَّم يُعدّ غير مستوفى.</span>
  @else<span class="small text-muted">التعديل لمدير الفرع ومنسق سلامته ومدير المرافق ومسؤول السلامة.</span>@endif
</div>

@foreach($articles as $a)
  @php($items = $a->items())
  <div class="cp-art" id="a{{ $a->article_no }}" data-article="{{ $a->article_no }}">
    <div class="d-flex align-items-start gap-2">
      <span class="cp-no">{{ $AR($a->article_no) }}</span>
      <div class="flex-grow-1">
        <div class="cp-title">{{ $a->title }}
          @if($a->hasItems())@php($s = $a->itemsSummary())<span class="badge {{ $s['met'] === $s['total'] - $s['na'] && $s['total'] ? 'st-ok' : 'text-bg-light border text-dark' }}" data-summary="{{ $a->article_no }}">مستوفى {{ $s['met'] }} من {{ $s['total'] }}{{ $s['na'] ? ' · لا ينطبق '.$s['na'] : '' }}</span>@endif
        </div>
        @if($a->requirement)<div class="cp-req"><b>اللائحة:</b> {{ $a->requirement }}</div>@endif
        @if($a->prepared)<div class="cp-prep"><b>ما أعدّه مسؤول السلامة:</b> {{ $a->prepared }}</div>@endif
      </div>
    </div>
    @if($a->hasItems())
      @foreach($items as $r)@include('governance.compliance-row', ['r' => $r, 'isItem' => true])@endforeach
    @else
      @include('governance.compliance-row', ['r' => $a, 'isItem' => false])
    @endif
  </div>
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
  function show(id, open){ var r=document.getElementById(id); if(!r) return; r.hidden=!open; var b=document.querySelector('[data-for="'+id+'"].cp-edit'); if(b) b.setAttribute('aria-expanded', open?'true':'false'); if(open){ var i=r.querySelector('input:not([type=hidden]):not([type=radio]),textarea'); if(i) i.focus(); } }
  document.querySelectorAll('.cp-edit').forEach(function(b){ b.addEventListener('click', function(){ var r=document.getElementById(b.getAttribute('data-for')); show(b.getAttribute('data-for'), r && r.hidden); }); });
  document.querySelectorAll('.cp-cancel').forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-for'), false); }); });
  if(location.hash && /^#r\d+$/.test(location.hash)){ var h=document.getElementById(location.hash.slice(1)); if(h) h.scrollIntoView({block:'center'}); }
})();
</script>
@endsection
