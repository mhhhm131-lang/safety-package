@extends('layouts.app')
@section('page_title', 'التقرير الشهري للتصاريح')
@section('content')
@php($months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'])

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <div>
    <h1 class="h4 m-0"><i class="bi bi-file-earmark-bar-graph"></i> التقرير الشهري للتصاريح</h1>
    <div class="small text-muted">من {{ $data['period']['starts_at'] }} إلى {{ $data['period']['ends_at'] }}</div>
  </div>
  <form method="get" class="ms-auto d-flex gap-2 align-items-end">
    <div>
      <label class="form-label small mb-1">السنة</label>
      <select name="year" class="form-select form-select-sm">
        @for($y = now()->year; $y >= now()->year - 3; $y--)
          <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
        @endfor
      </select>
    </div>
    <div>
      <label class="form-label small mb-1">الشهر</label>
      <select name="month" class="form-select form-select-sm">
        @foreach($months as $i => $m)
          <option value="{{ $i + 1 }}" @selected($i + 1 == $month)>{{ $m }}</option>
        @endforeach
      </select>
    </div>
    <button class="btn btn-sm btn-outline-primary">عرض</button>
    <a href="{{ route('permits.dashboard') }}" class="btn btn-sm btn-outline-secondary">اللوحة</a>
  </form>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-3">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-file-earmark-check"></i> التصاريح</div>
      <div class="card-body p-0">
        @foreach([
          'المُصدَر هذا الشهر' => 'issued', 'النشط الآن' => 'active_now', 'المكتمل' => 'completed',
          'المنتهي' => 'expired', 'المرفوض' => 'rejected',
        ] as $label => $key)
          <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
            <span class="text-muted">{{ $label }}</span><strong>{{ $data['permits'][$key] }}</strong>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-collection"></i> المُصدَر بحسب الفئة</div>
      <div class="card-body p-0">
        @foreach([
          'تأهيل' => 'by_qualification', 'عمل' => 'by_work', 'خاص' => 'by_special',
          'عامل' => 'by_worker', 'معدة' => 'by_equipment',
        ] as $label => $key)
          <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
            <span class="text-muted">{{ $label }}</span><strong>{{ $data['permits'][$key] }}</strong>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-exclamation-diamond"></i> الانحرافات والبلاغات</div>
      <div class="card-body p-0">
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">انحرافات سُجّلت</span><strong>{{ $data['deviations']['recorded'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">منها عالية الخطورة</span><strong class="text-danger">{{ $data['deviations']['high'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">مفتوحة الآن</span><strong>{{ $data['deviations']['open'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">بلاغات مرتبطة بتصاريح</span><strong>{{ $data['incidents_linked_to_permits'] }}</strong></div>
      </div>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-person-check"></i> فحص جاهزية العمال</div>
      <div class="card-body p-0">
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">مرات الفحص</span><strong>{{ $data['gate']['checks'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">سُمح</span><strong class="text-success">{{ $data['gate']['allowed'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">مُنع</span><strong class="text-danger">{{ $data['gate']['denied'] }}</strong></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-truck"></i> المعدات</div>
      <div class="card-body p-0">
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">مسجَّلة</span><strong>{{ $data['equipment']['total'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">تجاوزت موعد الفحص</span><strong class="text-danger">{{ $data['equipment']['overdue_inspection'] }}</strong></div>
        <div class="d-flex justify-content-between px-3 py-2 border-bottom small">
          <span class="text-muted">خارج الخدمة أو في الصيانة</span><strong>{{ $data['equipment']['out_of_service'] }}</strong></div>
      </div>
    </div>
  </div>
</div>

<div class="text-center small text-muted mt-4">
  يُطبع هذا التقرير للجهات (الموارد البشرية والتنمية الاجتماعية، الدفاع المدني).
  النسخة الآلية بصيغة JSON على العنوان نفسه بترويسة <span dir="ltr">Accept: application/json</span>.
</div>
@endsection
