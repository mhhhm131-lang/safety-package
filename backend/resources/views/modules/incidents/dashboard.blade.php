@extends('layouts.app')
@section('page_title', 'بلاغات الشاغلين')
@section('content')
@php($I = \App\Modules\Incident\Models\Incident::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0">بلاغات الشاغلين</h1>
  <span class="small text-muted">سجل مركز السلامة — ما يصل من الشاغلين بلا دخول. ليست بلاغات الفحص الفني.</span>
  <a class="btn btn-sm btn-outline-success ms-auto" href="{{ route('incident.landing') }}" target="_blank"><i class="bi bi-megaphone"></i> صفحة البلاغ العامة</a>
  @if(\App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'system.settings'))
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('incidents.settings') }}"><i class="bi bi-clock"></i> المهل</a>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.places.qr') }}"><i class="bi bi-qr-code"></i> رموز QR</a>
  @endif
  <a class="btn btn-sm btn-outline-secondary" href="{{ route('incidents.export', request()->query()) }}"><i class="bi bi-download"></i> تصدير</a>
</div>

<div class="row g-2 mb-3">
  @foreach([
    ['open', 'مفتوح', $counts['open'], 'primary'],
    ['received', 'ينتظر إحالة المركز', $counts['waiting_center'], 'info'],
    ['with_field', 'عند الفني', $counts['with_field'], 'warning'],
    ['escalated', 'مُصعَّد', $counts['escalated'], 'danger'],
    ['resolved', 'عولج — ينتظر الإغلاق', $counts['resolved'], 'success'],
    ['overdue', 'متجاوز المهلة', $counts['overdue'], 'danger'],
    ['closed', 'مغلق', $counts['closed'], 'dark'],
  ] as [$k, $label, $n, $color])
    <div class="col-6 col-md">
      <a class="card text-decoration-none text-dark h-100" style="border-top:3px solid var(--bs-{{ $color }})" href="{{ route('incidents.index', ['status' => $k === 'with_field' ? 'in_progress' : ($k === 'escalated' ? 'escalated_to_coord' : $k)]) }}">
        <div class="card-body text-center py-2"><div class="fs-4 fw-bold">{{ $n }}</div><div class="small text-muted">{{ $label }}</div></div>
      </a>
    </div>
  @endforeach
</div>

<form method="get" class="card p-2 mb-3"><div class="row g-2 align-items-end">
  <div class="col-md-2"><label class="form-label small mb-0">الحالة</label><select name="status" class="form-select form-select-sm">
    <option value="">الكل</option><option value="open" @selected(request('status')=='open')>المفتوحة</option><option value="overdue" @selected(request('status')=='overdue')>متجاوزة المهلة</option>
    @foreach($I::STATUS_LABELS as $s => $l)<option value="{{ $s }}" @selected(request('status')==$s)>{{ $l }}</option>@endforeach
  </select></div>
  <div class="col-md-2"><label class="form-label small mb-0">النوع</label><select name="type" class="form-select form-select-sm"><option value="">الكل</option>@foreach($I::TYPES as $t => $l)<option value="{{ $t }}" @selected(request('type')==$t)>{{ $l }}</option>@endforeach</select></div>
  <div class="col-md-2"><label class="form-label small mb-0">المكان</label><select name="place" class="form-select form-select-sm"><option value="">الكل</option>@foreach($places as $p)<option value="{{ $p->code }}" @selected(request('place')==$p->code)>{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
  <div class="col-md-2"><label class="form-label small mb-0">من</label><input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}"></div>
  <div class="col-md-2"><label class="form-label small mb-0">إلى</label><input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}"></div>
  <div class="col-md-2 d-flex gap-1"><input name="search" class="form-control form-control-sm" placeholder="بحث" value="{{ request('search') }}"><button class="btn btn-sm btn-g">بحث</button></div>
</div></form>

<div class="card"><div class="table-responsive"><table class="table table-hover m-0 small">
<thead><tr><th>الرقم</th><th>العنوان</th><th>النوع</th><th>الحالة</th><th>المكان</th><th>الفني</th><th>المبلّغ</th><th>أُرسل</th><th></th></tr></thead>
<tbody>
@forelse($incidents as $i)
  <tr class="{{ $i->isOverdue() ? 'table-danger' : '' }}">
    <td class="fw-bold text-nowrap">{{ $i->code }}</td>
    <td><a href="{{ route('incidents.show', $i) }}" class="text-decoration-none">{{ \Illuminate\Support\Str::limit($i->title, 60) }}</a>
      @if($i->inspection_ref)<span class="badge text-bg-light border" title="فُتح عليه بلاغ فحص">فحص {{ $i->inspection_ref['row'] ?? '' }}</span>@endif</td>
    <td><span class="badge text-bg-{{ $i->incident_type === 'urgent' ? 'danger' : ($i->incident_type === 'secret' ? 'secondary' : 'info') }}">{{ $i->type_label }}</span></td>
    <td><span class="badge text-bg-{{ $I::STATUS_COLORS[$i->status] ?? 'secondary' }}">{{ $i->status_label }}</span>
      @if($i->isOverdue())<span class="badge text-bg-danger">متجاوز</span>@endif
      @if($i->pending_closure)<span class="badge text-bg-warning">ينتظر المبلّغ</span>@endif</td>
    <td class="text-nowrap">{{ $i->place?->code ?? '—' }}{{ $i->location_text ? ' · '.\Illuminate\Support\Str::limit($i->location_text, 20) : '' }}</td>
    <td>{{ $i->incidentFieldTeam?->name ?? '—' }}</td>
    <td>{{ $i->reporterDisplay() }}</td>
    <td class="text-nowrap text-muted">{{ $i->created_at->format('m/d H:i') }}</td>
    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('incidents.show', $i) }}"><i class="bi bi-eye"></i></a></td>
  </tr>
@empty
  <tr><td colspan="9" class="text-center text-muted py-4">لا بلاغات</td></tr>
@endforelse
</tbody></table></div></div>
@if($incidents->hasPages())<div class="mt-3">{{ $incidents->withQueryString()->links() }}</div>@endif
@endsection
