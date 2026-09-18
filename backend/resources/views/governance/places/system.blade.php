@extends('layouts.app')
@section('title', $s['name'].' — '.$place->name)
@section('content')
{{-- المرحلة ١٩-٢ (قرار ٤٨): ملف النظام — ما كان في dashboard.html#place=…&sys=… بالبيانات نفسها. قراءة؛ الفحص في النموذج. --}}
@php($R = \App\Modules\Store\Services\InspectionDocReader::class)
@php($ST = ['ok' => 'st-ok', 'late' => 'st-late', 'fault' => 'st-wait', 'none' => 'text-bg-secondary'])
@php($MK = ['ok' => ['✓', 'text-success'], 'no' => ['✗', 'text-danger'], 'na' => ['لا ينطبق', 'text-muted'], '' => ['—', 'text-muted']])
@php($openN = count(array_filter($s['reports'], fn ($r) => !$R::isClosed($r))))
<div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
  <a href="{{ route('app.places.units.hub') }}">الأماكن</a><span class="text-muted">›</span>
  <a href="{{ route('app.places.units.file', $place) }}">{{ $place->name }}</a><span class="text-muted">›</span>
</div>
<h1 class="page-h mb-1">{{ $s['name'] }} <span class="small text-muted fw-normal" dir="ltr">{{ $place->code }}{{ $s['code'] ? ' · '.$s['code'] : '' }}</span></h1>
<p class="small text-muted mb-2" id="sfSum" data-st="{{ $s['st'] }}">
  <span class="badge {{ $ST[$s['st']] }}">{{ $R::STATE_LABELS[$s['st']] }}</span>
  آخر فحص <b>{{ $s['last']['d'] ?? '—' }}</b>@if($s['next']) · المستحق القادم <b>{{ $s['next'] }}</b>{{ $s['freq'] ? ' ('.$s['freq'].')' : '' }}@endif
  · {{ count($s['rounds']) }} جولة مسجّلة · {{ count($s['reports']) }} بلاغ ({{ $openN }} مفتوح)
</p>
@if($ui)<div class="mb-3"><a class="btn btn-g btn-sm" href="/{{ $s['form']['file'] }}#sys={{ $s['k'] }}"><i class="bi bi-clipboard-check"></i> افحص هذا النظام</a></div>@endif

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100"><div class="card-body">
      <h2 class="sec-h"><i class="bi bi-journal-check"></i> سجل الجولات <span class="small text-muted fw-normal">كل جولة سطر، حتى السليمة</span></h2>
      @if(!count($s['rounds']))<div class="small text-muted">لم تُسجَّل جولة لهذا النظام بعد.</div>
      @else
      <div class="table-responsive"><table class="table table-sm align-middle m-0" id="sfRounds">
        <thead><tr><th>التاريخ</th><th>الفاحص</th><th class="text-center">✓</th><th class="text-center">✗</th><th class="text-center text-nowrap">لا ينطبق / البنود</th></tr></thead>
        <tbody>
        @foreach($s['rounds'] as $i => $r)
          <tr data-round="{{ $i }}"><td class="text-nowrap">{{ $r['d'] ?? '' }} <span class="text-muted small">{{ $r['t'] ?? ($r['s'] ?? '') }}</span></td>
            <td class="small">{{ $r['q'] ?? '' }}{{ !empty($r['n']) ? ' · '.$r['n'] : '' }}</td>
            <td class="text-center"><span class="badge st-ok">{{ $r['ok'] ?? 0 }}</span></td>
            <td class="text-center"><span class="badge {{ !empty($r['no']) ? 'text-bg-danger' : 'text-bg-light border' }}">{{ $r['no'] ?? 0 }}</span></td>
            <td class="text-center small text-muted">{{ $r['na'] ?? 0 }} / {{ $r['tot'] ?? 0 }}</td></tr>
        @endforeach
        </tbody></table></div>
      @endif
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-body">
      <h2 class="sec-h"><i class="bi bi-calendar-week"></i> الجدول الدوري <span class="small text-muted fw-normal">من النموذج</span></h2>
      @if(!count($s['sched']))<div class="small text-muted">—</div>
      @else
      <table class="table table-sm m-0" id="sfSched"><thead><tr><th>الدورية</th><th>المهمة</th><th>مَن</th></tr></thead><tbody>
        @foreach($s['sched'] as $row)<tr><td class="fw-bold text-nowrap">{{ $row[0] ?? '' }}</td><td class="small">{{ $row[1] ?? '' }}</td><td class="small text-muted">{{ $row[2] ?? '' }}</td></tr>@endforeach
      </tbody></table>
      @endif
    </div></div>
  </div>
</div>

<div class="card mb-3"><div class="card-body">
  <h2 class="sec-h"><i class="bi bi-list-check"></i> البنود الآن <span class="small text-muted fw-normal">علامات الجولة القائمة</span></h2>
  <div class="table-responsive"><table class="table table-sm align-middle m-0" id="sfItems">
    <thead><tr><th style="width:2.5rem">#</th><th>البند</th><th class="text-center" style="width:6rem">العلامة</th></tr></thead>
    <tbody>
    @foreach($s['items'] as $it)
      <tr data-item="{{ $it['i'] }}" data-mark="{{ $it['mark'] }}"><td class="text-muted">{{ $it['i'] + 1 }}</td><td>{{ $it['text'] }}<div class="small text-muted">{{ $it['ref'] }}</div></td>
        <td class="text-center fw-bold {{ $MK[$it['mark']][1] ?? 'text-muted' }}">{{ $MK[$it['mark']][0] ?? '—' }}</td></tr>
    @endforeach
    @if(count($s['reads']))
      <tr><th colspan="3" class="pt-3">القراءات المقاسة</th></tr>
      @foreach($s['reads'] as $rd)
        <tr data-read="{{ $rd['i'] }}" data-mark="{{ $rd['mark'] }}"><td class="text-muted">{{ $rd['i'] + 1 }}</td><td>{{ $rd['text'] }}<div class="small text-muted">المرجعية: {{ $rd['ref'] !== '' ? $rd['ref'] : 'لم تُعتمد' }} · المقاسة: {{ $rd['act'] !== '' ? $rd['act'] : '—' }}</div></td>
          <td class="text-center fw-bold {{ $MK[$rd['mark']][1] ?? 'text-muted' }}">{{ $MK[$rd['mark']][0] ?? '—' }}</td></tr>
      @endforeach
    @endif
    </tbody></table></div>
</div></div>

<h2 class="sec-h"><i class="bi bi-clipboard-x"></i> بلاغات النظام <span class="badge text-bg-dark">{{ count($s['reports_sorted']) }}</span> <span class="small text-muted fw-normal">المفتوح أولاً، ثم ما أُغلق — بمدة العطل</span></h2>
<div class="d-grid gap-2 mb-3" id="sfReports">
  @forelse($s['reports_sorted'] as $r)
    @php($closed = $R::isClosed($r))
    @php($over = $R::overdueHours($r))
    @php($fd = $R::faultDays($r))
    <div class="card task {{ !$closed && $over !== null && $over > 0 ? 'task-late' : '' }}" data-report="{{ $r['row'] ?? '' }}" data-closed="{{ $closed ? 1 : 0 }}"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1"><b>{{ $r['id'] ?? $r['row'] ?? '' }}</b>@if(!empty($r['unit'])) · {{ $r['unit'] }}@endif <span class="badge {{ $closed ? 'st-ok' : 'st-wait' }}">{{ $closed ? 'أُغلق' : 'مفتوح — عند المستوى '.$R::holder($r) }}</span>
        <div class="small">{{ $r['item'] ?? '' }}</div>
        <div class="small text-muted">@if(!$closed && $over !== null && $over > 0)<span class="badge st-late">متأخر</span> @endif المهلة: {{ $r['due'] ?? '—' }}
          @if($fd !== null) · {{ $closed ? 'بقي العطل' : 'مضى على العطل' }} <b class="text-dark">{{ $fd ? $fd.' يوم' : 'أقل من يوم' }}</b>@endif{{ !empty($r['oos']) ? ' · خرج عن الخدمة' : '' }}</div></div>
      @if($ui)<a class="btn btn-o btn-sm" href="/{{ $s['form']['file'] }}#open={{ rawurlencode((string) ($r['row'] ?? '')) }}">افتحه</a>@endif
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2"><i class="bi bi-check-circle text-success"></i> لا بلاغات على هذا النظام.</div></div>
  @endforelse
</div>
@endsection
