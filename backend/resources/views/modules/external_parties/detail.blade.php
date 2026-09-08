@extends('layouts.app')
@section('page_title', $party->name)
@section('content')
@php($me = auth()->user())
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-building me-2"></i>{{ $party->name }} <small class="text-muted fs-6">{{ $party->getTypeLabel() }}</small></h1>
  <span class="badge {{ $party->status === 'active' ? 'bg-success' : ($party->status === 'blocked' ? 'bg-danger' : 'bg-secondary') }}">{{ $party->getStatusLabel() }}</span>
  @if($party->profile?->trust_score !== null)<span class="badge bg-light text-dark border" data-trust="{{ $party->profile->trust_score }}">الثقة {{ $party->profile->trust_score }}/100</span>@endif
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="{{ route('external-parties.profile', $party) }}" class="btn btn-sm btn-primary"><i class="bi bi-patch-check"></i> ملف التأهيل</a>
    <a href="{{ route('external-parties.documents', $party) }}" class="btn btn-sm btn-outline-secondary">المستندات ({{ $party->documents->count() }})</a>
    <a href="{{ route('competency.contractor', $party) }}" class="btn btn-sm btn-outline-secondary">كفاءة العمال</a>
    @if($me->can_('external_party.list') && !$me->isContractorRole())<a href="{{ route('external-parties.risks', $party) }}" class="btn btn-sm btn-outline-secondary">المخاطر</a>@endif
    @if($me->can_('external_party.evaluate'))<a href="{{ route('external-parties.evaluation.create', $party) }}" class="btn btn-sm btn-outline-secondary">تقييم</a>@endif
    @if($me->can_('external_party.edit'))<a href="{{ route('external-parties.edit', $party) }}" class="btn btn-sm btn-outline-warning">تعديل</a>@endif
    @unless($me->isContractorRole())<a href="{{ route('external-parties.index') }}" class="btn btn-sm btn-outline-secondary">رجوع</a>@endunless
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">البيانات</h2>
      <table class="table table-sm mb-0">
        <tr><th style="width:140px">السجل التجاري</th><td dir="ltr" class="text-start">{{ $party->cr_number ?? '—' }}</td></tr>
        <tr><th>جهة الاتصال</th><td>{{ $party->contact_person ?? '—' }}</td></tr>
        <tr><th>الهاتف</th><td dir="ltr" class="text-start">{{ $party->phone ?? '—' }}</td></tr>
        <tr><th>البريد</th><td dir="ltr" class="text-start">{{ $party->email ?? '—' }}</td></tr>
        <tr><th>العنوان</th><td>{{ $party->address ?? '—' }}</td></tr>
        <tr><th>الموقع</th><td dir="ltr" class="text-start">@if($party->website_url)<a href="{{ $party->website_url }}" target="_blank" rel="noopener">{{ $party->website_url }}</a>@else —@endif</td></tr>
        <tr><th>الحسابات</th><td>@forelse($party->users as $u)<span class="badge bg-light text-dark border">{{ $u->username }} — {{ $u->roleName() }}</span> @empty <span class="text-muted">لا حساب بعد — يُنشأ من شاشة المستخدمين بدور مقاول/مشرف مقاول مربوطاً بهذا الطرف</span>@endforelse</td></tr>
      </table>
      @if($party->notes)<div class="small text-muted mt-2">{{ $party->notes }}</div>@endif
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">المشاريع وحالة التأهيل</h2>
      <table class="table table-sm mb-0" id="party-projects">
        <thead><tr><th>المشروع</th><th>المكان</th><th>الدور</th><th>التأهيل</th><th>العقد</th></tr></thead>
        <tbody>
        @forelse($party->projectAssignments as $pc)
          <tr data-status="{{ $pc->qualification_status }}">
            <td><a href="{{ route('projects.contractors', $pc->project) }}">{{ $pc->project?->name }}</a></td>
            <td>{{ $pc->project?->place?->code ?? '—' }}</td>
            <td>{{ $pc->getRoleLabel() }}</td>
            <td><span class="badge {{ $pc->qualification_status === 'post_approved' ? 'bg-success' : ($pc->qualification_status === 'suspended' ? 'bg-danger' : 'bg-secondary') }}">{{ $pc->getStatusLabel() }}</span></td>
            <td class="small text-muted">{{ $pc->contract_start_date?->toDateString() ?? '—' }} → {{ $pc->contract_end_date?->toDateString() ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-3">لم يُربط بمشروع بعد</td></tr>
        @endforelse
        </tbody>
      </table>
    </div></div>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex align-items-center mb-2"><h2 class="h6 m-0">العمال ({{ $party->workers->count() }})</h2>
        @if($me->can_('worker.create'))<a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('workers.create') }}">تسجيل عامل</a>@endif
        <a class="btn btn-sm btn-outline-secondary ms-2" href="{{ route('workers.index', ['external_party_id' => $party->id]) }}">الكل</a></div>
      <table class="table table-sm mb-0" id="party-workers">
        <thead><tr><th>الاسم</th><th>الهوية</th><th>المهنة</th><th>الحالة</th></tr></thead>
        <tbody>
        @forelse($party->workers->take(10) as $w)
          <tr><td><a href="{{ route('workers.show', $w) }}">{{ $w->full_name }}</a></td><td dir="ltr">{{ $w->national_id }}</td><td>{{ $w->trade?->name }}</td><td><span class="badge {{ in_array($w->status, ['approved','work_authorized','role_authorized']) ? 'bg-success' : (in_array($w->status, ['blocked','suspended']) ? 'bg-danger' : 'bg-secondary') }}">{{ $w->getStatusLabel() }}</span></td></tr>
        @empty
          <tr><td colspan="4" class="text-center text-muted py-3">لا عمال مسجّلون</td></tr>
        @endforelse
        </tbody>
      </table>
    </div></div>
    <div class="card"><div class="card-body">
      <h2 class="h6">التقييمات</h2>
      <table class="table table-sm mb-0">
        <thead><tr><th>الفترة</th><th>المشروع</th><th>سلامة</th><th>جودة</th><th>امتثال</th><th>الإجمالي</th></tr></thead>
        <tbody>
        @forelse($party->evaluations as $ev)
          <tr><td class="small">{{ $ev->period_from->toDateString() }} → {{ $ev->period_to->toDateString() }}</td><td>{{ $ev->project?->name ?? '—' }}</td><td>{{ $ev->safety_score }}</td><td>{{ $ev->quality_score }}</td><td>{{ $ev->compliance_score }}</td><td><strong>{{ $ev->overall_score }}</strong></td></tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-3">لا تقييمات</td></tr>
        @endforelse
        </tbody>
      </table>
    </div></div>
  </div>
</div>
@endsection
