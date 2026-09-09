@extends('layouts.app')
@section('page_title', 'تقرير المخاطر')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="{{ route('reports.dashboard', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">تقرير المخاطر الفعلية</h1>
    <div class="small text-muted">مخاطر الإدارات والأماكن وحدها — الكتاب والسجل العام مرجعان لا واقع</div>
  </div>
</div>

@include('modules.reports._filters')
<div class="small text-muted mb-3">المخاطر حالة قائمة لا حدثاً في فترة، فلا تتأثر بالمدة. المكان والوحدة يؤثران.</div>

<div class="row g-2 mb-3">
  @foreach([
    ['المجموع', $data['total'], ''],
    ['حرج (١٥ فأكثر)', $data['critical'], 'text-danger'],
    ['متوسط (٨–١٤)', $data['medium'], 'text-warning'],
    ['منخفض (أقل من ٨)', $data['low'], 'text-success'],
  ] as [$label, $value, $color])
    <div class="col-6 col-lg-3">
      <div class="card"><div class="card-body text-center py-3">
        <div class="h4 m-0 {{ $color }}" data-stat="{{ $label }}">{{ $value }}</div>
        <small class="text-muted">{{ $label }}</small>
      </div></div>
    </div>
  @endforeach
</div>

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">مصفوفة ٥×٥</h2>
    <div class="table-responsive">
      <table class="table table-sm text-center align-middle m-0" style="max-width:36rem">
        <thead>
          <tr><th class="text-muted small">الاحتمال \ الشدة</th>
            @for($s = 1; $s <= 5; $s++)<th class="small">{{ $s }}</th>@endfor
          </tr>
        </thead>
        <tbody>
          @for($l = 5; $l >= 1; $l--)
            <tr>
              <th class="small text-muted">{{ $l }}</th>
              @for($s = 1; $s <= 5; $s++)
                @php
                  $n = $data['matrix'][$l][$s] ?? 0;
                  $score = $l * $s;
                  $bg = $score >= 15 ? 'bg-danger-subtle' : ($score >= 8 ? 'bg-warning-subtle' : 'bg-success-subtle');
                @endphp
                <td class="{{ $n ? $bg : '' }}" data-cell="{{ $l }}-{{ $s }}">{{ $n ?: '' }}</td>
              @endfor
            </tr>
          @endfor
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h2 class="h6 mb-2">أشد عشرة مخاطر</h2>
    @if($data['top']->isEmpty())
      <div class="text-muted small">لا مخاطر فعلية في هذا النطاق.</div>
    @else
      <div class="table-responsive">
        <table class="table table-sm align-middle m-0">
          <thead><tr><th>الرمز</th><th>الخطر</th><th>المكان</th><th>الإدارة</th><th class="text-center">الدرجة</th><th>الحالة</th></tr></thead>
          <tbody>
            @foreach($data['top'] as $risk)
              <tr data-risk="{{ $risk->code }}">
                <td class="font-monospace small">{{ $risk->code }}</td>
                <td>{{ $risk->title }}</td>
                <td class="small">{{ $risk->place?->name ?? '—' }}</td>
                <td class="small">{{ $risk->organizationUnit?->name ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-{{ $risk->risk_score >= 15 ? 'danger' : ($risk->risk_score >= 8 ? 'warning text-dark' : 'success') }}">{{ $risk->risk_score }}</span></td>
                <td class="small">{{ \App\Modules\Risk\Models\Risk::STATUS_LABELS[$risk->status] ?? $risk->status }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

@endsection
