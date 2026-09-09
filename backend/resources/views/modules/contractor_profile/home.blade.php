@extends('layouts.app')
@section('page_title', 'بوابة المقاول')
@section('content')
@php($me = auth()->user())
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-building me-2"></i>{{ $party->name }} <small class="text-muted fs-6">{{ $party->getTypeLabel() }} — بوابة المقاول</small></h1>
  <span class="badge {{ $evaluation['label'] === 'high' ? 'bg-success' : ($evaluation['label'] === 'medium' ? 'bg-warning text-dark' : 'bg-danger') }}" data-trust="{{ $evaluation['score'] }}">درجة الثقة {{ $evaluation['score'] }}/100</span>
</div>
<div class="alert alert-info py-2 small">ما تراه هنا بيانات طرفكم فقط: المشاريع وحالة التأهيل، العمال، المستندات، والتصاريح.</div>

{{-- التصاريح (المرحلة ٦-ب): ما ينتظر رفع أدلة منكم أولاً --}}
<div class="card mb-3">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-file-earmark-check"></i> تصاريحنا</span>
    <span class="badge bg-light text-dark border ms-2" data-permits-count>{{ $permits->count() }}</span>
    @if($me->can_('permit.list'))
      <a href="{{ route('permits.index') }}" class="small ms-auto">عرض الكل</a>
    @endif
  </div>
  <div class="card-body p-0">
    @forelse($permits as $p)
      @php($total = $p->requirements->count())
      @php($done = $p->requirements->filter->isComplete()->count())
      <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small" data-permit="{{ $p->code }}">
        <a href="{{ route('permits.show', $p) }}" class="fw-bold" dir="ltr">{{ $p->code }}</a>
        <div class="flex-grow-1">
          {{ \Illuminate\Support\Str::limit($p->title, 40) }}
          <div class="text-muted">{{ $p->type?->name }} @if($p->place)· {{ $p->place->name }}@endif</div>
        </div>
        @if($total)
          <div class="text-center" style="min-width:90px">
            <div class="text-muted" style="font-size:.72rem">{{ $done }}/{{ $total }} بنداً</div>
            <div class="progress" style="height:4px">
              <div class="progress-bar {{ $done === $total ? 'bg-success' : '' }}" style="width:{{ $total ? round($done / $total * 100) : 0 }}%"></div>
            </div>
          </div>
        @endif
        @include('modules.permits._status', ['status' => $p->status])
      </div>
    @empty
      <div class="text-center text-muted py-3 small">لا تصاريح لطرفكم بعد. يصدرها مسؤول السلامة بعد اكتمال التأهيل.</div>
    @endforelse
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">التأهيل</h2>
      <ul class="list-unstyled small mb-2" id="contractor-checks">
        @php($checkLabels = ['cr_active' => 'السجل التجاري', 'insurance_active' => 'التأمين', 'iso_cert' => 'شهادة ISO', 'gosi_registered' => 'التأمينات الاجتماعية', 'etimad_active' => 'اعتماد', 'docs_complete' => 'المستندات الإلزامية', 'no_critical_incidents' => 'لا بلاغات عاجلة مفتوحة', 'trade_coverage' => 'عمال مسجّلون'])
        @foreach($evaluation['checks'] as $k => $c)
          <li data-check="{{ $k }}" data-passed="{{ $c['passed'] ? 1 : 0 }}">@if($c['passed'])<span class="text-success">✓</span>@elseif($c['points'] > 0)<span class="text-warning">◐</span>@else<span class="text-danger">✗</span>@endif {{ $checkLabels[$k] ?? $k }} <span class="text-muted">({{ $c['points'] }}/{{ $c['max'] }})</span></li>
        @endforeach
      </ul>
      <a href="{{ route('external-parties.profile', $party) }}" class="btn btn-sm btn-outline-primary">ملف التأهيل كاملاً</a>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">مشاريعنا</h2>
      <table class="table table-sm mb-0" id="contractor-projects">
        <thead><tr><th>المشروع</th><th>المكان</th><th>الدور</th><th>حالة التأهيل</th><th></th></tr></thead>
        <tbody>
        @forelse($party->projectAssignments as $pc)
          <tr data-status="{{ $pc->qualification_status }}">
            <td>{{ $pc->project?->name }}</td><td>{{ $pc->project?->place?->name ?? '—' }}</td><td>{{ $pc->getRoleLabel() }}</td>
            <td><span class="badge {{ $pc->qualification_status === 'post_approved' ? 'bg-success' : ($pc->qualification_status === 'suspended' ? 'bg-danger' : 'bg-secondary') }}">{{ $pc->getStatusLabel() }}</span></td>
            <td class="text-nowrap">
              <a href="{{ route('projects.contractors', $pc->project) }}" class="btn btn-sm btn-outline-secondary">التفاصيل</a>
              @if($pc->qualification_status === 'draft')
                <form method="POST" action="{{ route('projects.contractors.transition', [$pc->project, $pc]) }}" class="d-inline">@csrf<input type="hidden" name="status" value="pre_review"><button class="btn btn-sm btn-g">تقديم للتأهيل المسبق</button></form>
              @elseif($pc->qualification_status === 'pre_approved')
                <form method="POST" action="{{ route('projects.contractors.transition', [$pc->project, $pc]) }}" class="d-inline">@csrf<input type="hidden" name="status" value="post_review"><button class="btn btn-sm btn-g">تقديم للتأهيل اللاحق</button></form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-3">لم يُربط طرفكم بمشروع بعد</td></tr>
        @endforelse
        </tbody>
      </table>
    </div></div>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
          <div class="d-flex align-items-center mb-2"><h2 class="h6 m-0">العمال ({{ $party->workers->count() }})</h2>
            @if($me->can_('worker.create'))<a class="btn btn-sm btn-g ms-auto" href="{{ route('workers.create') }}">تسجيل عامل</a>@endif</div>
          <ul class="list-unstyled small mb-2">
            @foreach(\App\Modules\Worker\Models\Worker::STATUSES as $k => $v)
              @if($workersByStatus->get($k))<li>{{ $v }}: <strong data-workers-status="{{ $k }}">{{ $workersByStatus->get($k) }}</strong></li>@endif
            @endforeach
          </ul>
          <a href="{{ route('workers.index') }}" class="btn btn-sm btn-outline-secondary">قائمة العمال</a>
        </div></div>
      </div>
      <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
          <div class="d-flex align-items-center mb-2"><h2 class="h6 m-0">المستندات ({{ $party->documents->count() }})</h2>
            <a class="btn btn-sm btn-g ms-auto" href="{{ route('external-parties.documents', $party) }}">رفع / عرض</a></div>
          @if($expiring->isNotEmpty())
            <div class="small text-danger">مستندات منتهية أو تقارب الانتهاء: {{ $expiring->pluck('name')->join('، ') }}</div>
          @else
            <div class="small text-muted">لا مستندات منتهية.</div>
          @endif
          <div class="small text-muted mt-1">الإلزامي للتأهيل: السجل التجاري والتأمين.</div>
        </div></div>
      </div>
    </div>
  </div>
</div>
@endsection
