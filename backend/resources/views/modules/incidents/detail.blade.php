@extends('layouts.app')
@section('page_title', 'بلاغ '.$incident->code)
@section('content')
@php($I = \App\Modules\Incident\Models\Incident::class)
@php($terminal = in_array($incident->status, $I::TERMINAL, true))
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <a href="{{ route('incidents.index') }}" class="small text-muted">بلاغات الشاغلين</a><span class="text-muted">›</span>
  <h1 class="h4 m-0">{{ $incident->code }}</h1>
  <span class="badge text-bg-{{ $I::STATUS_COLORS[$incident->status] ?? 'secondary' }} fs-6">{{ $incident->status_label }}</span>
  <span class="badge text-bg-{{ $incident->incident_type === 'urgent' ? 'danger' : ($incident->incident_type === 'secret' ? 'secondary' : 'info') }}">{{ $incident->type_label }}</span>
  @if($incident->isOverdue())<span class="badge text-bg-danger">متجاوز المهلة</span>@endif
  @if($incident->pending_closure && !$incident->reporter_approved_closure)<span class="badge text-bg-warning">بانتظار موافقة المبلّغ على الإغلاق</span>@endif
</div>

<div class="row g-3">
<div class="col-lg-8">
  {{-- البلاغ --}}
  <div class="card mb-3"><div class="card-body">
    <h5 class="fw-bold">{{ $incident->title }}</h5>
    <div class="row small text-muted g-2 mb-2">
      <div class="col-md-4"><b>المكان:</b> {{ $incident->place?->code }} {{ $incident->place?->name ?? '—' }}{{ $incident->location_text ? ' — '.$incident->location_text : '' }}</div>
      <div class="col-md-4"><b>المبلّغ:</b> {{ $incident->reporterDisplay() }}@if(!$incident->isSecret() && $incident->reporter_phone) · <span dir="ltr">{{ $incident->reporter_phone }}</span>@endif</div>
      <div class="col-md-4"><b>أُرسل:</b> {{ $incident->created_at->format('Y/m/d H:i') }}@if($incident->deadline_at) · <b>المهلة:</b> {{ $incident->deadline_at->format('m/d H:i') }}@endif</div>
      <div class="col-md-4"><b>الوحدة:</b> {{ $incident->organizationUnit?->name ?? '—' }}</div>
      <div class="col-md-4"><b>المنسق:</b> {{ $incident->incidentCoordinator?->name ?? '—' }}</div>
      <div class="col-md-4"><b>الفني:</b> {{ $incident->incidentFieldTeam?->name ?? 'لم يُحدَّد بعد' }}</div>
    </div>
    <p class="p-3 rounded mb-0" style="background:var(--bg-dark);white-space:pre-wrap">{{ $incident->description }}</p>
    @if($incident->inspection_ref)
      <div class="alert alert-light border mt-2 py-2 small mb-0"><i class="bi bi-clipboard-check me-1"></i> فُتح عليه بلاغ فحص <b>{{ $incident->inspection_ref['row'] ?? '' }}</b> في نموذج المكان — يسير من هناك بمسار المعهد.
        @if($incident->place)<a href="/{{ \App\Modules\Governance\Models\Place::FOLDERS[$incident->place->code] ?? '' }}/inspection-form.html">فتح النموذج ←</a>@endif</div>
    @endif
    @if($incident->resolution_summary)<div class="mt-2 p-2 rounded small" style="background:#eef5f1"><b>ما تم:</b> {{ $incident->resolution_summary }}</div>@endif
    @if($incident->escalation_reason)<div class="mt-2 p-2 rounded small bg-danger-subtle"><b>سبب التصعيد (مستوى {{ $incident->escalation_level }}):</b> {{ $incident->escalation_reason }}</div>@endif
  </div></div>

  {{-- الخطر المرتبط --}}
  <div class="card mb-3"><div class="card-body">
    <h6 class="fw-bold"><i class="bi bi-shield-exclamation me-1"></i> الخطر المرتبط</h6>
    @if($incident->risk)
      @php($risk = $incident->risk)
      <div class="small text-muted">{{ $risk->category?->name ?? '—' }} › {{ $risk->subCategory?->name ?? '—' }} › <b class="text-dark">{{ $risk->title }}</b></div>
      <div class="d-flex gap-2 flex-wrap mt-2 align-items-center">
        <span class="badge text-bg-secondary">{{ $risk->code }}</span>
        <span class="badge text-bg-light border">{{ $risk->risk_type === 'active' ? 'خطر فعلي للمكان' : 'من السجل العام' }}</span>
        <x-risk-score-badge :score="(int) $risk->risk_score" />
        <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('risk.show', $risk) }}">عرض الخطر</a>
      </div>
      <div class="row g-2 mt-2 small">
        <div class="col-md-6"><b>الإجراء التصحيحي:</b><div class="p-2 rounded" style="background:var(--bg-dark);white-space:pre-wrap">{{ $incident->corrective_action ?: 'لا يوجد' }}</div></div>
        <div class="col-md-6"><b>الإجراء الوقائي:</b><div class="p-2 rounded" style="background:var(--bg-dark);white-space:pre-wrap">{{ $incident->preventive_action ?: 'لا يوجد' }}</div></div>
      </div>
      @if($canManage && !$terminal)
        <details class="mt-2 small"><summary class="text-muted">تعديل الإجراءات لهذا البلاغ فقط</summary>
          <form method="post" action="{{ route('incidents.updateActions', $incident) }}" class="mt-2">@csrf @method('PUT')
            <textarea name="corrective_action" class="form-control form-control-sm mb-1" rows="2" placeholder="الإجراء التصحيحي">{{ $incident->corrective_action }}</textarea>
            <textarea name="preventive_action" class="form-control form-control-sm mb-1" rows="2" placeholder="الإجراء الوقائي">{{ $incident->preventive_action }}</textarea>
            <button class="btn btn-sm btn-outline-secondary">حفظ</button></form></details>
      @endif
    @else
      <div class="text-muted small">لم يُربط بخطر (بلاغ سري بلا تصنيف).</div>
      @if($isCenter && !$terminal)
        <form method="post" action="{{ route('incidents.linkRisk', $incident) }}" class="d-flex gap-2 mt-2">@csrf
          <select name="risk_id" class="form-select form-select-sm" required><option value="">اختر من السجل العام…</option>@foreach($referenceRisks as $r)<option value="{{ $r->id }}">{{ $r->code }} — {{ $r->title }}</option>@endforeach</select>
          <button class="btn btn-sm btn-g">ربط</button></form>
      @endif
    @endif
  </div></div>

  {{-- الإجراءات المتاحة بحسب الحالة والدور --}}
  @if(!$terminal)
  <div class="card mb-3"><div class="card-body">
    <h6 class="fw-bold">الإجراءات المتاحة</h6>
    <div class="d-flex flex-wrap gap-2">
      @if($isCenter && in_array($incident->status, ['new', 'received', 'referred', 'ref_received'], true))
        <button class="btn btn-g" data-bs-toggle="modal" data-bs-target="#referModal"><i class="bi bi-person-check me-1"></i> إحالة إلى فني المكان</button>
        @if($incident->status === 'received' || $incident->status === 'new')
          <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#closeNoteModal"><i class="bi bi-x-circle me-1"></i> إغلاق بملاحظة (لا يحتاج فنياً)</button>
        @endif
      @endif
      @if($isCenter && in_array($incident->status, ['forwarded', 'field_received', 'in_progress', 'escalated_to_coord'], true))
        <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#referModal"><i class="bi bi-arrow-repeat me-1"></i> إعادة الإحالة لفني آخر</button>
      @endif
      @if($isField && $incident->status === 'forwarded')
        <form method="post" action="{{ route('incidents.fieldReceive', $incident) }}">@csrf<button class="btn btn-g"><i class="bi bi-hand-thumbs-up me-1"></i> استلمتُ البلاغ</button></form>
      @endif
      @if($isField && $incident->status === 'field_received')
        <form method="post" action="{{ route('incidents.beginWork', $incident) }}">@csrf<button class="btn btn-g"><i class="bi bi-play-circle me-1"></i> بدء المعالجة</button></form>
      @endif
      @if($isField && in_array($incident->status, ['field_received', 'in_progress'], true))
        @if($incident->status === 'in_progress')
          <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#resolveModal"><i class="bi bi-check-circle me-1"></i> عولج (صورة + ملخص)</button>
        @endif
        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#escCoordModal"><i class="bi bi-arrow-up-circle me-1"></i> تصعيد للمنسق</button>
      @endif
      @if($isCoord && $incident->status === 'escalated_to_coord')
        <form method="post" action="{{ route('incidents.resolveEscalation', $incident) }}">@csrf<button class="btn btn-g">أتولى المعالجة</button></form>
        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#escMgrModal">تصعيد للجنة السلامة</button>
      @endif
      @if($isCommittee && $incident->status === 'escalated_to_manager')
        <form method="post" action="{{ route('incidents.resolveEscalation', $incident) }}">@csrf<button class="btn btn-g">أتولى المعالجة</button></form>
        <form method="post" action="{{ route('incidents.close', $incident) }}">@csrf<button class="btn btn-dark">إغلاق</button></form>
      @endif
      @if($incident->status === 'resolved')
        @php($needsReporter = $incident->actor_id !== null && in_array($incident->incident_type, ['normal', 'urgent'], true))
        @if(auth()->id() === $incident->actor_id && $incident->pending_closure)
          <form method="post" action="{{ route('incidents.approveClosure', $incident) }}">@csrf<button class="btn btn-success"><i class="bi bi-hand-thumbs-up me-1"></i> أوافق على الإغلاق — عولج فعلاً</button></form>
          <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">لم يُعالج — أعِده</button>
        @endif
        @if(!$needsReporter && !$incident->coord_verified_at && ($isCoord || $isCenter))
          <form method="post" action="{{ route('incidents.verify', $incident) }}">@csrf<button class="btn btn-info"><i class="bi bi-shield-check me-1"></i> تحققتُ ميدانياً</button></form>
        @endif
        @if($isCenter)
          <form method="post" action="{{ route('incidents.close', $incident) }}">@csrf<button class="btn btn-dark"><i class="bi bi-lock me-1"></i> {{ $needsReporter && !$incident->reporter_approved_closure ? ($incident->pending_closure ? 'إعادة طلب الموافقة' : 'طلب موافقة المبلّغ ثم الإغلاق') : 'إغلاق نهائي' }}</button></form>
          <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">رفض — أعِده للمعالجة</button>
        @elseif($isCoord)
          <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">رفض — أعِده للمعالجة</button>
        @endif
      @endif
      @if($isCenter)
        <button class="btn btn-outline-secondary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#oosModal">خارج النطاق</button>
      @endif
    </div>
    @if(!$isCenter && !$isField && !$isCoord && !$isCommittee && auth()->id() !== $incident->actor_id)
      <div class="small text-muted mt-2">اطلاع فقط — الإجراءات للمعيَّنين على هذا البلاغ ومركز السلامة.</div>
    @endif
  </div></div>
  @endif

  {{-- المرفقات --}}
  <div class="card mb-3"><div class="card-body">
    <h6 class="fw-bold"><i class="bi bi-paperclip me-1"></i> المرفقات <span class="badge text-bg-light border">{{ $incident->attachments->count() }}</span></h6>
    <div class="d-flex flex-wrap gap-2">
      @forelse($incident->attachments as $a)
        <a href="{{ route('incidents.attachment', [$incident, $a]) }}" target="_blank" class="text-decoration-none text-center small" style="width:120px">
          @if($a->isImage())<img src="{{ route('incidents.attachment', [$incident, $a]) }}" style="width:120px;height:90px;object-fit:cover;border-radius:6px;border:1px solid var(--border-color)">@else<div style="width:120px;height:90px;border:1px solid var(--border-color);border-radius:6px;display:flex;align-items:center;justify-content:center"><i class="bi bi-file-earmark-pdf fs-3"></i></div>@endif
          <div class="text-muted">{{ $a->kind === 'evidence' ? 'دليل المعالجة' : 'من المبلّغ' }}</div>
        </a>
      @empty
        <span class="text-muted small">لا مرفقات</span>
      @endforelse
    </div>
    @if($canManage && !$terminal)
      <form method="post" action="{{ route('incidents.upload', $incident) }}" enctype="multipart/form-data" class="d-flex gap-2 mt-2">@csrf
        <input type="file" name="file" class="form-control form-control-sm" accept="image/*,application/pdf" required>
        <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> رفع دليل</button></form>
    @endif
  </div></div>

  {{-- الملاحظات --}}
  <div class="card mb-3"><div class="card-body">
    <h6 class="fw-bold">الملاحظات</h6>
    <form method="post" action="{{ route('incidents.addNote', $incident) }}" class="d-flex gap-2 mb-2">@csrf
      <input name="note" class="form-control form-control-sm" placeholder="أضف ملاحظة…" required maxlength="5000"><button class="btn btn-sm btn-outline-primary">إضافة</button></form>
    @forelse($incident->events->where('action', 'note') as $n)
      <div class="p-2 rounded mb-1 small" style="background:var(--bg-dark)"><b>{{ $n->actor?->name ?? 'النظام' }}</b> <span class="text-muted">· {{ $n->created_at->format('Y/m/d H:i') }}</span><div>{{ $n->note }}</div></div>
    @empty
      <div class="text-muted small">لا ملاحظات</div>
    @endforelse
  </div></div>
