@extends('layouts.app')
@section('title', 'الهيكل التنظيمي')
@section('content')
<div class="d-flex align-items-center mb-3">
  <h1 class="h4 m-0">الهيكل التنظيمي <span class="text-muted fs-6">{{ $count }} وحدة</span></h1>
  <a class="btn btn-g btn-sm ms-auto" href="{{ route('app.org.create') }}"><i class="bi bi-plus"></i> إدارة أو قسم</a>
</div>
<div class="small text-muted mb-2">التعديل هنا يظهر فوراً في لوحة العمل اليومي، والعكس. لا تُحذف وحدة لها أقسام أو موظفون. اضغط الرأس فتظهر إداراته وأقسامه.</div>
<style>
  .org-head{display:flex;align-items:center;gap:.6rem;width:100%;background:#fff;border:1px solid var(--line);border-radius:10px;padding:.6rem .9rem;text-align:start;color:var(--ink);cursor:pointer;font-weight:700}
  .org-head:hover{border-color:var(--g);color:var(--g)}
  .org-head:not(.collapsed){border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom-color:transparent}
  .org-head .caret{transition:transform .2s;color:var(--mut)}.org-head:not(.collapsed) .caret{transform:rotate(180deg)}
  .org-body{border:1px solid var(--line);border-top:0;border-radius:0 0 10px 10px;background:#fff}
</style>
{{-- بكلمته (٢٠٢٦-١٠-٠٨): الرؤوس وحدها (المركز الرئيسي والفروع) مطوية، وكل رأس يُفتح على إداراته وأقسامه --}}
<div class="d-grid gap-2">
@foreach($groups as $g)
@php($h = $g['head'])
<div data-group="{{ $h->code }}">
  <button class="org-head collapsed {{ $h->is_active ? '' : 'text-muted' }}" type="button" data-bs-toggle="collapse" data-bs-target="#grp-{{ $h->id }}" aria-expanded="false" aria-controls="grp-{{ $h->id }}">
    <i class="bi {{ $h->parent_id === null ? 'bi-building' : 'bi-geo-alt' }}" style="color:var(--gold)"></i>
    <span>{{ $h->name }}</span>
    <span class="badge text-bg-light border fw-normal">{{ \App\Modules\Governance\Models\OrganizationUnit::TYPE_LABELS[$h->unit_type] ?? $h->unit_type }}</span>
    <span class="small text-muted fw-normal">{{ count($g['rows']) }} وحدة{{ ($h->manager?->name ?? $h->manager_name) ? ' · '.($h->manager?->name ?? $h->manager_name) : '' }}</span>
    <i class="bi bi-chevron-down caret ms-auto"></i>
  </button>
  <div class="collapse" id="grp-{{ $h->id }}">
    <div class="org-body">
      <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
        <span class="text-muted" dir="ltr">{{ $h->code }}</span>
        <span class="text-muted">المكان: {{ $h->place?->name ?? '—' }}</span>
        <span class="ms-auto text-nowrap">
          <a class="btn btn-sm btn-outline-success" href="{{ route('risk.active.index', ['unit' => $h->code]) }}" title="سجل مخاطر هذا الرأس وما تحته"><i class="bi bi-lightning-charge"></i> المخاطر</a>
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.org.edit', $h) }}">تعديل</a>
          <form method="post" action="{{ route('app.org.destroy', $h) }}" class="d-inline" onsubmit="return confirm('حذف «{{ $h->name }}»؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">حذف</button></form>
        </span>
      </div>
      @if(!$g['rows'])
        <div class="px-3 py-3 text-muted small">لا إدارات بعد — <a href="{{ route('app.org.create') }}">أضف إدارة</a> واجعلها تتبع «{{ $h->name }}»</div>
      @else
      <div class="table-responsive"><table class="table table-hover m-0">
      <thead><tr><th>الوحدة</th><th>النوع</th><th>المكان</th><th>مدير الإدارة</th><th></th></tr></thead>
      <tbody>
      @foreach($g['rows'] as $row)
      @php($u = $row['unit'])
      <tr class="{{ $u->is_active ? '' : 'table-secondary' }}">
        <td style="padding-inline-start:{{ 12 + $row['level']*24 }}px"><span class="{{ $row['level'] ? '' : 'fw-bold' }}">{{ $u->name }}</span> <span class="text-muted small" dir="ltr">{{ $u->code }}</span></td>
        <td class="small">{{ \App\Modules\Governance\Models\OrganizationUnit::TYPE_LABELS[$u->unit_type] ?? $u->unit_type }}</td>
        <td class="small">{{ $u->place?->name ?? '—' }}</td>
        <td class="small">{{ $u->manager?->name ?? $u->manager_name ?? '—' }}</td>
        <td class="text-nowrap">
          <a class="btn btn-sm btn-outline-success" href="{{ route('risk.active.index', ['unit' => $u->code]) }}" title="سجل مخاطر هذه الوحدة وأقسامها"><i class="bi bi-lightning-charge"></i> المخاطر</a>
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.org.edit', $u) }}">تعديل</a>
          <form method="post" action="{{ route('app.org.destroy', $u) }}" class="d-inline" onsubmit="return confirm('حذف «{{ $u->name }}»؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">حذف</button></form>
        </td>
      </tr>
      @endforeach
      </tbody></table></div>
      @endif
    </div>
  </div>
</div>
@endforeach
</div>
@endsection
