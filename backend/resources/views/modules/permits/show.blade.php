@extends('layouts.app')
@section('page_title', 'التصريح ' . $permit->code)
@section('content')
@php
  $me = auth()->user();
  // الإجراءات المتاحة: حالة التصريح × ما تسمح به السياسة.
  $actions = match ($permit->status) {
    'draft'           => ['submitted' => ['إرسال للمراجعة', 'bi-send', 'btn-g', 'submit']],
    'submitted'       => ['under_review' => ['بدء المراجعة', 'bi-search', 'btn-g', 'review']],
    'under_review'    => [
        'safety_approved' => ['اعتماد السلامة', 'bi-shield-check', 'btn-primary', 'safetyApprove'],
        'approved'        => ['الاعتماد النهائي', 'bi-check-circle', 'btn-success', 'approve'],
        'conditional'     => ['اعتماد مشروط', 'bi-exclamation-circle', 'btn-outline-warning', 'conditional'],
        'rejected'        => ['رفض', 'bi-x-circle', 'btn-outline-danger', 'reject'],
    ],
    'safety_approved' => [
        'approved' => ['الاعتماد النهائي', 'bi-check-circle', 'btn-success', 'approve'],
        'rejected' => ['رفض', 'bi-x-circle', 'btn-outline-danger', 'reject'],
    ],
    'conditional'     => [
        'approved' => ['اعتماد بعد التحقق', 'bi-check-circle', 'btn-success', 'approve'],
        'rejected' => ['رفض', 'bi-x-circle', 'btn-outline-danger', 'reject'],
    ],
    'approved'        => ['active' => ['تفعيل التصريح', 'bi-play-circle', 'btn-success', 'activate']],
    'active'          => [
        'completed' => ['إغلاق (اكتمل العمل)', 'bi-check2-all', 'btn-success', 'complete'],
        'suspended' => ['إيقاف مؤقت', 'bi-pause-circle', 'btn-outline-warning', 'suspend'],
    ],
    'suspended'       => ['active' => ['استئناف', 'bi-play-circle', 'btn-g', 'resume']],
    default           => [],
  };
  // اعتماد بمرحلتين: يُخفى الاعتماد المباشر من المراجعة.
  if ($permit->status === 'under_review' && $requiresTwoStage) { unset($actions['approved']); }
  $actions = array_filter($actions, fn($a) => $me->can($a[3], $permit));
  $critical = ['rejected', 'suspended', 'cancelled'];
  $editable = !$permit->isTerminal() && $me->can('completeRequirement', $permit);
@endphp

{{-- الترويسة --}}
<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <div>
    <div class="small"><a href="{{ route('permits.index') }}" class="text-muted text-decoration-none"><i class="bi bi-arrow-right"></i> التصاريح</a></div>
    <h1 class="h4 m-0">{{ $permit->title }}</h1>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
      <span class="fw-bold" dir="ltr" style="color:var(--g)">{{ $permit->code }}</span>
      @include('modules.permits._status', ['status' => $permit->status])
      <span class="small text-muted">{{ $permit->type?->name }}</span>
      @if($permit->getScopeLabel())<span class="badge bg-light text-dark border">{{ $permit->getScopeLabel() }}</span>@endif
      @if($requiresTwoStage && in_array($permit->status, ['draft','submitted','under_review']))
        <span class="badge bg-primary"><i class="bi bi-shield-exclamation"></i> يتطلب اعتماد السلامة أولاً</span>
      @endif
      @if($permit->expires_at)
        <small class="{{ $permit->expires_at->isPast() ? 'text-danger' : 'text-muted' }}">
          <i class="bi bi-calendar-x"></i> ينتهي {{ $permit->expires_at->format('Y-m-d H:i') }}
        </small>
      @endif
    </div>
  </div>
  <div class="ms-auto d-flex gap-2">
    @if($permit->status === 'draft' && $me->can('update', $permit))
      <a href="{{ route('permits.edit', $permit) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> تعديل</a>
    @endif
    @if($permit->status === 'completed' && $me->can('evaluate', $permit))
      <a href="{{ route('permits.evaluate', $permit) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard-check"></i> التقييم البعدي</a>
    @endif
  </div>
