@extends('layouts.app')
@section('title', $report->title)

{{-- ٢٢-٧ (د): التقرير يُبنى من سجل الحالة، ويُكتب ويُعتمد ويُنشر، وإجراءاته تصل أصحابها. --}}

@php
  $canWrite = \App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'emergency.manage');
  $S = \App\Modules\Emergency\Models\AfterActionReport::class;
@endphp

@section('content')
<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.aar.index') }}"><i class="bi bi-arrow-right"></i> التقارير</a>
    <h1 class="h4 m-0">{{ $report->title }}</h1>
    <span class="badge text-bg-secondary">{{ $report->getStatusLabel() }}</span>
    @if($report->incident)
      <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('emergency.incidents.live', $report->incident) }}">شاشة الحالة</a>
    @endif
  </div>

  {{-- ما بناه النظام من السجل: لا يُكتب باليد --}}
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>ما سجّله النظام</strong> <span class="small text-muted">محسوب من الحالة، لا يُكتب باليد</span></div>
    <div class="row g-0 text-center">
      @foreach([['بدأت', $report->incident_start_at?->format('H:i')], ['انتهت', $report->incident_end_at?->format('H:i')],
                ['المدة (دقيقة)', $report->resolution_time_minutes], ['سُجّلوا بأمان', $report->evacuated_count]] as [$l, $v])
        <div class="col-6 col-md-3 border p-3">
          <div class="fs-4 fw-bold">{{ $v ?? '—' }}</div>
          <div class="small text-muted">{{ $l }}</div>
        </div>
      @endforeach
    </div>
    @if($report->chronology)
      <div class="card-body">
        <div class="small text-muted mb-1">الخط الزمني</div>
        <pre class="small mb-0" style="white-space:pre-wrap">{{ $report->chronology }}</pre>
      </div>
    @endif
  </div>

  {{-- ما يكتبه الإنسان --}}
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>ما يُكتب</strong></div>
    <div class="card-body">
      <form method="post" action="{{ url('/app/emergency/aar/'.$report->id) }}">
        @csrf
        @foreach([['what_went_well', 'ما الذي سار جيداً'], ['what_went_wrong', 'ما الذي لم يسر جيداً'],
                  ['root_cause_analysis', 'السبب الجذري'], ['lessons_learned', 'الدرس المستفاد'],
                  ['recommendations', 'التوصيات']] as [$f, $label])
          <div class="mb-3">
            <label class="form-label">{{ $label }}</label>
            <textarea name="{{ $f }}" class="form-control" rows="2" @disabled(!$canWrite)>{{ old($f, $report->$f) }}</textarea>
          </div>
        @endforeach
        @if($canWrite)<button class="btn btn-primary">احفظ</button>@endif
      </form>
    </div>
  </div>

  {{-- الإجراءات التصحيحية --}}
  <div class="card mb-3">
    <div class="card-header bg-light"><strong>ما يُصحَّح ومن يصحّحه</strong></div>
    @if($report->correctiveActions->isNotEmpty())
      <ul class="list-group list-group-flush">
        @foreach($report->correctiveActions as $a)
          <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
            <div class="flex-grow-1">
              <strong>{{ $a->title }}</strong>
              <div class="small text-muted">
                {{ $a->assignedTo?->name ?? '—' }}
                @if($a->due_date) · المهلة {{ $a->due_date->format('Y-m-d') }}@endif
                @if($a->description)<br>{{ $a->description }}@endif
              </div>
            </div>
            @if($a->completed_date)
              <span class="badge text-bg-success">أُنجز {{ $a->completed_date->format('Y-m-d') }}</span>
            @elseif($a->assigned_to_id === auth()->id() || $canWrite)
              <form method="post" action="{{ route('emergency.aar.actions.done', $a) }}" class="m-0">
                @csrf<button class="btn btn-sm btn-success">أنجزته</button>
              </form>
            @else
              <span class="badge text-bg-secondary">مفتوح</span>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
    @if($canWrite)
      <div class="card-body border-top">
        <form method="post" action="{{ route('emergency.aar.actions.add', $report) }}" class="row g-2">
          @csrf
          <div class="col-md-5"><input name="title" class="form-control" placeholder="ما الذي يُصحَّح؟" required maxlength="200"></div>
          <div class="col-md-3">
            <select name="assigned_to_id" class="form-select" required>
              <option value="">من يصحّحه…</option>
              @foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-2"><input type="date" name="due_date" class="form-control" required></div>
          <div class="col-md-2 d-grid"><button class="btn btn-outline-primary">أسنِد</button></div>
        </form>
      </div>
    @endif
  </div>

  @if($canWrite)
    <div class="d-flex gap-2 flex-wrap">
      @if($report->status === $S::STATUS_DRAFT)
        <form method="post" action="{{ route('emergency.aar.submit', $report) }}">@csrf<button class="btn btn-outline-primary">ارفعه للمراجعة</button></form>
      @elseif($report->status === $S::STATUS_UNDER_REVIEW)
        <form method="post" action="{{ route('emergency.aar.approve', $report) }}">@csrf<button class="btn btn-success">اعتمده</button></form>
      @elseif($report->status === $S::STATUS_APPROVED)
        <form method="post" action="{{ route('emergency.aar.publish', $report) }}">@csrf<button class="btn btn-success">انشره</button></form>
      @else
        <span class="text-muted">نُشر التقرير @if($report->approvedBy)· اعتمده {{ $report->approvedBy->name }}@endif</span>
      @endif
    </div>
  @endif
</div>
@endsection
