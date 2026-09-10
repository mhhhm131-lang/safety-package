@extends('layouts.app')
@section('page_title', 'تقرير الحالة — '.$incident->incident_code)
@section('content')
<style>@media print{.side,.topbar,.no-print{display:none!important}main{width:100%!important}}</style>
<div class="d-flex align-items-center gap-2 flex-wrap mb-3 no-print">
  <h1 class="h4 m-0"><i class="bi bi-file-text"></i> تقرير الحالة {{ $incident->incident_code }}</h1>
  <span class="badge text-bg-{{ $incident->getStatusColor() }}">{{ $incident->getStatusLabel() }}</span>
  @if($incident->isOpen())<a class="btn btn-sm btn-danger" href="{{ route('emergency.incidents.live', $incident) }}"><i class="bi bi-broadcast"></i> التتبع المباشر</a>@endif
  <button class="btn btn-sm btn-g ms-auto" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
</div>

<div class="card mb-3"><div class="card-body">
  <h2 class="h5">{{ $incident->is_drill ? 'تمرين إخلاء' : 'حالة طارئة' }} — {{ $incident->getTypeLabel() }} — {{ $incident->place?->name ?? $incident->building->name }}</h2>
  <div class="row small g-2">
    <div class="col-md-3"><span class="text-muted">الرمز:</span> <strong>{{ $incident->incident_code }}</strong></div>
    <div class="col-md-3"><span class="text-muted">الخطورة:</span> {{ $incident->getSeverityLabel() }}</div>
    <div class="col-md-3"><span class="text-muted">المكان:</span> {{ $incident->place?->code }} {{ $incident->place?->name ?? '—' }}</div>
    <div class="col-md-3"><span class="text-muted">المبنى:</span> {{ $incident->building->name }}</div>
    <div class="col-md-3"><span class="text-muted">التفعيل:</span> {{ $incident->triggered_at->format('Y-m-d H:i:s') }} — {{ $incident->triggeredBy?->name ?? '—' }}</div>
    <div class="col-md-3"><span class="text-muted">الإقرار بالاستلام:</span> {{ $incident->acknowledged_at ? $incident->acknowledged_at->format('H:i:s').' — '.($incident->acknowledgedBy?->name ?? '') : '—' }}</div>
    <div class="col-md-3"><span class="text-muted">أول وصول من الفريق:</span> {{ $firstArrivalSec !== null ? gmdate('i:s', $firstArrivalSec).' دقيقة' : '—' }}</div>
    <div class="col-md-3"><span class="text-muted">السيطرة:</span> {{ $incident->contained_at ? $incident->contained_at->format('H:i:s').' — '.($incident->containedBy?->name ?? '') : '—' }}</div>
    <div class="col-md-3"><span class="text-muted">الانتهاء:</span> {{ $incident->ended_at ? $incident->ended_at->format('Y-m-d H:i:s').' — '.($incident->endedBy?->name ?? '') : ($incident->cancelled_at ? 'أُلغيت '.$incident->cancelled_at->format('H:i:s').' — '.$incident->cancel_reason : 'مفتوحة') }}</div>
    <div class="col-md-3"><span class="text-muted">المدة الكلية:</span> {{ $incident->getDurationFormatted() }}</div>
    <div class="col-md-3"><span class="text-muted">مستوى التصعيد:</span> {{ $incident->escalation_level }}</div>
    @if($incident->linkedIncident)<div class="col-md-3"><span class="text-muted">بلاغ الشاغل المرتبط:</span> <a href="{{ route('incidents.show', $incident->linkedIncident) }}">{{ $incident->linkedIncident->code }}</a></div>@endif
  </div>
  @if($incident->description)<p class="mt-2 mb-0"><span class="text-muted">الوصف:</span> {{ $incident->description }}</p>@endif
</div></div>

