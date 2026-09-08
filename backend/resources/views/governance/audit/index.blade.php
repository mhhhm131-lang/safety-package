@extends('layouts.app')
@section('title', 'سجل التدقيق')
@section('content')
<h1 class="h4 mb-3">سجل التدقيق <span class="text-muted fs-6">{{ $logs->total() }}</span></h1>
<form class="row g-2 mb-3">
  <div class="col-md-3"><select class="form-select" name="model"><option value="">كل الكيانات</option>@foreach($models as $m)<option @selected(request('model')===$m)>{{ $m }}</option>@endforeach</select></div>
  <div class="col-md-3"><select class="form-select" name="action"><option value="">كل الأفعال</option>@foreach($actions as $a)<option @selected(request('action')===$a)>{{ $a }}</option>@endforeach</select></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100">تصفية</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm m-0">
<thead><tr><th>الوقت</th><th>من</th><th>الفعل</th><th>الكيان</th><th>الوصف</th><th>IP</th></tr></thead>
<tbody>
@forelse($logs as $l)
<tr>
  <td class="text-nowrap small">{{ $l->created_at?->format('Y/m/d H:i') }}</td>
  <td>{{ $l->user?->name ?? 'النظام' }}</td>
  <td><code>{{ $l->action }}</code></td>
  <td>{{ $l->model_name }} @if($l->object_id)#{{ $l->object_id }}@endif</td>
  <td class="small">{{ $l->description }}</td>
  <td class="small" dir="ltr">{{ $l->ip_address }}</td>
</tr>
@empty
<tr><td colspan="6" class="text-center text-muted">لا سجلات</td></tr>
@endforelse
</tbody></table></div></div>
<div class="mt-2">{{ $logs->links() }}</div>
@endsection
