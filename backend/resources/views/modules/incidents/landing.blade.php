@extends('layouts.public')
@section('page_title', 'بلاغ عن خطر')
@section('content')
@php($q = $place ? '?place='.e($place) : '')
<div class="card p-4 mb-3">
  <div class="card-h mb-1"><i class="bi bi-megaphone-fill me-1" style="color:var(--red)"></i> رأيت خطراً؟ أبلغ مركز السلامة الآن</div>
  <div class="text-muted small mb-3">لأي موظف أو متدرب أو زائر — بلا تسجيل دخول. يُقيَّد برقم ووقت في سجل بلاغات الشاغلين، وتتابعه برمز.
    @if($place) <span class="badge text-bg-success">المكان: {{ $places->firstWhere('code', $place)?->name ?? $place }}</span>@endif
  </div>
  <div class="row g-3">
    <div class="col-md-4"><a class="type-card urgent" href="{{ route('incident.form', 'urgent') }}{{ $q }}">
      <div class="t"><i class="bi bi-exclamation-octagon-fill me-1"></i>عاجل</div>
      <div class="small mt-1">خطر قائم الآن يهدد الناس أو المكان: حريق، دخان، تسرب، سقوط وشيك، شخص مصاب.</div>
      <div class="small mt-2 fw-bold" style="color:var(--red)">وفي الخطر الداهم اتصل أولاً: ٩٩٨ الدفاع المدني · ٩٩٧ الهلال الأحمر</div>
    </a></div>
    <div class="col-md-4"><a class="type-card" href="{{ route('incident.form', 'normal') }}{{ $q }}">
      <div class="t"><i class="bi bi-flag-fill me-1" style="color:var(--g)"></i>عادي</div>
      <div class="small mt-1">ملاحظة سلامة تحتاج معالجة: طفاية مفقودة، مخرج مسدود، سلك مكشوف، بلاط مكسور، رائحة غريبة.</div>
    </a></div>
    <div class="col-md-4"><a class="type-card secret" href="{{ route('incident.form', 'secret') }}{{ $q }}">
      <div class="t"><i class="bi bi-incognito me-1"></i>سري</div>
      <div class="small mt-1">هويتك مخفية تماماً. البلاغ يُرى ويُعالج كالبقية، ولا يُسجَّل من أرسله.</div>
    </a></div>
  </div>
</div>
<div class="card p-3 d-flex flex-row align-items-center gap-3 flex-wrap">
  <i class="bi bi-search fs-4" style="color:var(--g)"></i>
  <div class="flex-grow-1"><b>أرسلت بلاغاً من قبل؟</b> <span class="text-muted small">تابع حالته وخطه الزمني برمز التتبع الذي أُعطيته.</span></div>
  <a class="btn btn-outline-success btn-sm" href="{{ route('incident.track') }}">تتبع بلاغ</a>
</div>
{{-- المرحلة ١٢ (قرار ٣٥): «أريد أن…» للشاغل والزائر بلا دخول --}}
@if(!empty($intents))
<div class="card p-3 mt-3" id="intents">
  <div class="fw-bold mb-2"><i class="bi bi-hand-index-thumb me-1"></i> أريد أن…</div>
  <div class="d-flex flex-wrap gap-2">
    @foreach($intents as $i)
      <a class="btn btn-sm {{ $i->primary ? 'btn-g' : 'btn-outline-success' }}" href="{{ $i->url }}" data-intent="{{ $i->key }}" title="{{ $i->hint }}"><i class="bi {{ $i->icon }}"></i> {{ $i->label }}</a>
    @endforeach
  </div>
</div>
@endif
@endsection