<div class="row g-3 mb-3">
  <div class="col-md-7">
    <div class="card h-100"><div class="card-header"><strong>الحصر</strong></div><div class="card-body">
      <div class="row text-center g-2">
        @foreach([['الإجمالي', $stats['total'], ''], ['آمنون', $stats['safe'], 'success'], ['مفقودون', $stats['missing'], 'danger'], ['مصابون', $stats['injured'], 'danger'], ['مساعدة', $stats['needs_help'], 'info'], ['الفريق وصل', $stats['team_arrived'].'/'.$stats['team_total'], 'success']] as [$l, $n, $c])
          <div class="col-4 col-md-2"><div class="fs-5 fw-bold {{ $c ? 'text-'.$c : '' }}">{{ $n }}</div><div class="small text-muted">{{ $l }}</div></div>
        @endforeach
      </div>
      @if($byPoint->isNotEmpty())<div class="small mt-2"><span class="text-muted">بحسب نقطة التجمع:</span> @foreach($byPoint as $p){{ $p->assemblyPoint?->name ?? '?' }} ({{ $p->count }}){{ $loop->last ? '' : '، ' }}@endforeach</div>@endif
      @php($team = $incident->checkIns->where('person_type', 'team'))
      @if($team->isNotEmpty())
        <table class="table table-sm small mt-2 mb-0"><thead><tr><th>الفريق الأولي</th><th>الاسم</th><th>الوصول</th></tr></thead><tbody>
        @foreach($team as $c)<tr><td>{{ $c->teamMember?->getRoleKeyLabel() ?? $c->teamMember?->getRoleLabel() }}</td><td>{{ $c->getPersonName() }}</td><td>{{ $c->isSafe() ? $c->checked_in_at?->format('H:i:s') : 'لم يُسجَّل' }}</td></tr>@endforeach
        </tbody></table>
      @endif
      @php($missing = $incident->checkIns->whereIn('status', ['missing', 'injured', 'assisted']))
      @if($missing->isNotEmpty())
        <table class="table table-sm small mt-2 mb-0"><thead><tr><th>مفقود/مصاب/مساعدة</th><th>الحالة</th><th>آخر موقع</th></tr></thead><tbody>
        @foreach($missing as $c)<tr><td>{{ $c->getPersonName() }}</td><td>{{ $c->getStatusLabel() }}</td><td>{{ $c->last_known_location ?? '—' }}</td></tr>@endforeach
        </tbody></table>
      @endif
    </div></div>
  </div>
  <div class="col-md-5">
    <div class="card h-100"><div class="card-header"><strong>من نُبّه</strong></div><div class="card-body small">
      @php($n = $incident->notifications)
      <div>داخل النظام: {{ $n->where('channel', 'in_app')->count() }} (أُرسل {{ $n->where('channel', 'in_app')->whereIn('status', ['sent', 'delivered'])->count() }})</div>
      <div>بريد: {{ $n->where('channel', 'email')->count() }}</div>
      <div>نداء هاتفي يدوي: {{ $n->where('channel', 'phone_call')->count() }}</div>
      @if($n->where('status', 'failed')->count())<div class="text-danger">تعذّر: {{ $n->where('status', 'failed')->count() }}</div>@endif
      @if($incident->lockdown)<hr><div>إغلاق أمني: {{ $incident->lockdown->getLevelLabel() }} — {{ $incident->lockdown->getStateLabel() }}</div>@endif
      @if($incident->drill)<hr><div>التمرين {{ $incident->drill->drill_code }}: النتيجة {{ $incident->drill->getResultLabel() ?? '—' }} · الدرجة {{ $incident->drill->score ?? '—' }}/100 · زمن الإخلاء {{ $incident->drill->evacuation_time_sec ? gmdate('i:s', $incident->drill->evacuation_time_sec) : '—' }}</div>@endif
    </div></div>
  </div>
</div>

