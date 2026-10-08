@extends('layouts.app')
@section('title', 'معالجو أخطار إدارتي')
@section('content')
{{-- خطة المعالج — الخطوة ١ (٢٠٢٦-١٠-٠٨): مدير الإدارة المعالجة يسمّي أمام كل خطر من يعالجه — تخصصاً أو شخصاً. الاختيار يُحفظ بنفسه.
     بطاقة لكل خطر (لا جدول) حتى تُقرأ على الجوال؛ رسالة الحفظ يعرضها القالب فلا تُكرَّر هنا. --}}
<div class="d-flex align-items-center mb-1">
  <h1 class="h4 m-0">معالجو أخطار إدارتي <span class="text-muted fs-6">{{ $risks->count() }}</span></h1>
</div>
<p class="text-muted small mb-3">{{ $unitName ? "الأخطار التي علّق مسؤول السلامة عليها «{$unitName}» إدارةً معالجة." : '' }} اختر أمام كل خطر من يعالج بلاغه: تخصصاً من الفنيين، أو شخصاً من إدارتك. @if($missing)<span class="text-danger fw-bold">{{ $missing }} بلا معالج</span> — بلاغه يصل إليك حتى تسمّي.@endif</p>

@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

@if($risks->isEmpty())
  <div class="card"><div class="card-body text-muted">لا أخطار معلَّقة على إدارتك بعد. مسؤول السلامة يعلّق «الإدارة المعالجة» على الخطر في السجل العام، فيظهر هنا.</div></div>
@else
<div class="d-grid gap-2">
@foreach($risks as $r)
@php $cur = $r->handler_user_id ? 'user:'.$r->handler_user_id : ($r->handler_specialty ? 'spec:'.$r->handler_specialty : ''); @endphp
<div class="card {{ $cur === '' ? 'border-warning' : '' }}" data-risk="{{ $r->id }}" style="{{ $cur === '' ? 'background:#fff8e6;' : '' }}">
  <div class="card-body py-2">
    <div class="fw-bold">{{ $r->title }}</div>
    <div class="small text-muted mb-2">@if($r->code)<code dir="ltr">{{ $r->code }}</code> · @endif{{ $r->category?->name }}@if($r->subCategory) › {{ $r->subCategory->name }}@endif · الإدارة المعالجة: {{ $r->handling_unit_display }}</div>
    <form method="post" action="{{ route('risk.handlers.set', $r) }}">@csrf
      <label class="form-label small mb-1" for="h{{ $r->id }}">المعالج</label>
      <select name="handler" id="h{{ $r->id }}" class="form-select" onchange="this.form.submit()">
        <option value="" @selected($cur === '')>— بلا معالج (يصلني البلاغ) —</option>
        <optgroup label="تخصص (يصلح لكل الفروع)">
          @foreach($specialties as $key => $label)<option value="spec:{{ $key }}" @selected($cur === 'spec:'.$key)>{{ $label }}</option>@endforeach
        </optgroup>
        @if($people->isNotEmpty())
        <optgroup label="شخص من إدارتي">
          @foreach($people as $p)<option value="user:{{ $p->id }}" @selected($cur === 'user:'.$p->id)>{{ $p->name }} — {{ $p->roleName() }}</option>@endforeach
        </optgroup>
        @endif
      </select>
      <noscript><button class="btn btn-sm btn-g mt-1">حفظ</button></noscript>
    </form>
    @if($r->handlerSetBy)<div class="small text-muted mt-1">كتبه {{ $r->handlerSetBy->name }}@if($r->handler_set_at) · {{ $r->handler_set_at->format('Y-m-d') }}@endif</div>@endif
  </div>
</div>
@endforeach
</div>
@endif
@endsection
