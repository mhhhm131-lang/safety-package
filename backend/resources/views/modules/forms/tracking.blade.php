@extends('layouts.app')
@section('page_title', 'متابعة نموذج')
@section('content')
@php $me = auth()->user(); @endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="{{ route('forms.show', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">متابعة التكليف</h1>
    <div class="small text-muted">{{ $form->title }}</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    @if($me->can_('form.send') && ($stats['pending'] + $stats['overdue']) > 0)
      <form method="post" action="{{ route('forms.remind-all', $form) }}">
        @csrf
        <button class="btn btn-sm btn-outline-warning"><i class="bi bi-bell"></i> ذكّر من لم يعبّئ</button>
      </form>
      <a href="{{ route('forms.send', $form) }}" class="btn btn-sm btn-g"><i class="bi bi-person-plus"></i> تكليف إضافي</a>
    @endif
  </div>
</div>

<div class="row g-2 mb-3">
  @foreach([
    ['مكلَّف', $stats['total'], 'secondary'], ['عبّأ', $stats['completed'], 'success'],
    ['بانتظار', $stats['pending'], 'warning'], ['متأخر', $stats['overdue'], 'danger'],
  ] as [$label, $value, $color])
    <div class="col-6 col-md-3">
      <div class="card"><div class="card-body text-center py-2">
        <div class="h4 m-0 text-{{ $color }}" data-stat="{{ $label }}">{{ $value }}</div>
        <small class="text-muted">{{ $label }}</small>
      </div></div>
    </div>
  @endforeach
</div>

@php $pct = $stats['total'] ? (int) round($stats['completed'] / $stats['total'] * 100) : 0; @endphp
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between small mb-1">
      <span>نسبة الاستجابة</span>
      <strong data-response-pct="{{ $pct }}">{{ $pct }}%</strong>
    </div>
    <div class="progress" style="height:6px">
      <div class="progress-bar {{ $pct === 100 ? 'bg-success' : '' }}" style="width:{{ $pct }}%"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>المكلَّف</th><th>الدور</th><th>مصدر التكليف</th><th>المهلة</th><th>الحالة</th><th>التعبئة</th><th></th></tr>
      </thead>
      <tbody>
        @forelse($assignments as $a)
          <tr data-assignment="{{ $a->id }}" class="{{ $a->status === 'overdue' ? 'table-warning' : '' }}">
            <td class="fw-bold">{{ $a->assignedTo?->name ?? '—' }}</td>
            <td class="small text-muted">{{ $a->assignedTo?->roleName() }}</td>
            <td class="small text-muted">{{ $a->getSourceLabel() }}</td>
            <td class="small {{ $a->due_date && $a->due_date->isPast() && $a->isOpen() ? 'text-danger fw-bold' : 'text-muted' }}">
              {{ $a->due_date?->format('Y-m-d') ?? '—' }}
            </td>
            <td>
              <span class="badge bg-{{ $a->status === 'completed' ? 'success' : ($a->status === 'overdue' ? 'danger' : 'warning text-dark') }}"
                    data-status="{{ $a->status }}">{{ $a->getStatusLabel() }}</span>
            </td>
            <td class="small text-muted">{{ $a->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
            <td class="text-nowrap">
              @if($a->isOpen() && $me->can_('form.send'))
                <form method="post" action="{{ route('forms.remind', [$form, $a]) }}" class="d-inline">
                  @csrf
                  <button class="btn btn-sm btn-outline-secondary py-0" title="تذكير">
                    <i class="bi bi-bell"></i>
                    @if($a->reminded_at)<span class="small">ذُكّر {{ $a->reminded_at->diffForHumans() }}</span>@endif
                  </button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">لا تكليفات بعد.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($assignments->hasPages())<div class="card-footer">{{ $assignments->links() }}</div>@endif
</div>
@endsection
