@extends('layouts.app')
@section('page_title', 'كفاءة عمال المقاول')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-award me-2"></i>كفاءة عمال: {{ $externalParty->name }}</h1>
  <a href="{{ route('external-parties.show', $externalParty) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
@php($pct = $compliance['overall_compliance'])
<div class="card mb-3"><div class="card-body d-flex align-items-center gap-3">
  <div class="display-6 fw-bold {{ $pct >= 100 ? 'text-success' : ($pct >= 50 ? 'text-warning' : 'text-danger') }}">{{ $pct }}%</div>
  <div class="small text-muted">متوسط امتثال {{ $compliance['workers_count'] }} عاملاً لمتطلبات تدريب مهنهم.</div>
</div></div>
<div class="card"><div class="card-body p-0">
  <table class="table table-sm mb-0">
    <thead><tr><th>العامل</th><th>المهنة</th><th>الحالة</th><th>الامتثال</th></tr></thead>
    <tbody>
    @forelse($compliance['workers'] as $row)
      <tr><td><a href="{{ route('competency.worker', $row['worker']) }}">{{ $row['worker']->full_name }}</a></td><td>{{ $row['worker']->trade?->name }}</td><td>{{ $row['worker']->getStatusLabel() }}</td><td>{{ $row['compliance_percent'] }}%</td></tr>
    @empty
      <tr><td colspan="4" class="text-center text-muted py-3">لا عمال</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
@endsection