</div>

{{-- الخط الزمني --}}
<div class="col-lg-4">
  <div class="card"><div class="card-body">
    <h6 class="fw-bold"><i class="bi bi-clock-history me-1"></i> الخط الزمني</h6>
    @foreach($incident->events as $ev)
      <div class="d-flex gap-2 mb-2 small">
        <i class="bi bi-circle-fill mt-1" style="font-size:.5rem;color:var(--accent)"></i>
        <div><b>{{ $ev->action_label }}</b>
          <div class="text-muted">{{ $incident->isSecret() && $ev->action === 'create' ? 'المبلّغ (مخفي)' : ($ev->actor?->name ?? 'النظام') }} · {{ $ev->created_at->format('Y/m/d H:i') }}</div>
          @if($ev->note)<div class="text-muted">{{ $ev->note }}</div>@endif</div>
      </div>
    @endforeach
  </div></div>
  @if($incident->secret_tracking_code && $isCenter)
    <div class="card mt-3"><div class="card-body small"><b>رمز تتبع المبلّغ:</b> <span class="font-monospace" dir="ltr">{{ $incident->secret_tracking_code }}</span><div class="text-muted">يظهر للمبلّغ الخط الزمني نفسه بلا هوية.</div></div></div>
  @endif
</div>
</div>

