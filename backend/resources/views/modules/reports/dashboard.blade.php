@extends('layouts.app')
@section('page_title', 'لوحة التقارير')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <div>
    <h1 class="h5 m-0">لوحة التقارير</h1>
    <div class="small text-muted">أرقام حية عبر الوحدات — تُحسب عند كل فتح، بلا ذاكرة مؤقتة</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="{{ route('reports.incidents', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-megaphone"></i> تقرير البلاغات</a>
    <a href="{{ route('reports.risks', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-exclamation"></i> تقرير المخاطر</a>
    <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i> تصدير</a>
  </div>
</div>

@include('modules.reports._filters')

{{-- فجوة الاستجابة أولاً: هي غاية الحزمة، لا رقم من جملة أرقام --}}
@php $r = $data['response']; @endphp
<div class="card mb-3 border-danger-subtle">
  <div class="card-body">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-stopwatch fs-5 text-danger"></i>
      <h2 class="h6 m-0">فجوة الاستجابة</h2>
      <span class="small text-muted">الزمن بين البلاغ والاستجابة الأولية — الرقم الذي تقوم عليه الحزمة</span>
    </div>
    <div class="row g-2">
      <div class="col-6 col-lg-3">
        <div class="border rounded p-2 text-center">
          <div class="h4 m-0" data-kpi="incident_avg">
            {{ $r['incident']['avg_minutes'] === null ? 'لا بيانات' : $r['incident']['avg_minutes'] }}
          </div>
          <small class="text-muted">متوسط وصول بلاغ الشاغل إلى الفني (دقيقة)</small>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="border rounded p-2 text-center">
          <div class="h4 m-0" data-kpi="incident_max">
            {{ $r['incident']['max_minutes'] === null ? 'لا بيانات' : $r['incident']['max_minutes'] }}
          </div>
          <small class="text-muted">أطول وصول للفني (دقيقة)</small>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="border rounded p-2 text-center">
          <div class="h4 m-0 {{ $r['incident']['pending'] ? 'text-warning' : '' }}" data-kpi="incident_pending">{{ $r['incident']['pending'] }}</div>
          <small class="text-muted">بلاغ لم يصل الفني بعد</small>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="border rounded p-2 text-center">
          <div class="h4 m-0 {{ $r['emergency']['unacknowledged'] ? 'text-danger' : '' }}" data-kpi="emergency_unack">{{ $r['emergency']['unacknowledged'] }}</div>
          <small class="text-muted">حالة طارئة بلا إقرار باستلام التنبيه</small>
        </div>
      </div>
    </div>
    @if($r['emergency']['avg_minutes'] !== null)
      <div class="small text-muted mt-2" data-kpi="emergency_avg">
        متوسط الإقرار بالحالة الطارئة: {{ $r['emergency']['avg_minutes'] }} دقيقة
        (من {{ $r['emergency']['count'] }} حالة).
      </div>
    @endif
  </div>
</div>

{{-- ما يحتاج قراراً --}}
@if(count($data['attention']))
  <div class="card mb-3">
    <div class="card-body py-2">
      <h2 class="h6 mb-2"><i class="bi bi-exclamation-circle text-warning"></i> ما يحتاج قراراً</h2>
      <ul class="list-unstyled m-0 small">
        @foreach($data['attention'] as $item)
          <li class="py-1 border-bottom" data-attention="{{ $item['route'] }}">
            <a href="{{ route($item['route']) }}" class="text-decoration-none">
              <span class="badge bg-warning text-dark">{{ $item['n'] }}</span> {{ $item['text'] }}
            </a>
          </li>
        @endforeach
      </ul>
    </div>
  </div>
@else
  <div class="alert alert-success py-2 small" data-attention="none">لا شيء ينتظر قراراً في هذه المدة.</div>
@endif