</div>

<div class="row g-3">
  {{-- ═══ يمين: التفاصيل والبنود ═══ --}}
  <div class="col-lg-8">

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-info-circle"></i> معلومات التصريح</div>
      <div class="card-body">
        <div class="row g-3 small">
          @if($permit->place)
            <div class="col-sm-6"><div class="text-muted">المكان</div>
              <div class="fw-bold">{{ $permit->place->code }} — {{ $permit->place->name }}</div></div>
          @endif
          @if($permit->sub_location)
            <div class="col-sm-6"><div class="text-muted">الموضع الدقيق</div><div class="fw-bold">{{ $permit->sub_location }}</div></div>
          @endif
          @if($permit->project)
            <div class="col-sm-6"><div class="text-muted">المشروع</div><div class="fw-bold">{{ $permit->project->name }}</div></div>
          @endif
          @if($permit->externalParty)
            <div class="col-sm-6"><div class="text-muted">المقاول</div><div class="fw-bold">{{ $permit->externalParty->name }}</div></div>
          @endif
          @if($permit->organizationUnit)
            <div class="col-sm-6"><div class="text-muted">الوحدة</div><div class="fw-bold">{{ $permit->organizationUnit->name }}</div></div>
          @endif
          @if($permit->starts_at)
            <div class="col-sm-6"><div class="text-muted">يبدأ</div><div class="fw-bold">{{ $permit->starts_at->format('Y-m-d H:i') }}</div></div>
          @endif
          @if($permit->workers_count !== null || $permit->equipment_count !== null)
            <div class="col-sm-6"><div class="text-muted">الطاقة المطلوبة</div>
              <div class="fw-bold">
                @if($permit->workers_count !== null)<i class="bi bi-person"></i> {{ $permit->workers_count }} عاملاً @endif
                @if($permit->equipment_count !== null)<span class="ms-2"><i class="bi bi-truck"></i> {{ $permit->equipment_count }} معدة</span>@endif
              </div></div>
          @endif
          @if($permit->parent)
            <div class="col-sm-6"><div class="text-muted">التصريح الأب</div>
              <div class="fw-bold"><a href="{{ route('permits.show', $permit->parent) }}">{{ $permit->parent->code }}</a></div></div>
          @endif
          @if($permit->requester_name)
            <div class="col-sm-6"><div class="text-muted">مقدّم الطلب</div>
              <div class="fw-bold">{{ $permit->requester_name }} @if($permit->requester_phone)<span class="text-muted" dir="ltr">· {{ $permit->requester_phone }}</span>@endif</div></div>
          @endif
          @if($permit->description)
            <div class="col-12"><div class="text-muted">وصف العمل</div><div>{{ $permit->description }}</div></div>
          @endif
          @if($permit->precautions)
            <div class="col-12"><div class="text-muted">الاحتياطات</div><div>{{ $permit->precautions }}</div></div>
          @endif
          @if($permit->safety_approved_at)
            <div class="col-12">
              <span class="badge bg-success-subtle text-success border border-success-subtle">
                <i class="bi bi-shield-check"></i> اعتماد السلامة: {{ $permit->safetyApprovedBy?->name ?? '—' }} · {{ $permit->safety_approved_at->format('Y-m-d H:i') }}
              </span>
            </div>
          @endif
          @if($permit->rejection_reason)
            <div class="col-12"><div class="alert alert-danger py-2 mb-0 small"><strong>سبب الرفض:</strong> {{ $permit->rejection_reason }}</div></div>
          @endif
        </div>
      </div>
    </div>

    {{-- المخاطر --}}
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-exclamation-triangle text-warning"></i> المخاطر المرتبطة</span>
        <span class="badge bg-light text-dark border ms-2" data-risks-count>{{ $permit->risks->count() }}</span>
        @if($editable && $availableRisks->isNotEmpty())
          <button class="btn btn-sm btn-outline-primary ms-auto py-0" data-bs-toggle="collapse" data-bs-target="#addRisk">
            <i class="bi bi-plus"></i> ربط خطر
          </button>
        @endif
      </div>
      @if($editable && $availableRisks->isNotEmpty())
        <div class="collapse" id="addRisk">
          <div class="card-body border-bottom">
            <form method="post" action="{{ route('permits.risks.attach', $permit) }}" class="row g-2 align-items-end">
              @csrf
              <div class="col-md-9">
                <select name="risk_id" class="form-select form-select-sm" required>
                  <option value="">— اختر من مخاطر المكان الفعّالة —</option>
                  @foreach($availableRisks as $r)
                    <option value="{{ $r->id }}">{{ $r->code }} — {{ \Illuminate\Support\Str::limit($r->title, 60) }} (درجة {{ $r->risk_score }})</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-3"><button class="btn btn-sm btn-g w-100">ربط وإعادة اشتقاق البنود</button></div>
            </form>
          </div>
        </div>
      @endif
      <div class="card-body p-0">
        @forelse($permit->risks as $risk)
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
            <span class="text-muted" dir="ltr">{{ $risk->code }}</span>
            <span class="flex-grow-1">{{ $risk->title }}</span>
            <x-risk-score-badge :score="$risk->risk_score" />
            <span class="badge {{ $risk->pivot->auto_suggested ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-light text-muted border' }}"
                  style="font-size:.65rem">{{ $risk->pivot->auto_suggested ? 'مقترح آلياً' : 'مضاف يدوياً' }}</span>
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا مخاطر مرتبطة — اربط خطراً من مخاطر المكان.</div>
        @endforelse
      </div>
    </div>

    {{-- تصاريح تستلزمها مخاطر هذا التصريح --}}
    @if($requiredTypes->isNotEmpty())
      <div class="card mb-3 border-info">
        <div class="card-header bg-info-subtle">
          <i class="bi bi-link-45deg"></i> تصاريح تستلزمها مخاطر هذا العمل
        </div>
        <div class="card-body p-0">
          @foreach($requiredTypes as $row)
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small" data-required-type="{{ $row['type']->code }}">
              <span class="badge {{ $row['mandatory'] ? 'bg-danger' : 'bg-secondary' }}">
                {{ $row['mandatory'] ? 'لازم' : 'موصى به' }}
              </span>
              <div class="flex-grow-1">
                <div class="fw-bold">{{ $row['type']->name }}</div>
                <div class="text-muted">بسبب: {{ implode('، ', array_slice($row['triggered_by'], 0, 2)) }}</div>
              </div>
              @if(auth()->user()->can_('permit.create'))
                <a href="{{ route('permits.create', ['step' => 2, 'permit_type_id' => $row['type']->id]) }}"
                   class="btn btn-sm btn-outline-primary py-0">إصدار</a>
              @endif
            </div>
          @endforeach
        </div>
      </div>
    @endif

    {{-- البنود --}}
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-list-check"></i> بنود التحكم</span>
        <span class="ms-auto small fw-bold {{ $completionStats['pct'] === 100 ? 'text-success' : 'text-muted' }}" data-completion>
          {{ $completionStats['mandatory_done'] }}/{{ $completionStats['mandatory_total'] }} إلزامي ({{ $completionStats['pct'] }}%)
        </span>
      </div>
      <div class="card-body p-0">
        @include('modules.permits._requirements', ['editable' => $editable])
      </div>
    </div>

    {{-- العمال --}}
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-people"></i> العمال المعيَّنون</span>
        <span class="badge bg-light text-dark border ms-2" data-workers-count>{{ $permit->workers->count() }}</span>
        @if($editable)
          <button class="btn btn-sm btn-outline-primary ms-auto py-0" data-bs-toggle="collapse" data-bs-target="#addWorker">
            <i class="bi bi-person-plus"></i> إسناد عامل
          </button>
        @endif
      </div>
      @if($editable)
        <div class="collapse" id="addWorker">
          <div class="card-body border-bottom">
            <form method="post" action="{{ route('permits.workers.assign', $permit) }}" class="row g-2 align-items-end">
              @csrf
              <div class="col-md-9">
                <select name="worker_id" class="form-select form-select-sm" required>
                  <option value="">— اختر عاملاً —</option>
                  @foreach($availableWorkers as $w)
                    <option value="{{ $w->id }}">{{ $w->full_name }} — {{ $w->national_id }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-3"><button class="btn btn-sm btn-g w-100">إسناد</button></div>
            </form>
            <div class="form-text">تُفحص أهليته لحظة الإسناد: ثغرة عالية تمنع تفعيل التصريح.</div>
          </div>
        </div>
      @endif
      <div class="card-body p-0">
        @forelse($permit->workers as $pw)
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small" data-worker="{{ $pw->worker_id }}">
            <div class="flex-grow-1">
              <div class="fw-bold">{{ $pw->worker?->full_name ?? '—' }}</div>
              <div class="text-muted">{{ $pw->worker?->trade?->name ?? '' }} @if($pw->worker?->national_id)<span dir="ltr">· {{ $pw->worker->national_id }}</span>@endif</div>
            </div>
            <span class="badge {{ $pw->qualification_status === 'qualified' ? 'bg-success' : ($pw->qualification_status === 'warning' ? 'bg-warning text-dark' : 'bg-danger') }}">
              {{ $pw->getStatusLabel() }}
            </span>
            @if($editable)
              <form method="post" action="{{ route('permits.workers.remove', [$permit, $pw->worker_id]) }}"
                    onsubmit="return confirm('إزالة العامل من التصريح؟')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x"></i></button>
              </form>
            @endif
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا عمال معيَّنون.</div>
        @endforelse
      </div>
    </div>

    {{-- ثغرات العمال --}}
    @if($workerGapReport && $workerGapReport['workers_with_gaps'] > 0)
      <div class="card mb-3 border-warning">
        <div class="card-header bg-warning-subtle d-flex align-items-center">
          <span><i class="bi bi-exclamation-triangle-fill text-warning"></i> ثغرات أهلية العمال</span>
          <span class="badge bg-warning text-dark ms-auto">{{ $workerGapReport['workers_with_gaps'] }} من {{ $workerGapReport['total_workers'] }}</span>
        </div>
        <div class="card-body p-0">
          @foreach($workerGapReport['gaps'] as $entry)
            <div class="px-3 py-2 border-bottom small">
              <div class="fw-bold mb-1"><i class="bi bi-person-fill"></i> {{ $entry['worker_name'] }}
                @if($entry['trade'])<span class="text-muted">· {{ $entry['trade'] }}</span>@endif
              </div>
              <div class="d-flex flex-wrap gap-1">
                @foreach($entry['gaps'] as $gap)
                  <span class="badge {{ $gap['severity'] === 'high' ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-warning-subtle text-dark border' }}"
                        style="font-size:.7rem" title="{{ $gap['detail'] }}">{{ $gap['label'] }}</span>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @endif

    {{-- المرفقات --}}
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-paperclip"></i> المرفقات</span>
        <span class="badge bg-light text-dark border ms-2">{{ $permit->attachments->count() }}</span>
        @if($editable)
          <button class="btn btn-sm btn-outline-primary ms-auto py-0" data-bs-toggle="collapse" data-bs-target="#addAtt">
            <i class="bi bi-upload"></i> رفع
          </button>
        @endif
      </div>
      @if($editable)
        <div class="collapse" id="addAtt">
          <div class="card-body border-bottom">
            <form method="post" action="{{ route('permits.attachments.store', $permit) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
              @csrf
              <div class="col-md-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="اسم المستند" required maxlength="190"></div>
              <div class="col-md-5"><input type="file" name="file" class="form-control form-control-sm" required></div>
              <div class="col-md-2"><button class="btn btn-sm btn-g w-100"><i class="bi bi-upload"></i></button></div>
            </form>
          </div>
        </div>
      @endif
      <div class="card-body p-0">
        @forelse($permit->attachments as $att)
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
            <i class="bi bi-file-earmark-text fs-5 text-muted"></i>
            <div class="flex-grow-1">
              <a href="{{ route('permits.attachments.download', [$permit, $att]) }}" target="_blank" class="fw-bold">{{ $att->name }}</a>
              <div class="text-muted" style="font-size:.75rem">{{ $att->uploadedBy?->name }} · {{ $att->created_at?->format('Y-m-d') }}</div>
            </div>
            @if($editable)
              <form method="post" action="{{ route('permits.attachments.delete', [$permit, $att]) }}" onsubmit="return confirm('حذف المرفق؟')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
              </form>
            @endif
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا مرفقات.</div>
        @endforelse
      </div>
    </div>

    {{-- الانحرافات --}}
    @if($permit->status === 'active' || $permit->deviations->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-exclamation-diamond text-warning"></i> الانحرافات الميدانية</span>
          @if($permit->deviations->where('status', 'open')->count())
            <span class="badge bg-danger ms-2" data-open-deviations>{{ $permit->deviations->where('status', 'open')->count() }} مفتوح</span>
          @endif
          @if($permit->status === 'active' && $me->can('recordDeviation', $permit))
            <button class="btn btn-sm btn-outline-warning ms-auto py-0" data-bs-toggle="collapse" data-bs-target="#addDev">
              <i class="bi bi-plus"></i> تسجيل انحراف
            </button>
          @endif
        </div>
        @if($permit->status === 'active' && $me->can('recordDeviation', $permit))
          <div class="collapse" id="addDev">
            <div class="card-body border-bottom">
              <form method="post" action="{{ route('permits.deviations.store', $permit) }}" class="row g-2">
                @csrf
                <div class="col-md-8">
                  <label class="form-label small mb-1">ما الذي وُجد ويختلف عن المخطط؟ <span class="text-danger">*</span></label>
                  <textarea name="description" class="form-control form-control-sm" rows="3" required minlength="10" maxlength="2000"></textarea>
                </div>
                <div class="col-md-4">
                  <label class="form-label small mb-1">الخطورة</label>
                  <select name="severity" class="form-select form-select-sm">
                    <option value="low">منخفضة</option>
                    <option value="medium" selected>متوسطة</option>
                    <option value="high">عالية</option>
                  </select>
                  <label class="form-label small mb-1 mt-2">الإجراء الفوري</label>
                  <textarea name="corrective_action_taken" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                </div>
                <div class="col-12 text-start"><button class="btn btn-sm btn-warning">تسجيل</button></div>
              </form>
              <div class="form-text">لا يُغلق التصريح وفيه انحراف مفتوح.</div>
            </div>
          </div>
        @endif
        <div class="card-body p-0">
          @forelse($permit->deviations as $dev)
            <div class="px-3 py-2 border-bottom small" data-deviation="{{ $dev->id }}">
              <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge {{ $dev->severity === 'high' ? 'bg-danger' : ($dev->severity === 'medium' ? 'bg-warning text-dark' : 'bg-secondary') }}">
                  {{ $dev->getSeverityLabel() }}
                </span>
                <span class="badge {{ $dev->isOpen() ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-success-subtle text-success border border-success-subtle' }}">
                  {{ $dev->getStatusLabel() }}
                </span>
                <span class="text-muted">{{ $dev->recordedBy?->name }} · {{ $dev->recorded_at?->format('Y-m-d H:i') }}</span>
                @if($dev->isOpen() && $permit->status === 'active' && $me->can('recordDeviation', $permit))
                  <button class="btn btn-sm btn-outline-success py-0 ms-auto" data-bs-toggle="collapse" data-bs-target="#res{{ $dev->id }}">
                    <i class="bi bi-check-lg"></i> إغلاق
                  </button>
                @endif
              </div>
              <div>{{ $dev->description }}</div>
              @if($dev->corrective_action_taken)
                <div class="text-muted mt-1"><i class="bi bi-tools"></i> {{ $dev->corrective_action_taken }}</div>
              @endif
              @if($dev->isOpen() && $permit->status === 'active' && $me->can('recordDeviation', $permit))
                <div class="collapse mt-2" id="res{{ $dev->id }}">
                  <form method="post" action="{{ route('permits.deviations.resolve', [$permit, $dev]) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-8">
                      <textarea name="corrective_action_taken" class="form-control form-control-sm" rows="2"
                                placeholder="صف الإجراء التصحيحي المنجز…" required minlength="10"></textarea>
                    </div>
                    <div class="col-md-4 d-flex gap-1">
                      <button name="resolution_status" value="resolved" class="btn btn-sm btn-success flex-grow-1">عولج</button>
                      <button name="resolution_status" value="accepted" class="btn btn-sm btn-outline-secondary">قُبل بمبرر</button>
                    </div>
                  </form>
                </div>
              @endif
            </div>
          @empty
            <div class="text-center text-muted py-3 small">لا انحرافات — العمل يسير وفق المخطط.</div>
          @endforelse
        </div>
      </div>
    @endif

  </div>

  {{-- ═══ يسار: الإجراء والسجل ═══ --}}
  <div class="col-lg-4">

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-lightning-charge"></i> الإجراء التالي</div>
      <div class="card-body">
        @if($hasBlockingConflicts || !empty($conflicts))
          <div class="alert {{ $hasBlockingConflicts ? 'alert-danger' : 'alert-warning' }} py-2 small">
            <strong><i class="bi bi-sign-stop"></i> {{ $hasBlockingConflicts ? 'تعارض مانع' : 'تنبيه تعارض' }}</strong>
            @foreach($conflicts as $c)
              <div class="mt-1" data-conflict="{{ $c['severity'] }}">
                <span class="badge {{ $c['severity'] === 'block' ? 'bg-danger' : 'bg-warning text-dark' }}" style="font-size:.6rem">
                  {{ $c['severity'] === 'block' ? 'مانع' : 'تنبيه' }}
                </span>
                {{ $c['message'] }}
              </div>
            @endforeach
          </div>
        @endif

        @if(!empty($blockers))
          <div class="alert alert-warning py-2 small" data-blockers="{{ count($blockers) }}">
            <strong><i class="bi bi-exclamation-triangle"></i> يجب استيفاء هذه أولاً</strong>
            <ul class="mb-0 mt-1 ps-3">
              @foreach($blockers as $b)<li>{{ $b }}</li>@endforeach
            </ul>
          </div>
        @endif

        @forelse($actions as $status => [$label, $icon, $cls, $policy])
          @if($status === 'active' && $permit->status === 'approved')
            <a href="{{ route('permits.activate', $permit) }}" class="btn {{ $cls }} w-100 mb-2">
              <i class="bi {{ $icon }}"></i> {{ $label }}
            </a>
          @elseif(in_array($status, $critical))
            <button class="btn {{ $cls }} w-100 mb-2" data-bs-toggle="modal" data-bs-target="#criticalModal"
                    data-status="{{ $status }}" data-label="{{ $label }}">
              <i class="bi {{ $icon }}"></i> {{ $label }}
            </button>
          @else
            <form method="post" action="{{ route('permits.transition', $permit) }}" class="mb-2">
              @csrf
              <input type="hidden" name="to_status" value="{{ $status }}">
              <button class="btn {{ $cls }} w-100"><i class="bi {{ $icon }}"></i> {{ $label }}</button>
            </form>
          @endif
        @empty
          <div class="text-center text-muted small py-2">لا إجراء متاح لك في هذه الحالة.</div>
        @endforelse

        @can('cancel', $permit)
          <hr>
          <button class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#criticalModal"
                  data-status="cancelled" data-label="إلغاء التصريح">
            <i class="bi bi-trash"></i> إلغاء التصريح
          </button>
        @endcan
      </div>
    </div>

    @if(!empty($permit->activation_conditions))
      @php($cond = $permit->activation_conditions)
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-camera"></i> ظروف لحظة التفعيل</div>
        <div class="card-body small">
          @if(!empty($cond['weather']))<div><span class="text-muted">الطقس:</span> <strong>{{ $cond['weather'] }}</strong></div>@endif
          <div><span class="text-muted">العمال:</span>
            <strong class="{{ !empty($cond['workers_confirmed']) ? 'text-success' : 'text-danger' }}">
              {{ !empty($cond['workers_confirmed']) ? 'جاهزون ✓' : 'لم يُؤكَّد' }}</strong></div>
          <div><span class="text-muted">المعدات:</span>
            <strong class="{{ !empty($cond['equipment_checked']) ? 'text-success' : 'text-danger' }}">
              {{ !empty($cond['equipment_checked']) ? 'فُحصت ✓' : 'لم تُفحص' }}</strong></div>
          @if(!empty($cond['notes']))<div class="text-muted mt-1">{{ $cond['notes'] }}</div>@endif
        </div>
      </div>
    @endif

    @if($permit->children->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-diagram-3"></i> التصاريح الفرعية ({{ $permit->children->count() }})</div>
        <div class="card-body p-0">
          @foreach($permit->children as $child)
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
              <i class="bi bi-arrow-down-right text-muted"></i>
              <a href="{{ route('permits.show', $child) }}" class="fw-bold">{{ $child->code }}</a>
              <span class="text-muted flex-grow-1">{{ \Illuminate\Support\Str::limit($child->title, 30) }}</span>
              @include('modules.permits._status', ['status' => $child->status])
            </div>
          @endforeach
        </div>
      </div>
    @endif

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> سجل الأحداث</div>
      <div class="card-body p-0" style="max-height:480px;overflow:auto">
        @forelse($permit->events as $event)
          <div class="px-3 py-2 border-bottom small" data-event="{{ $event->event_type }}">
            <div class="fw-bold">{{ $event->getTypeLabel() }}</div>
            @if($event->from_status && $event->to_status)
              <div class="text-muted">
                {{ \App\Modules\Permit\Models\Permit::STATUS_LABELS[$event->from_status] ?? $event->from_status }}
                <i class="bi bi-arrow-left"></i>
                <strong>{{ \App\Modules\Permit\Models\Permit::STATUS_LABELS[$event->to_status] ?? $event->to_status }}</strong>
              </div>
            @endif
            @if($event->notes)<div class="fst-italic text-muted mt-1">{{ $event->notes }}</div>@endif
            <div class="text-muted" style="font-size:.72rem">
              <i class="bi bi-person"></i> {{ $event->performedBy?->name ?? 'النظام' }} · {{ $event->created_at?->diffForHumans() }}
              @if($event->signature_ip)<span dir="ltr" title="عنوان الجهاز">· {{ $event->signature_ip }}</span>@endif
            </div>
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا أحداث.</div>
        @endforelse
      </div>
    </div>

  </div>
</div>

{{-- نافذة القرار الحرج: السبب إلزامي --}}
<div class="modal fade" id="criticalModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="{{ route('permits.transition', $permit) }}" class="modal-content">
      @csrf
      <input type="hidden" name="to_status" id="criticalStatus">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning"></i> <span id="criticalLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-shield-lock"></i> قرار حرج يُسجَّل بالوقت والمستخدم وعنوان الجهاز. السبب إلزامي.
        </div>
        <label class="form-label">السبب <span class="text-danger">*</span></label>
        <textarea name="notes" class="form-control" rows="4" required minlength="10"
                  placeholder="اذكر السبب بوضوح — يظهر لمقدّم الطلب."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-danger">تأكيد</button>
      </div>
    </form>
  </div>
</div>

@push('scripts')
<script>
document.getElementById('criticalModal')?.addEventListener('show.bs.modal', function (e) {
  var b = e.relatedTarget;
  document.getElementById('criticalStatus').value = b.dataset.status;
  document.getElementById('criticalLabel').textContent = b.dataset.label;
});
</script>
@endpush
@endsection
