@extends('layouts.app')
@section('page_title', 'جولة '.($round->organizationUnit?->name ?? ''))
@section('content')
@php
  $me = auth()->user();
  $unit = $round->organizationUnit;
  $states = array_keys(\App\Modules\Form\Models\FormRound::STATE_LABELS);
  $cur = (int) array_search($round->state, $states, true);
@endphp

<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <div>
    <div class="small"><a href="{{ route('forms.rounds.index') }}" class="text-muted text-decoration-none"><i class="bi bi-arrow-right"></i> الجولات</a></div>
    <h1 class="h4 m-0">{{ $unit?->name ?? '—' }} — الجولة {{ $round->round_no }}</h1>
    <div class="small text-muted mt-1">بطاقة الإدارة: سبعة بنود × أربع مراحل. النظام يسأل ولا يبني ولا يحاسب.</div>
  </div>
  <span class="badge bg-{{ $round->stateColor() }} ms-auto fs-6" data-round-state="{{ $round->state }}">{{ $round->getStateLabel() }}</span>
</div>

{{-- شريط الحالات: لدى الإدارة ← اعتمدها المدير ← الإقرارات جارية ← مقفلة --}}
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex gap-2 flex-wrap small" data-round-strip>
      @foreach($states as $i => $k)
        <span class="badge rounded-pill {{ $i < $cur ? 'bg-success' : ($i === $cur ? 'bg-dark' : 'bg-light text-muted border') }}">{{ $i + 1 }} · {{ \App\Modules\Form\Models\FormRound::STATE_LABELS[$k] }}</span>
      @endforeach
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-card-checklist"></i> بطاقة الإدارة</div>
      <div class="card-body small">
        <div class="d-flex flex-wrap gap-3 mb-2">
          <span><i class="bi bi-diagram-3"></i> الإدارة: <strong>{{ $unit?->name ?? '—' }}</strong></span>
          <span><i class="bi bi-person-badge"></i> مديرها: <strong>{{ $assignment?->assignedTo?->name ?? '—' }}</strong></span>
          <span><i class="bi bi-people"></i> موظفوها: <strong>{{ $employees }}</strong></span>
        </div>

        @if($assignment)
          <div data-card-status="{{ $assignment->status }}">
            الحالة:
            <span class="badge bg-{{ $assignment->status === 'completed' ? 'success' : 'warning text-dark' }}">{{ $assignment->status === 'completed' ? 'مكتملة' : 'بانتظار التعبئة' }}</span>
            @if($assignment->completed_at)<span class="text-muted"> — {{ $assignment->completed_at->format('Y-m-d H:i') }}</span>@endif
            @if($assignment->isOpen() && $assignment->draft_saved_at)<span class="text-muted"> — مسودة محفوظة آلياً {{ $assignment->draft_saved_at->format('Y-m-d H:i') }}</span>@endif
          </div>

          @if($isManager && $assignment->isOpen() && $round->state === \App\Modules\Form\Models\FormRound::STATE_SENT)
            <a href="{{ route('forms.fill', $assignment->form_id) }}" class="btn btn-g mt-2" data-fill><i class="bi bi-pencil-square"></i> عبّئ البطاقة</a>
          @endif

          @if($isManager && $assignment->status === 'completed' && $round->state === \App\Modules\Form\Models\FormRound::STATE_SENT)
            {{-- ٢٩-٤: اعتماد المدير — يقفل البطاقة باسمه ووقته (التوقيع اسم ووقت، قرار ٨٧-٦) --}}
            <form method="post" action="{{ route('forms.rounds.approve', $round) }}" class="mt-3"
                  onsubmit="return confirm('تُقفل البطاقة باسمك ووقتك، ثم تصل بنودها التي قلتَ فيها «نعم» موظفي إدارتك ليصدّقوها سرياً. تعتمد؟')">
              @csrf
              <div class="rounded p-3" style="background:#f6faf7;border:1px solid #cfe3d8">
                <div class="mb-2">بعد الاعتماد لا تُعدَّل البطاقة، وتصل بنودها موظفي الإدارة سرياً.</div>
                <button class="btn btn-g" data-approve><i class="bi bi-check2-circle"></i> البيانات صحيحة وأعتمدها</button>
              </div>
            </form>
          @endif

          @if($round->approved_at)
            <div class="mt-2 text-success" data-approved><i class="bi bi-check2-circle"></i> اعتمدها {{ $round->approvedBy?->name ?? '—' }} في {{ $round->approved_at->format('Y-m-d H:i') }}.</div>
          @endif

          @if($assignment->status === 'completed' && $assignment->form && $me->can('results', $assignment->form))
            <a href="{{ route('forms.results', $assignment->form_id) }}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-bar-chart"></i> ما كتبته الإدارة</a>
          @endif
        @else
          <div class="text-muted">لا تكليف في هذه الجولة.</div>
        @endif
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> سجل الجولة — بالاسم والوقت</div>
      <div class="card-body p-0">
        @forelse($round->log ?? [] as $e)
          <div class="px-3 py-2 border-bottom small" data-log="{{ $e['action'] ?? '' }}">
            <div class="fw-bold">{{ \App\Modules\Form\Models\FormRound::ACTION_LABELS[$e['action'] ?? ''] ?? ($e['action'] ?? '') }}</div>
            <div class="text-muted">{{ $e['by'] ?? '—' }} · <span dir="ltr">{{ $e['at'] ?? '' }}</span>@if(!empty($e['note'])) · {{ $e['note'] }}@endif</div>
          </div>
        @empty
          <div class="px-3 py-3 small text-muted">لا خطوات بعد.</div>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
