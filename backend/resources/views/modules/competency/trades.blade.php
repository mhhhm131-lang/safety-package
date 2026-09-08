@extends('layouts.app')
@section('page_title', 'المهن')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-tools me-2"></i>المهن</h1>
  <a href="{{ route('competency.matrix') }}" class="btn btn-sm btn-outline-secondary ms-auto">مصفوفة الكفاءات</a>
</div>
<div class="alert alert-info py-2 small">بذرة OHSMS ٩ صفوف (بنّاء، لحّام) بتصنيف مهني بخمس طبقات. مهن مقاولي المعهد تُضاف هنا عند الحاجة (§٨ س٩) — لا قائمة مخترعة.</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
@if(auth()->user()->can_('competency.manage'))
<form method="POST" action="{{ route('competency.trades.store') }}" class="card mb-3"><div class="card-body row g-2 align-items-end">
  @csrf
  <div class="col-md-2"><label class="form-label small">الرمز</label><input name="code" class="form-control form-control-sm" dir="ltr" required maxlength="20" value="{{ old('code') }}"></div>
  <div class="col-md-3"><label class="form-label small">الاسم</label><input name="name" class="form-control form-control-sm" required maxlength="200" value="{{ old('name') }}"></div>
  <div class="col-md-2"><label class="form-label small">بالإنجليزية</label><input name="name_en" class="form-control form-control-sm" dir="ltr" value="{{ old('name_en') }}"></div>
  <div class="col-md-2"><label class="form-label small">الطبقة</label><select name="level" class="form-select form-select-sm">@foreach($levels as $k => $v)<option value="{{ $k }}" @selected(old('level', 'occupation') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="col-md-2"><label class="form-label small">الأصل</label><select name="parent_id" class="form-select form-select-sm"><option value="">—</option>@foreach($parents as $p)<option value="{{ $p->id }}" @selected(old('parent_id') == $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
  <div class="col-md-1"><button class="btn btn-sm btn-g w-100">إضافة</button></div>
</div></form>
@endif
<div class="card"><div class="card-body p-0">
  <table class="table table-sm mb-0" id="trades-table">
    <thead><tr><th>الرمز</th><th>المهنة</th><th>الطبقة</th><th>الأصل</th><th>العمال</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    @forelse($trades as $trade)
      <tr data-trade="{{ $trade->code }}">
        <td dir="ltr" class="text-start">{{ $trade->code }}</td><td>{{ $trade->name }} <span class="text-muted small">{{ $trade->name_en }}</span></td><td>{{ $levels[$trade->level] ?? $trade->level }}</td>
        <td>{{ $trade->parent?->name ?? '—' }}</td><td>{{ $trade->workers_count }}</td>
        <td>@if($trade->is_active)<span class="badge bg-success">نشطة</span>@else<span class="badge bg-secondary">موقوفة</span>@endif</td>
        <td>@if(auth()->user()->can_('competency.manage'))<form method="POST" action="{{ route('competency.trades.toggle', $trade) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $trade->is_active ? 'إيقاف' : 'تفعيل' }}</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="text-center text-muted py-4">لا مهن</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
<div class="mt-2">{{ $trades->links() }}</div>
@endsection
