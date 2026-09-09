@extends('layouts.app')
@section('page_title', 'تقرير البلاغات')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="{{ route('reports.dashboard', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <h1 class="h5 m-0">تقرير بلاغات الشاغل</h1>
</div>

@include('modules.reports._filters')

<div class="row g-2 mb-3">
  @foreach([
    ['المجموع', $data['total'], ''],
    ['عاجل', $data['by_type']['urgent'] ?? 0, 'text-danger'],
    ['عادي', $data['by_type']['normal'] ?? 0, 'text-warning'],
    ['سري', $data['by_type']['secret'] ?? 0, 'text-secondary'],
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
    <h2 class="h6 mb-2"><i class="bi bi-stopwatch"></i> الوصول إلى الفني</h2>
    <div class="row g-2">
      <div class="col-6 col-lg-3"><div class="border rounded p-2 text-center">
        <div class="h5 m-0" data-resp="avg">{{ $data['response']['avg_minutes'] === null ? 'لا بيانات' : $data['response']['avg_minutes'] }}</div>
        <small class="text-muted">متوسط الدقائق</small>
      </div></div>
      <div class="col-6 col-lg-3"><div class="border rounded p-2 text-center">
        <div class="h5 m-0" data-resp="max">{{ $data['response']['max_minutes'] === null ? 'لا بيانات' : $data['response']['max_minutes'] }}</div>
        <small class="text-muted">أطول انتظار</small>
      </div></div>
      {{-- عدد البلاغات التي حُسب منها المتوسط: متوسط بلا عدده لا يُقرأ --}}
      <div class="col-6 col-lg-3"><div class="border rounded p-2 text-center">
        <div class="h5 m-0" data-resp="count">{{ $data['response']['count'] }}</div>
        <small class="text-muted">بلاغاً قيس وصولها</small>
      </div></div>
      <div class="col-6 col-lg-3"><div class="border rounded p-2 text-center">
        <div class="h5 m-0" data-resp="pending">{{ $data['response']['pending'] }}</div>
        <small class="text-muted">لم يصل الفني بعد</small>
      </div></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">التوزيع بالحالة</h2>
    <div class="table-responsive">
      <table class="table table-sm m-0">
        <tbody>
          @foreach(\App\Modules\Incident\Models\Incident::STATUS_LABELS as $key => $label)
            @if(($data['by_status'][$key] ?? 0) > 0)
              <tr data-status="{{ $key }}">
                <td>{{ $label }}</td>
                <td class="text-end"><span class="badge bg-{{ \App\Modules\Incident\Models\Incident::STATUS_COLORS[$key] ?? 'secondary' }}">{{ $data['by_status'][$key] }}</span></td>
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h2 class="h6 mb-2">آخر عشرة بلاغات</h2>
    @if($data['recent']->isEmpty())
      <div class="text-muted small">لا بلاغات في هذه المدة.</div>
    @else
      <div class="table-responsive">
        <table class="table table-sm align-middle m-0">
          <thead><tr><th>الرمز</th><th>العنوان</th><th>المكان</th><th>الحالة</th><th>التاريخ</th></tr></thead>
          <tbody>
            @foreach($data['recent'] as $incident)
              <tr data-incident="{{ $incident->code }}">
                <td class="font-monospace small">{{ $incident->code }}</td>
                <td>{{ $incident->incident_type === 'secret' ? 'بلاغ سري' : $incident->title }}</td>
                <td class="small">{{ $incident->place?->name ?? '—' }}</td>
                <td><span class="badge bg-{{ \App\Modules\Incident\Models\Incident::STATUS_COLORS[$incident->status] ?? 'secondary' }}">{{ $incident->status_label }}</span></td>
                <td class="small text-muted">{{ $incident->created_at?->format('Y-m-d H:i') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>

@endsection