{{-- النوافذ --}}
<div class="modal fade" id="referModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.refer', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">إحالة إلى فني المكان</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <label class="form-label">الفني <span class="text-danger">*</span></label>
    <select name="field_worker_id" class="form-select" required><option value="">اختر…</option>
      @foreach($fieldWorkers as $u)<option value="{{ $u->id }}" @selected($u->id === $incident->incident_field_team_id)>{{ $u->name }}{{ $u->profile?->place ? ' — '.$u->profile->place->code.' '.$u->profile->place->name : '' }}</option>@endforeach</select>
    <label class="form-label mt-2">منسق السلامة (اختياري)</label>
    <select name="coordinator_id" class="form-select"><option value="">—</option>@foreach($coordinators as $u)<option value="{{ $u->id }}" @selected($u->id === $incident->incident_coordinator_id)>{{ $u->name }}</option>@endforeach</select>
    <label class="form-label mt-2">ملاحظة للفني</label><textarea name="note" class="form-control" rows="2" maxlength="2000"></textarea>
    <div class="small text-muted mt-2">تُعاد المهلة من وقت الإحالة، ويظهر البلاغ في شريط «بلاغات شاغلين لهذا المكان» في نموذج الفحص.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-g">إحالة</button></div></form></div></div>

<div class="modal fade" id="closeNoteModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.closeWithNote', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">إغلاق بملاحظة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">ما الذي تم أو سبب الإغلاق (يُقيَّد في السجل) <span class="text-danger">*</span></label><textarea name="note" class="form-control" rows="3" required minlength="5"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-dark">إغلاق البلاغ</button></div></form></div></div>

