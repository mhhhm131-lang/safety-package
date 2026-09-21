@extends('layouts.app')
@section('title', 'أستغيث الآن')

{{--
  ٢٢-٣ (د): الاستغاثة لكل حساب. كانت مبنية في الخلفية ولا تُرى إلا داخل لوحة مركز الطوارئ،
  ونية «أستغيث الآن» كانت تفتح اللوحة ولا تُطلق شيئاً (جرد ٢٢-١).
  هنا: أربعة أزرار كبيرة، كلٌّ بضغطة واحدة ترسل فعلاً — نماذج خادم، لا تعتمد على جافاسكربت.
--}}

@section('content')
<div class="container-fluid px-0" style="max-width:640px">

  @if($mine)
    <div class="card border-success border-2 mb-3">
      <div class="card-body d-flex align-items-start gap-3">
        <i class="bi bi-check-circle-fill text-success display-5 lh-1"></i>
        <div>
          <h1 class="h5 mb-1">استغاثتك وصلت المركز</h1>
          <div class="text-muted">
            {{ $mine->getTypeLabel() }}
            @if($mine->created_at) · {{ $mine->created_at->format('H:i') }} ({{ $mine->created_at->diffForHumans() }})@endif
            · الحالة: {{ $mine->getStatusLabel() }}
          </div>
          <div class="mt-2">ابقَ مكانك إن كنت آمناً. إن تغيّر الوضع اتصل بالمركز مباشرة.</div>
        </div>
      </div>
    </div>
  @else
    <div class="mb-3">
      <h1 class="h4 mb-1">أستغيث الآن</h1>
      <p class="text-muted mb-0">
        اضغط ما ينطبق. تصل استغاثتك إلى مركز السلامة فوراً باسمك
        @if($myPlace)ومكانك ({{ $myPlace->name }})@endif.
      </p>
    </div>

    <div class="d-grid gap-2 mb-3">
      @foreach($types as $key => [$label, $icon, $when])
        <form method="post" action="{{ route('emergency.sos.trigger') }}">
          @csrf
          <input type="hidden" name="alert_type" value="{{ $key }}">
          <button class="btn btn-danger btn-lg w-100 py-3 text-start d-flex align-items-center gap-3">
            <i class="bi {{ $icon }} fs-2"></i>
            <span class="flex-grow-1">
              <span class="d-block fw-bold fs-5">{{ $label }}</span>
              <span class="d-block small opacity-75">{{ $when }}</span>
            </span>
            <i class="bi bi-chevron-left"></i>
          </button>
        </form>
      @endforeach
    </div>
  @endif

  <div class="card">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small">مركز السلامة</span>
      <a class="btn btn-outline-danger" href="tel:{{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}">
        <i class="bi bi-telephone-fill"></i> {{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}
      </a>
      <span class="text-muted small ms-auto">الاتصال أسرع إن كنت تستطيع الكلام.</span>
    </div>
  </div>

  <a class="btn btn-link w-100 mt-2" href="{{ route('app.home') }}">رجوع</a>
</div>
@endsection
