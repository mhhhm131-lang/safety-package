@extends('layouts.app')
@section('title', 'المستخدمون')
@section('content')
<div class="d-flex align-items-center mb-3">
  <h1 class="h4 m-0">المستخدمون <span class="text-muted fs-6">{{ $users->total() }}</span></h1>
  <a class="btn btn-g btn-sm ms-auto" href="{{ route('app.users.create') }}"><i class="bi bi-person-plus"></i> حساب جديد</a>
</div>
<form class="row g-2 mb-3">
  <div class="col-md-4"><input class="form-control" name="q" value="{{ request('q') }}" placeholder="بحث بالاسم أو اسم الدخول"></div>
  <div class="col-md-4"><select class="form-select" name="role"><option value="">كل الأدوار</option>@foreach($roles as $k=>$v)<option value="{{ $k }}" @selected(request('role')===$k)>{{ $v }}</option>@endforeach</select></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100">تصفية</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover m-0">
<thead><tr><th>الاسم</th><th>اسم الدخول</th><th>الدور</th><th>الوحدة</th><th>المكان</th><th>الحالة</th><th></th></tr></thead>
<tbody>
@forelse($users as $u)
<tr class="{{ optional($u->profile)->is_active ? '' : 'table-secondary' }}">
  <td>{{ $u->name }}</td>
  <td dir="ltr" class="text-end">{{ $u->username }}</td>
  <td><span class="badge badge-role">{{ $u->roleName() }}</span></td>
  <td class="small">{{ optional(optional($u->profile)->organizationUnit)->name ?? '—' }}</td>
  <td class="small">{{ optional(optional($u->profile)->place)->name ?? '—' }}</td>
  <td>{{ optional($u->profile)->is_active ? 'مفعّل' : 'معطّل' }}</td>
  <td class="text-nowrap">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.users.edit', $u) }}">تعديل</a>
    <form method="post" action="{{ route('app.users.toggle', $u) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">{{ optional($u->profile)->is_active ? 'تعطيل' : 'تفعيل' }}</button></form>
    <form method="post" action="{{ route('app.users.reset', $u) }}" class="d-inline" onsubmit="return confirm('إعادة كلمة مرور {{ $u->username }}؟')">@csrf<button class="btn btn-sm btn-outline-secondary">كلمة مرور جديدة</button></form>
  </td>
</tr>
@empty
<tr><td colspan="7" class="text-muted text-center">لا حسابات</td></tr>
@endforelse
</tbody></table></div></div>
<div class="mt-2">{{ $users->links() }}</div>
@endsection