<div class="modal fade" id="resolveModal"><div class="modal-dialog modal-lg"><form method="post" action="{{ route('incidents.resolve', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">عولج — تأكيد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    @php($ev = $incident->attachments->where('kind', 'evidence')->count())
    <div class="alert alert-light border small">المطلوب: ملخص ≥ ٣٠ حرفاً + صورة/مستند دليل واحد على الأقل (ارفعه من قسم المرفقات). الأدلة المرفوعة: <b>{{ $ev }}</b>@if(!$ev) <span class="text-danger">— ارفع دليلاً أولاً</span>@endif</div>
    <textarea name="resolution_summary" class="form-control" rows="5" required minlength="30" placeholder="ما الذي تم في الميدان: الإجراء، الأدوات، الوقت، حالة الموقع بعد المعالجة…"></textarea>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success" @disabled(!$ev)>تأكيد</button></div></form></div></div>

<div class="modal fade" id="escCoordModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.escalateToCoordinator', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">تصعيد إلى منسق السلامة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">لماذا تعذّرت المعالجة؟ <span class="text-danger">*</span></label><textarea name="reason" class="form-control" rows="3" required minlength="10"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-warning">تصعيد</button></div></form></div></div>

<div class="modal fade" id="escMgrModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.escalateToManager', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">تصعيد إلى لجنة السلامة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">السبب <span class="text-danger">*</span></label><textarea name="reason" class="form-control" rows="3" required minlength="10"></textarea><div class="small text-muted mt-1">حتى تشكيل اللجنة يصل التصعيد إلى مسؤول السلامة.</div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-danger">تصعيد</button></div></form></div></div>

<div class="modal fade" id="rejectModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.rejectClosure', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">رفض الإغلاق — إعادة للمعالجة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">لماذا؟ <span class="text-danger">*</span></label><textarea name="note" class="form-control" rows="3" required></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-outline-danger">إعادة</button></div></form></div></div>

<div class="modal fade" id="oosModal"><div class="modal-dialog"><form method="post" action="{{ route('incidents.outOfScope', $incident) }}" class="modal-content">@csrf
  <div class="modal-header"><h5 class="modal-title">خارج النطاق</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">ملاحظة</label><textarea name="note" class="form-control" rows="2"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-outline-secondary">تأكيد</button></div></form></div></div>
@endsection
