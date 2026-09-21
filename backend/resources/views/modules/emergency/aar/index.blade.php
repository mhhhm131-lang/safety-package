@extends('layouts.app')
@section('title', 'تقارير ما بعد الحادث')

{{-- ٢٢-٧ (د): كانت أربع عشرة وظيفة بلا شاشة ولا مدخل. --}}

@section('content')
<div class="container-fluid px-0" style="max-width:1000px">
  <h1 class="page-h">تقارير ما بعد الحادث</h1>
  <p class="text-muted">بعد كل حالة طارئة: ما الذي سار، وما الذي لم يسر، وما يُصحَّح ومن يصحّحه.</p>

  @if($openActions->isNotEmpty())
    <div class="card mb-3 border-warning">
      <div class="card-header bg-warning"><strong>إجراءات تصحيحية مفتوحة</strong> <span class="badge text-bg-dark">{{ $openActions->count() }}</span></div>
      <ul class="list-group list-group-flush small">
        @foreach($openActions as $a)
          <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
            <span class="flex-grow-1">{{ $a->title }}</span>
            <span class="text-muted">{{ $a->assignedTo?->name ?? '—' }}</span>
            @if($a->due_date)
              <span class="badge {{ $a->due_date->isPast() ? 'text-bg-danger' : 'text-bg-secondary' }}">{{ $a->due_date->format('Y-m-d') }}</span>
            @endif
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.aar.show', $a->report_id) }}">التقرير</a>
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="card">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead><tr><th>الحالة</th><th>التقرير</th><th>الوضع</th><th>أُعدّ</th><th></th></tr></thead>
        <tbody>
          @forelse($reports as $r)
            <tr>
              <td>{{ $r->incident?->incident_code ?? '—' }}</td>
              <td>{{ $r->title }}</td>
              <td><span class="badge text-bg-secondary">{{ $r->getStatusLabel() }}</span></td>
              <td class="small text-muted">{{ $r->preparedBy?->name }} · {{ $r->created_at?->format('Y-m-d') }}</td>
              <td><a class="btn btn-sm btn-outline-primary" href="{{ route('emergency.aar.show', $r) }}">افتح</a></td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">لا تقارير بعد. يُبنى التقرير بزر واحد من صفحة تقرير الحالة.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="mt-2">{{ $reports->links() }}</div>
</div>
@endsection
