@extends('layouts.app')
@section('page_title', 'رموز QR للأماكن')
@section('content')
<style>
  .qr-card{border:1px solid #d9e2de;border-radius:10px;padding:14px;text-align:center;background:#fff;page-break-inside:avoid}
  .qr-card .code{font-weight:900;color:#0f4c3a}
  .qr-card .url{font-size:11px;color:#6b7a74;direction:ltr}
  @media print{ .side,.topbar,.no-print{display:none!important} main{width:100%!important} .qr-card{margin-bottom:10mm} }
</style>
<div class="d-flex align-items-center gap-2 mb-3 no-print">
  <h1 class="h4 m-0">رموز QR لبلاغ الشاغل</h1>
  <span class="small text-muted">مسح الرمز يفتح صفحة البلاغ والمكان محدد مسبقاً. يُطبع ويُلصق عند مدخل المكان.</span>
  <button class="btn btn-sm btn-g ms-auto" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
</div>
<div class="row g-3">
  @foreach($places as $p)
    <div class="col-md-4 col-6">
      <div class="qr-card">
        <div class="code">{{ $p->code }}</div>
        <div class="fw-bold mb-2">{{ $p->name }}</div>
        <div class="d-flex justify-content-center" id="qr-{{ $p->code }}"></div>
        <div class="mt-2 fw-bold small">رأيت خطراً؟ امسح وأبلغ</div>
        <div class="url">{{ $p->qr_url }}</div>
      </div>
    </div>
  @endforeach
</div>
@endsection
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  @foreach($places as $p)
    new QRCode(document.getElementById('qr-{{ $p->code }}'), {text: @json($p->qr_url), width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M});
  @endforeach
</script>
@endpush
