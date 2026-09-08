@extends('layouts.app')
@section('page_title', 'مقارنة المقاولين')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-bar-chart me-2"></i>مقارنة المقاولين — {{ $project->name }}</h1>
  <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
<div class="card"><div class="card-body p-0">
  <table class="table table-sm mb-0">
    <thead><tr><th>المقاول</th><th>الفترة</th><th>سلامة</th><th>جودة</th><th>امتثال</th><th>الإجمالي</th><th>المقيِّم</th></tr></thead>
    <tbody>
    @forelse($evaluations as $ev)
      <tr><td>{{ $ev->externalParty?->name }}</td><td class="small">{{ $ev->period_from->toDateString() }} → {{ $ev->period_to->toDateString() }}</td><td>{{ $ev->safety_score }}</td><td>{{ $ev->quality_score }}</td><td>{{ $ev->compliance_score }}</td><td><strong>{{ $ev->overall_score }}</strong></td><td class="small">{{ $ev->evaluatedBy?->name }}</td></tr>
    @empty
      <tr><td colspan="7" class="text-center text-muted py-4">لا تقييمات لهذا المشروع — تُسجَّل من صفحة الطرف الخارجي</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
@endsection
