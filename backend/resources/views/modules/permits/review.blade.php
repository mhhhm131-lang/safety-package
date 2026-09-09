@extends('layouts.app')
@section('page_title', 'مراجعة بنود التصريح ' . $permit->code)
@section('content')
@php($isQual = $permit->permit_category === 'qualification')

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  @foreach([1 => 'اختيار النوع', 2 => 'السياق والمخاطر', 3 => 'مراجعة البنود'] as $i => $label)
    <div class="d-flex align-items-center gap-1 {{ $i <= 3 ? 'fw-bold' : 'text-muted' }}" style="color:var(--g)">
      <i class="bi bi-{{ $i < 3 ? 'check-circle-fill' : '3-circle-fill' }} fs-5"></i>{{ $label }}
    </div>
    @if(!$loop->last)<div class="flex-grow-1" style="max-width:60px;height:2px;background:var(--g)"></div>@endif
  @endforeach
</div>

<div class="card mb-3">
  <div class="card-body py-3 d-flex align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-bold"><span class="badge bg-light text-dark border" dir="ltr">{{ $permit->code }}</span> {{ $permit->title }}</div>
      <div class="small text-muted">
        {{ $permit->type?->name }}
        @if($permit->place) · {{ $permit->place->name }} @endif
        @if($permit->externalParty) · {{ $permit->externalParty->name }} @endif
      </div>
    </div>
    <div class="ms-auto text-start">
      <div class="fw-bold" style="color:var(--g)">{{ $completionStats['mandatory_total'] }} بنداً إلزامياً</div>
      <div class="small text-muted">{{ $isQual ? 'من كتالوج التأهيل' : 'مشتقة من بنود تحكم المخاطر' }}</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    @if($permit->risks->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-exclamation-triangle text-warning"></i> المخاطر المرتبطة آلياً ({{ $permit->risks->count() }})</div>
        <div class="card-body p-0">
          @foreach($permit->risks->take(10) as $risk)
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
              <span class="text-muted" dir="ltr">{{ $risk->code }}</span>
              <span class="flex-grow-1">{{ $risk->title }}</span>
              <x-risk-score-badge :score="$risk->risk_score" />
            </div>
          @endforeach
          @if($permit->risks->count() > 10)
            <div class="px-3 py-2 small text-muted">و{{ $permit->risks->count() - 10 }} خطراً آخر…</div>
          @endif
        </div>
      </div>
    @endif

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-list-check"></i> البنود المولَّدة</div>
      <div class="card-body p-0">
        @include('modules.permits._requirements', ['editable' => false])
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header">الخطوة التالية</div>
      <div class="card-body">
        <div class="alert alert-light py-2 small border">
          <i class="bi bi-info-circle"></i>
          البنود أُنشئت آلياً. لا يُفعَّل التصريح حتى تكتمل بنوده الإلزامية
          ({{ $completionStats['mandatory_total'] }}) ميدانياً.
        </div>

        @can('submit', $permit)
          <form method="post" action="{{ route('permits.transition', $permit) }}" class="mb-2">
            @csrf
            <input type="hidden" name="to_status" value="submitted">
            <input type="hidden" name="notes" value="روجعت البنود المولَّدة عند الإنشاء">
            <button class="btn btn-g w-100"><i class="bi bi-send"></i> إرسال للمراجعة</button>
          </form>
        @endcan

        <a href="{{ route('permits.show', $permit) }}" class="btn btn-outline-secondary w-100">
          <i class="bi bi-floppy"></i> حفظ كمسودة وإكمال لاحقاً
        </a>
      </div>
    </div>

    @if($availableWorkers->isNotEmpty() && !$permit->isTerminal())
      <div class="card">
        <div class="card-header"><i class="bi bi-people"></i> إسناد عمال (اختياري الآن)</div>
        <div class="card-body">
          <form method="post" action="{{ route('permits.workers.assign', $permit) }}" class="row g-2">
            @csrf
            <div class="col-12">
              <select name="worker_id" class="form-select form-select-sm" required>
                <option value="">— اختر عاملاً —</option>
                @foreach($availableWorkers as $w)
                  <option value="{{ $w->id }}">{{ $w->full_name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-person-plus"></i> إسناد</button></div>
          </form>
          @if($permit->workers->isNotEmpty())
            <hr>
            @foreach($permit->workers as $pw)
              <div class="small"><i class="bi bi-person-fill-check text-success"></i> {{ $pw->worker?->full_name }}</div>
            @endforeach
          @endif
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
