@extends('layouts.app')
@section('page_title', $worker->full_name)
@section('content')
@php($me = auth()->user())
@php($labels = \App\Modules\Worker\Models\Worker::STATUSES)
@php($colors = ['draft' => 'secondary', 'submitted' => 'info', 'induction' => 'info', 'training' => 'warning', 'approved' => 'success', 'work_authorized' => 'success', 'role_authorized' => 'success', 'blocked' => 'danger', 'suspended' => 'warning'])
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-person-badge me-2"></i>{{ $worker->full_name }}</h1>
  <span class="badge bg-{{ $colors[$worker->status] ?? 'secondary' }} fs-6" data-worker-status="{{ $worker->status }}">{{ $worker->getStatusLabel() }}</span>
  <div class="ms-auto d-flex gap-2">
    <a href="{{ route('competency.worker', $worker) }}" class="btn btn-sm btn-outline-secondary">الكفاءة</a>
    <a href="{{ route('workers.documents', $worker) }}" class="btn btn-sm btn-outline-secondary">المستندات ({{ $worker->documents->count() }})</a>
    @if($me->can_('worker.edit'))<a href="{{ route('workers.edit', $worker) }}" class="btn btn-sm btn-outline-warning">تعديل</a>@endif
    <a href="{{ route('workers.index') }}" class="btn btn-sm btn-outline-secondary">رجوع</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="row g-2 small">
        <div class="col-md-4"><span class="text-muted">رقم الهوية</span><div dir="ltr" class="text-start fw-bold">{{ $worker->national_id }}</div></div>
        <div class="col-md-4"><span class="text-muted">المهنة</span><div>{{ $worker->trade?->name ?? '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">الطرف الخارجي</span><div><a href="{{ route('external-parties.show', $worker->external_party_id) }}">{{ $worker->externalParty?->name }}</a></div></div>
        <div class="col-md-4"><span class="text-muted">المشروع</span><div>{{ $worker->project?->name ?? '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">مكان العمل</span><div>{{ $worker->place ? $worker->place->code.' · '.$worker->place->name : '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">الهاتف</span><div dir="ltr" class="text-start">{{ $worker->phone ?? '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">انتهاء الإقامة</span><div>{{ $worker->iqama_expiry?->toDateString() ?? '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">انتهاء الفحص الطبي</span><div>{{ $worker->medical_expiry?->toDateString() ?? '—' }}</div></div>
        <div class="col-md-4"><span class="text-muted">الالتحاق</span><div>{{ $worker->joined_date?->toDateString() ?? $worker->created_at->toDateString() }}</div></div>
        @if($worker->blocked_reason)<div class="col-12"><span class="text-danger">سبب الحظر: {{ $worker->blocked_reason }}</span></div>@endif
      </div>
    </div></div>

    @if(count($transitions))
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">الخطوة التالية في دورة الحياة</h2>
      <div class="d-flex gap-2 flex-wrap" id="worker-transitions">
        @foreach($transitions as $t)
          <form method="POST" action="{{ route('workers.transition', $worker) }}" class="d-inline" data-transition="{{ $t }}">
            @csrf<input type="hidden" name="status" value="{{ $t }}">
            @if(in_array($t, ['blocked', 'suspended']))<input name="note" class="form-control form-control-sm d-inline-block me-1" style="width:200px" placeholder="السبب (إلزامي للحظر)">@endif
            <button class="btn btn-sm {{ in_array($t, ['blocked','suspended']) ? 'btn-outline-danger' : 'btn-g' }}">{{ $labels[$t] ?? $t }}</button>
          </form>
        @endforeach
      </div>
      <div class="small text-muted mt-2">المسار: مسودة ← مقدَّم ← تعريف ← تدريب ← معتمد ← مصرّح بالعمل ← مصرّح بالدور. مشرف المقاول يقدّم؛ الاعتماد لمسؤول السلامة والمناوب والمنسق.</div>
    </div></div>
    @endif

    <div class="card"><div class="card-body">
      <h2 class="h6">سجل التدريب</h2>
      <table class="table table-sm mb-2">
        <thead><tr><th>الموضوع</th><th>الحالة</th><th>أُكمل</th><th>ينتهي</th><th>الشهادة</th></tr></thead>
        <tbody>
        @forelse($worker->trainingRecords as $rec)
          <tr><td>{{ $rec->trainingTopic?->name }}</td><td>{{ ['pending' => 'قيد التنفيذ', 'completed' => 'مكتمل', 'expired' => 'منتهٍ', 'waived' => 'معفى'][$rec->status] ?? $rec->status }}</td><td>{{ $rec->completed_at?->toDateString() ?? '—' }}</td><td>{{ $rec->expires_at?->toDateString() ?? '—' }}</td><td dir="ltr">{{ $rec->certificate_number ?? '—' }}</td></tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-2">لا سجل تدريب</td></tr>
        @endforelse
        </tbody>
      </table>
      @if($me->can_('worker.manage'))
      <form method="POST" action="{{ route('workers.training.store', $worker) }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-4"><label class="form-label small">الموضوع</label><select name="training_topic_id" class="form-select form-select-sm" required>@foreach(\App\Modules\Worker\Models\TrainingTopic::where('is_active', true)->orderBy('code')->get() as $tp)<option value="{{ $tp->id }}">{{ $tp->code }} · {{ $tp->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small">الحالة</label><select name="status" class="form-select form-select-sm"><option value="completed">مكتمل</option><option value="pending">قيد التنفيذ</option><option value="waived">معفى</option><option value="expired">منتهٍ</option></select></div>
        <div class="col-md-2"><label class="form-label small">أُكمل في</label><input type="date" name="completed_at" class="form-control form-control-sm" value="{{ now()->toDateString() }}"></div>
        <div class="col-md-2"><label class="form-label small">ينتهي</label><input type="date" name="expires_at" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">تسجيل</button></div>
      </form>
      @endif
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h6">السجل الزمني</h2>
      <ul class="list-unstyled small mb-0">
        @forelse($worker->statusEvents->sortByDesc('created_at') as $ev)
          <li class="border-bottom py-1"><span class="text-muted" dir="ltr">{{ $ev->created_at?->format('Y-m-d H:i') }}</span> — {{ $labels[$ev->from_status] ?? $ev->from_status }} ← <strong>{{ $labels[$ev->to_status] ?? $ev->to_status }}</strong>@if($ev->actor) ({{ $ev->actor->name }})@endif @if($ev->note)<div class="text-muted">{{ $ev->note }}</div>@endif</li>
        @empty
          <li class="text-muted">لا أحداث</li>
        @endforelse
      </ul>
    </div></div>
  </div>
</div>
@endsection