{{-- المرحلة ١٠-٤ (ز): الالتزام بالخطة — أول مرة يُعرف فيها بالأرقام أين تأخرت الاستجابة --}}
@if($planRows)
@php($S = \App\Modules\Emergency\Models\EmergencyIncidentStep::class)
<div class="card mb-3 border-dark" id="planCompliance">
  <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong><i class="bi bi-list-check"></i> الالتزام بالخطة</strong>
    <span class="small text-muted">خطة {{ $incident->place?->code }} كما زُومنت من الوثيقة لحظة التفعيل · {{ $planSummary['total'] }} خطوة</span>
    <span class="ms-auto d-flex gap-1 flex-wrap">
      <span class="badge text-bg-success">ضمن النافذة {{ $planSummary['on_time'] }}</span>
      <span class="badge text-bg-warning">تأخرت {{ $planSummary['late'] }}</span>
      <span class="badge text-bg-danger">لم تُعلَّم {{ $planSummary['missed'] }}</span>
      @if($planSummary['skipped'])<span class="badge text-bg-secondary">تُخطّيت {{ $planSummary['skipped'] }}</span>@endif
      @if($planSummary['done_conditional'])<span class="badge text-bg-light border text-dark">شرطية تمت {{ $planSummary['done_conditional'] }}</span>@endif
      @if($planSummary['conditional'])<span class="badge text-bg-light border text-dark">شرطية لم تتحقق {{ $planSummary['conditional'] }}</span>@endif
      <span class="badge text-bg-dark" data-plan-ratio="{{ $planSummary['ratio'] ?? '' }}">الالتزام: {{ $planSummary['ratio'] === null ? 'لم يُقس' : $planSummary['ratio'].'٪' }}</span>
    </span>
  </div>
  <div class="table-responsive"><table class="table table-sm m-0 small align-middle">
    <thead><tr><th>#</th><th>المسار</th><th>الخطوة</th><th>المستهدف</th><th>الفعلي</th><th>الفارق</th><th>من</th><th>ملاحظة</th></tr></thead>
    <tbody>
    @foreach($planRows as $r)
      <tr class="{{ ['late' => 'table-warning', 'missed' => 'table-danger', 'on_time' => 'table-success', 'done_conditional' => 'table-success'][$r['state']] ?? '' }}" data-state="{{ $r['state'] }}">
        <td><strong>{{ $r['label'] }}</strong></td>
        <td class="text-muted text-nowrap">{{ $r['path'] }}</td>
        <td>{{ $r['title'] }}</td>
        <td class="text-nowrap">{{ $r['target'] ?? '—' }}@if($r['window_to_sec'] !== null)<br><small class="text-muted">حتى {{ $S::secs($r['window_to_sec']) }}</small>@endif</td>
        <td class="text-nowrap">
          @if($r['status'] === 'done')بعد {{ $S::secs($r['elapsed_sec']) }}<br><small class="text-muted">{{ $r['done_at'] }}</small>
          @elseif($r['status'] === 'skipped')<span class="text-muted">تُخطّيت</span>
          @elseif($r['conditional'])<span class="text-muted">لم تتحقق</span>
          @else<span class="text-danger fw-bold">لم تُعلَّم</span>@endif
        </td>
        <td class="text-nowrap">{{ $r['delta_label'] ?? '—' }}</td>
        <td><small>{{ $r['owner'] }}</small>@if($r['by'])<br><small class="text-muted">{{ $r['by'] }}</small>@endif</td>
        <td><small class="text-muted">{{ $r['auto'] ? 'آلياً من '.$r['auto'] : '' }}{{ $r['note'] ? ($r['auto'] ? ' — ' : '').$r['note'] : '' }}</small></td>
      </tr>
    @endforeach
    </tbody></table></div>
</div>
@endif

@if($incident->final_report)
<div class="card mb-3"><div class="card-header"><strong>التقرير النهائي</strong></div><div class="card-body" style="white-space:pre-wrap">{{ $incident->final_report }}</div></div>
@endif

<div class="card"><div class="card-header"><strong>السجل الزمني</strong> <span class="small text-muted">{{ $incident->eventLogs->count() }} حدثاً</span></div>
<table class="table table-sm m-0 small"><thead><tr><th style="width:90px">الوقت</th><th style="width:130px">النوع</th><th>الحدث</th><th style="width:140px">بواسطة</th></tr></thead><tbody>
@foreach($incident->eventLogs as $log)
  <tr><td class="text-nowrap">{{ $log->logged_at?->format('H:i:s') }}</td><td><span class="badge text-bg-{{ $log->severity === 'critical' ? 'danger' : ($log->severity === 'warning' ? 'warning' : 'info') }}">{{ $log->getTypeLabel() }}</span></td><td>{{ $log->message }}</td><td>{{ $log->user?->name ?? 'النظام' }}</td></tr>
@endforeach
</tbody></table></div>
@endsection
