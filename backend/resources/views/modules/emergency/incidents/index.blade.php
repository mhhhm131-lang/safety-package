@extends('layouts.app')
@section('page_title', 'الحالات الطارئة')
@section('content')
@php($I = \App\Modules\Emergency\Models\EmergencyIncident::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-broadcast"></i> الحالات الطارئة</h1>
  <span class="small text-muted">كل ما فُعّل: حقيقي وتمرين وإغلاق أمني</span>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.dashboard') }}">مركز الطوارئ</a>
</div>
<form method="get" class="card p-2 mb-3"><div class="row g-2 align-items-end">
  <div class="col-md-2"><label class="form-label small mb-0">الحالة</label><select name="status" class="form-select form-select-sm"><option value="">الكل</option>@foreach($I::STATUS_LABELS as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="col-md-2"><label class="form-label small mb-0">النوع</label><select name="type" class="form-select form-select-sm"><option value="">الكل</option>@foreach($I::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="col-md-3"><label class="form-label small mb-0">المكان</label><select name="place" class="form-select form-select-sm"><option value="">الكل</option>@foreach($places as $p)<option value="{{ $p->code }}" @selected(request('place') === $p->code)>{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
  <div class="col-md-2"><label class="form-label small mb-0">تمرين؟</label><select name="drill" class="form-select form-select-sm"><option value="">الكل</option><option value="0" @selected(request('drill') === '0')>حقيقي</option><option value="1" @selected(request('drill') === '1')>تمرين</option></select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-g w-100">بحث</button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover m-0 small">
<thead><tr><th>الرمز</th><th>النوع</th><th>المكان</th><th>الخطورة</th><th>الحالة</th><th>بدأت</th><th>المدة</th><th>فعّلها</th><th></th></tr></thead>
<tbody>
@forelse($incidents as $i)
  <tr>
    <td class="fw-bold text-nowrap">{{ $i->incident_code }}</td>
    <td>{{ $i->getTypeLabel() }}@if($i->is_drill) <span class="badge text-bg-light border">تمرين</span>@endif</td>
    <td>{{ $i->place?->code }} {{ $i->place?->name ?? '—' }}</td>
    <td>{{ $i->getSeverityLabel() }}</td>
    <td><span class="badge text-bg-{{ $i->getStatusColor() }}">{{ $i->getStatusLabel() }}</span>@if($i->escalation_level > 1) <span class="badge text-bg-dark">م{{ $i->escalation_level }}</span>@endif</td>
    <td class="text-nowrap text-muted">{{ $i->triggered_at->format('Y-m-d H:i') }}</td>
    <td>{{ $i->getDurationFormatted() }}</td>
    <td>{{ $i->triggeredBy?->name ?? '—' }}</td>
    <td class="text-nowrap">
      @if($i->isOpen())<a class="btn btn-sm btn-danger" href="{{ route('emergency.incidents.live', $i) }}"><i class="bi bi-broadcast"></i></a>@endif
      <a class="btn btn-sm btn-outline-primary" href="{{ route('emergency.incidents.report', $i) }}"><i class="bi bi-file-text"></i></a>
    </td>
  </tr>
@empty
  <tr><td colspan="9" class="text-center text-muted py-4">لا حالات</td></tr>
@endforelse
</tbody></table></div></div>
@if($incidents->hasPages())<div class="mt-3">{{ $incidents->links() }}</div>@endif
@endsection