{{-- عدّادات الوحدات --}}
<div class="row g-2 mb-3">
  @foreach([
    ['بلاغات الشاغل', $data['incidents']['total'], $data['incidents']['open'].' مفتوح', 'bi-megaphone', 'incidents'],
    ['حالات طارئة', $data['emergency']['real'], $data['emergency']['drills'].' تمريناً', 'bi-exclamation-octagon', 'emergency'],
    ['مخاطر فعلية', $data['risks']['total'], $data['risks']['critical'].' حرجاً', 'bi-shield-exclamation', 'risks'],
    ['تصاريح', $data['permits']['total'], $data['permits']['active'].' نشطاً', 'bi-file-earmark-check', 'permits'],
    ['عمال', $data['workers']['total'], $data['workers']['authorized'].' مصرّحاً', 'bi-person-badge', 'workers'],
    ['تكليفات نماذج', $data['forms']['total'], ($data['forms']['response_pct'] === null ? 'لا بيانات' : $data['forms']['response_pct'].'٪ استجابة'), 'bi-ui-checks', 'forms'],
  ] as [$label, $value, $sub, $icon, $key])
    <div class="col-6 col-lg-2">
      <div class="card h-100"><div class="card-body text-center py-3">
        <i class="bi {{ $icon }} fs-5 text-muted"></i>
        <div class="h4 m-0 mt-1" data-count="{{ $key }}">{{ $value }}</div>
        <small class="text-muted d-block">{{ $label }}</small>
        <small class="text-muted">{{ $sub }}</small>
      </div></div>
    </div>
  @endforeach
</div>

{{-- المؤشرات --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up"></i> المؤشرات</h2>
    <div class="row g-2">
      @foreach([
        ['نسبة إغلاق البلاغات', $kpi['closure_rate'], '٪'],
        ['متوسط أيام الإغلاق', $kpi['avg_closure_days'], ' يوم'],
        ['نسبة التصعيد', $kpi['escalation_rate'], '٪'],
        ['متوسط درجة الخطر النشط', $kpi['avg_risk_score'], ''],
        ['نسبة تفعيل التصاريح', $kpi['permit_activation'], '٪'],
        ['نسبة إنهاء الحالات الطارئة', $kpi['emergency_end_rate'], '٪'],
      ] as [$label, $value, $unit])
        <div class="col-6 col-lg-2">
          <div class="border rounded p-2 text-center">
            <div class="h5 m-0" data-kpi-value="{{ $label }}">
              {{ $value === null ? 'لا بيانات' : $value.$unit }}
            </div>
            <small class="text-muted">{{ $label }}</small>
          </div>
        </div>
      @endforeach
    </div>
    <div class="small text-muted mt-2">«لا بيانات» تعني لا سجلات في المدة المختارة — ليست صفراً.</div>
  </div>
</div>

{{-- الأماكن التسعة --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-geo-alt"></i> الأماكن</h2>
    <div class="table-responsive">
      <table class="table table-sm align-middle m-0">
        <thead><tr>
          <th>المكان</th><th class="text-center">بلاغات</th><th class="text-center">حالات طارئة</th>
          <th class="text-center">مخاطر حرجة</th><th class="text-center">تصاريح نشطة</th>
        </tr></thead>
        <tbody>
          @foreach($data['by_place'] as $place)
            <tr data-place="{{ $place['code'] }}">
              <td>{{ $place['code'] }} — {{ $place['name'] }}</td>
              <td class="text-center">{{ $place['incidents'] }}</td>
              <td class="text-center">{{ $place['emergency'] }}</td>
              <td class="text-center {{ $place['critical_risks'] ? 'text-danger fw-bold' : '' }}">{{ $place['critical_risks'] }}</td>
              <td class="text-center">{{ $place['active_permits'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>

{{-- الاتجاه الشهري --}}
@if(count($data['by_month']))
  <div class="card">
    <div class="card-body">
      <h2 class="h6 mb-2"><i class="bi bi-bar-chart"></i> بلاغات الشاغل شهراً بشهر</h2>
      @php $max = max(array_column($data['by_month'], 'count')) ?: 1; @endphp
      @foreach($data['by_month'] as $month)
        <div class="d-flex align-items-center gap-2 mb-1 small" data-month="{{ $month['label'] }}">
          <span class="text-muted" style="min-width:5rem">{{ $month['label'] }}</span>
          <div class="progress flex-grow-1" style="height:14px">
            <div class="progress-bar" style="width: {{ (int) round($month['count'] / $max * 100) }}%"></div>
          </div>
          <span style="min-width:2rem">{{ $month['count'] }}</span>
        </div>
      @endforeach
    </div>
  </div>
@endif

@endsection
