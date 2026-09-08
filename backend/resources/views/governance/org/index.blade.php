@extends('layouts.app')
@section('title', 'الهيكل التنظيمي')
@section('content')
<div class="d-flex align-items-center mb-3">
  <h1 class="h4 m-0">الهيكل التنظيمي <span class="text-muted fs-6">{{ $count }} وحدة</span></h1>
  <a class="btn btn-g btn-sm ms-auto" href="{{ route('app.org.create') }}"><i class="bi bi-plus"></i> إدارة أو قسم</a>
</div>
<div class="small text-muted mb-2">التعديل هنا يظهر فوراً في لوحة العمل اليومي، والعكس. لا تُحذف وحدة لها أقسام أو موظفون.</div>
<div class="card"><div class="table-responsive"><table class="table table-hover m-0">
<thead><tr><th>الوحدة</th><th>النوع</th><th>المكان</th><th>مدير الإدارة</th><th></th></tr></thead>
<tbody>
@foreach($tree as $row)
@php($u = $row['unit'])
<tr class="{{ $u->is_active ? '' : 'table-secondary' }}">
  <td style="padding-inline-start:{{ 12 + $row['level']*24 }}px">{{ $row['level'] ? '' : '' }}<span class="{{ $row['level'] ? '' : 'fw-bold' }}">{{ $u->name }}</span> <span class="text-muted small" dir="ltr">{{ $u->code }}</span></td>
  <td class="small">{{ ['company'=>'المدير العام','branch'=>'نائب','department'=>'إدارة','section'=>'قسم','team'=>'فريق','region'=>'منطقة'][$u->unit_type] ?? $u->unit_type }}</td>
  <td class="small">{{ $u->place?->name ?? '—' }}</td>
  <td class="small">{{ $u->manager?->name ?? $u->manager_name ?? '—' }}</td>
  <td class="text-nowrap">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.org.edit', $u) }}">تعديل</a>
    <form method="post" action="{{ route('app.org.destroy', $u) }}" class="d-inline" onsubmit="return confirm('حذف «{{ $u->name }}»؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">حذف</button></form>
  </td>
</tr>
@endforeach
</tbody></table></div></div>
@endsection
