@extends('layouts.app')
@section('page_title', 'تفعيل التصريح ' . $permit->code)
@section('content')
@php($mandatory = $preventiveRequirements->where('severity', 'mandatory'))
@php($done = $mandatory->filter->isComplete()->count())
@php($pct = $mandatory->count() ? (int) round($done / $mandatory->count() * 100) : 100)

<div class="d-flex align-items-center gap-3 mb-3">
  <a href="{{ route('permits.show', $permit) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">التحقق الميداني قبل التفعيل</h1>
    <div class="small text-muted">
      <span dir="ltr">{{ $permit->code }}</span> — {{ $permit->title }}
      @if($permit->place) · {{ $permit->place->name }} @endif
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong class="small">البنود الاستباقية الإلزامية</strong>
      <strong class="small {{ $allMandatoryPreventiveDone ? 'text-success' : 'text-muted' }}" data-mandatory="{{ $done }}/{{ $mandatory->count() }}">
        {{ $done }} / {{ $mandatory->count() }}
        @if($allMandatoryPreventiveDone)<i class="bi bi-check-circle-fill"></i>@endif
      </strong>
    </div>
    <div class="progress" style="height:6px">
      <div class="progress-bar {{ $allMandatoryPreventiveDone ? 'bg-success' : 'bg-warning' }}" style="width:{{ $pct }}%"></div>
    </div>
    @if($allMandatoryPreventiveDone)
      <div class="small text-success mt-2"><i class="bi bi-unlock-fill"></i> كل البنود الإلزامية مكتملة — التفعيل متاح.</div>
    @else
      <div class="small text-danger mt-2"><i class="bi bi-lock-fill"></i> التفعيل مقفل حتى تكتمل البنود الاستباقية الإلزامية.</div>
    @endif
  </div>
</div>

@if(!empty($blockers))
  <div class="alert alert-warning py-2 small">
    <strong><i class="bi bi-exclamation-triangle"></i> موانع قائمة</strong>
    <ul class="mb-0 mt-1 ps-3">@foreach($blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
  </div>
@endif

<div class="card mb-3">
  <div class="card-header bg-light"><i class="bi bi-shield-check text-primary"></i> الإجراءات الاستباقية</div>
  <div class="card-body p-0">
    @if($preventiveRequirements->isEmpty())
      <div class="text-center text-muted py-4"><i class="bi bi-check2-all fs-3 d-block mb-2 text-success"></i>لا إجراءات استباقية محددة لهذا التصريح.</div>
    @else
      @include('modules.permits._requirements', [
        'groupedRequirements' => ['preventive' => $preventiveRequirements, 'operational' => collect(), 'response' => collect(), 'other' => collect()],
        'editable' => true,
      ])
    @endif
  </div>
</div>

@if($allMandatoryPreventiveDone && empty($blockers))
  <div class="card border-primary">
    <div class="card-header bg-primary-subtle"><i class="bi bi-camera-fill"></i> لقطة ظروف الموقع عند بدء العمل</div>
    <div class="card-body">
      <form method="post" action="{{ route('permits.transition', $permit) }}">
        @csrf
        <input type="hidden" name="to_status" value="active">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label small fw-bold">الطقس والظروف المحيطة</label>
            <input type="text" name="activation_weather" class="form-control form-control-sm" placeholder="مثال: صحو، تهوية جيدة">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="activation_workers_confirmed" value="1" id="wOk">
              <label class="form-check-label small" for="wOk">العمال في الموقع وجاهزون</label>
            </div>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="activation_equipment_checked" value="1" id="eOk">
              <label class="form-check-label small" for="eOk">المعدات فُحصت وجاهزة</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label small fw-bold">ملاحظات ميدانية</label>
            <textarea name="activation_notes" class="form-control form-control-sm" rows="2"></textarea>
          </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
          <button class="btn btn-success fw-bold"><i class="bi bi-play-circle-fill"></i> تفعيل التصريح الآن</button>
        </div>
      </form>
    </div>
  </div>
@else
  <div class="d-flex justify-content-between">
    <a href="{{ route('permits.show', $permit) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right"></i> العودة للتصريح</a>
    <button class="btn btn-secondary" disabled><i class="bi bi-lock-fill"></i> التفعيل مقفل</button>
  </div>
@endif
@endsection
