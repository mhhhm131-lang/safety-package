@extends('layouts.app')
@section('page_title', 'كفاءة العامل')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-award me-2"></i>كفاءة العامل: {{ $worker->full_name }} <small class="text-muted fs-6">{{ $worker->trade?->name }}</small></h1>
  <a href="{{ route('workers.show', $worker) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
@php($pct = $compliance['compliance_percent'])
<div class="card mb-3"><div class="card-body d-flex align-items-center gap-3">
  <div class="display-6 fw-bold {{ $pct >= 100 ? 'text-success' : ($pct >= 50 ? 'text-warning' : 'text-danger') }}" data-compliance="{{ $pct }}">{{ $pct }}%</div>
  <div class="small text-muted">نسبة مواضيع التدريب المطلوبة لمهنته المكتملة وسارية. المتطلبات تُحدَّد من مصفوفة الكفاءات.</div>
</div></div>
<div class="card"><div class="card-body p-0">
  <table class="table table-sm mb-0">
    <thead><tr><th>موضوع التدريب</th><th>الفئة</th><th>الحالة</th></tr></thead>
    <tbody>
    @forelse($compliance['requirements'] as $r)
      <tr><td>{{ $r['topic']->name }}</td><td class="small">{{ $r['topic']->getCategoryLabel() }}</td>
        <td>@switch($r['status'])@case('completed')<span class="badge bg-success">مكتمل</span>@break @case('expired')<span class="badge bg-danger">منتهٍ</span>@break @case('pending')<span class="badge bg-warning text-dark">قيد التنفيذ</span>@break @default<span class="badge bg-secondary">لم يبدأ</span>@endswitch</td></tr>
    @empty
      <tr><td colspan="3" class="text-center text-muted py-3">لا متطلبات محدَّدة لهذه المهنة بعد — تُحدَّد من مصفوفة الكفاءات</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
@endsection
