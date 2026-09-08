@extends('layouts.app')
@section('title', $unit ? 'تعديل وحدة' : 'وحدة جديدة')
@section('content')
<h1 class="h4 mb-3">{{ $unit ? 'تعديل «'.$unit->name.'»' : 'إدارة أو قسم جديد' }}</h1>
<form method="post" action="{{ $unit ? route('app.org.update', $unit) : route('app.org.store') }}" class="card p-3" style="max-width:720px">
  @csrf @if($unit) @method('PUT') @endif
  <div class="row g-3">
    <div class="col-12"><label class="form-label">الاسم</label><input class="form-control" name="name" value="{{ old('name', $unit?->name) }}" required placeholder="مثال: قسم الصيانة الكهربائية"></div>
    <div class="col-md-6"><label class="form-label">تتبع</label>
      <select class="form-select" name="parent_id"><option value="">— المدير العام مباشرة</option>
        @foreach($parents as $p)<option value="{{ $p->id }}" @selected((int)old('parent_id', $unit?->parent_id)===$p->id)>{{ $p->name }}</option>@endforeach
      </select></div>
    <div class="col-md-6"><label class="form-label">النوع</label>
      <select class="form-select" name="unit_type">@foreach($types as $k=>$v)<option value="{{ $k }}" @selected(old('unit_type', $unit?->unit_type ?? 'department')===$k)>{{ $v }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label">المكان الذي تشغله</label>
      <select class="form-select" name="place_id">@foreach($places as $p)<option value="{{ $p->id }}" @selected((int)old('place_id', $unit?->place_id)===$p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label">مدير الإدارة (الاسم)</label><input class="form-control" name="manager_name" value="{{ old('manager_name', $unit?->manager_name) }}" placeholder="يظهر مرشِّحاً للفريق الأولي"></div>
    @if(!$unit)<div class="col-md-6"><label class="form-label">الرمز (اختياري، لاتيني)</label><input class="form-control" dir="ltr" name="code" value="{{ old('code') }}" placeholder="يُولَّد تلقائياً"></div>@endif
    @if($unit)<div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" @checked(old('is_active', $unit->is_active))><label class="form-check-label" for="act">مفعّلة</label></div></div>@endif
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-g">حفظ</button><a class="btn btn-outline-secondary" href="{{ route('app.org.index') }}">إلغاء</a></div>
</form>
@endsection
