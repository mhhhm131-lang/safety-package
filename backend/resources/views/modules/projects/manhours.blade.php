@extends('layouts.app')
@section('page_title', 'ساعات العمل')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-clock-history me-2"></i>ساعات العمل — {{ $project->name }}</h1>
  <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
@if(auth()->user()->can_('project.edit'))
<form method="POST" action="{{ route('projects.manhours.store', $project) }}" class="card mb-3"><div class="card-body row g-2 align-items-end">
  @csrf
  <div class="col-md-2"><label class="form-label small">التاريخ</label><input type="date" name="date" class="form-control form-control-sm" value="{{ old('date', now()->toDateString()) }}" required></div>
  <div class="col-md-3"><label class="form-label small">المقاول</label><select name="external_party_id" class="form-select form-select-sm"><option value="">—</option>@foreach($parties as $pt)<option value="{{ $pt->id }}" @selected(old('external_party_id') == $pt->id)>{{ $pt->name }}</option>@endforeach</select></div>
  <div class="col-md-1"><label class="form-label small">العمال</label><input type="number" name="workers_count" class="form-control form-control-sm" min="1" value="{{ old('workers_count') }}" required></div>
  <div class="col-md-1"><label class="form-label small">ساعات/عامل</label><input type="number" step="0.5" name="hours_worked" class="form-control form-control-sm" min="0" value="{{ old('hours_worked') }}" required></div>
  <div class="col-md-1"><label class="form-label small">حوادث</label><input type="number" name="incidents_count" class="form-control form-control-sm" min="0" value="{{ old('incidents_count', 0) }}"></div>
  <div class="col-md-1"><label class="form-label small">وقت ضائع</label><input type="number" name="lost_time_incidents" class="form-control form-control-sm" min="0" value="{{ old('lost_time_incidents', 0) }}"></div>
  <div class="col-md-2"><label class="form-label small">ملاحظة</label><input name="notes" class="form-control form-control-sm" value="{{ old('notes') }}"></div>
  <div class="col-md-1"><button class="btn btn-sm btn-g w-100">إضافة</button></div>
</div></form>
@endif
<div class="card"><div class="card-body p-0">
  <table class="table table-sm mb-0">
    <thead><tr><th>التاريخ</th><th>المقاول</th><th>العمال</th><th>ساعات/عامل</th><th>الإجمالي</th><th>حوادث</th><th>وقت ضائع</th><th>ملاحظة</th></tr></thead>
    <tbody>
    @forelse($manhours as $log)
      <tr><td dir="ltr">{{ $log->date->toDateString() }}</td><td>{{ $log->externalParty?->name ?? '—' }}</td><td>{{ $log->workers_count }}</td><td>{{ $log->hours_worked }}</td><td><strong>{{ $log->total_manhours }}</strong></td><td>{{ $log->incidents_count }}</td><td>{{ $log->lost_time_incidents }}</td><td class="small text-muted">{{ $log->notes }}</td></tr>
    @empty
      <tr><td colspan="8" class="text-center text-muted py-4">لا سجلات</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
<div class="mt-2">{{ $manhours->links() }}</div>
@endsection
