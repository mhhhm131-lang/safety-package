@extends('layouts.public')
@section('page_title', 'بلاغ عن خطر')
@section('content')
@php($q = $place ? '?place='.e($place) : '')
<div class="card p-4 mb-3">
  <div class="card-h mb-1"><i class="bi bi-megaphone-fill me-1" style="color:var(--red)"></i> رأيت خطراً؟ أبلغ مركز السلامة الآن</div>
  <div class="text-muted small mb-3">لأي موظف أو متدرب أو زائر — بلا تسجيل دخول. يُقيَّد برقم ووقت في سجل بلاغات الشاغلين، وتتابعه برمز.
    @if($place) <span class="badge text-bg-success">المكان: {{ $places->firstWhere('code', $place)?->name ?? $place }}</span>@endif
  </div>
  {{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: اختر نوع البلاغ بميزته لك، لا بمصطلحاتنا --}}
  <div class="row g-3">
    <div class="col-md-4"><a class="type-card" href="{{ route('incident.form', 'normal') }}{{ $q }}" data-type="normal">
      <div class="t"><i class="bi bi-flag-fill me-1" style="color:var(--g)"></i>عادي</div>
      <div class="small mt-1 fw-bold" style="color:var(--g)">لا يُغلق إلا بموافقتك.</div>
      <div class="small mt-1">ملاحظة سلامة تحتاج معالجة: طفاية مفقودة، مخرج مسدود، سلك مكشوف، بلاط مكسور، رائحة غريبة. تتابعه برمز، وتُسأل في النهاية: هل عولج فعلاً؟</div>
    </a></div>
    <div class="col-md-6"><a class="type-card secret" href="{{ route('incident.form', 'secret') }}{{ $q }}" data-type="secret">
      <div class="t"><i class="bi bi-incognito me-1"></i>سري</div>
      <div class="small mt-1 fw-bold" style="color:#4a3d8f">يخفي هويتك تماماً.</div>
      <div class="small mt-1">لا اسم ولا هاتف ولا حساب، ولا يُسجَّل من أرسله. تستفيد منه المنظمة ويُعالج كالبقية، ولا يمنحك حق المطالبة.</div>
    </a></div>
    {{-- ٢٦-٣: بطاقة «عاجل» حُذفت — الزر الأحمر «طوارئ الآن» أسفل الشاشة يقوم مقامها --}}
  </div>
</div>
<div class="card p-3 d-flex flex-row align-items-center gap-3 flex-wrap">
  <i class="bi bi-search fs-4" style="color:var(--g)"></i>
  <div class="flex-grow-1"><b>أرسلت بلاغاً من قبل؟</b> <span class="text-muted small">تابع حالته وخطه الزمني برمز التتبع الذي أُعطيته.</span></div>
  <a class="btn btn-outline-success btn-sm" href="{{ route('incident.track') }}">تتبع بلاغ</a>
</div>
@include('modules.incidents._my_reports')
{{-- ٢٦-٤ (قرار ٦٦): كتلة «أريد أن…» حُذفت من هنا — الصفحة غرضها البلاغ؛ ما كان فيها له بابه: المنظومة وقنوات الإبلاغ والتتبع في ذيل الصفحة، والزر الأحمر للاتصال --}}
@endsection
