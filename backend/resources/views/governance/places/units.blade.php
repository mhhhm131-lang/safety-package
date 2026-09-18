@extends('layouts.app')
@section('title', 'وحدات '.$place->name)
@section('content')
{{-- المرحلة ١٨-٣ (قرار ٤٧): شاشة «وحدات المكان» — يُدخلها صاحب المكان مرة واحدة؛ الحقول قليلة وبحسب النوع --}}
@php($L = \App\Modules\Governance\Models\PlaceUnit::TYPE_LABELS)
@php($F = \App\Modules\Governance\Models\PlaceUnit::FIELDS_BY_TYPE)
@php($FL = \App\Modules\Governance\Models\PlaceUnit::FIELD_LABELS)
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
  <h1 class="page-h m-0">وحدات {{ $place->name }}</h1>
  <span class="small text-muted" dir="ltr">{{ $place->code }}</span>
  <a class="small ms-auto" href="{{ route('app.places.units.file', $place) }}">ملف المكان ←</a>
</div>
<p class="small text-muted mb-3">
  @if($canAny) تُدخل مرة واحدة. ما يُضاف هنا يظهر بعدها في البلاغ والفحص والخطر والفريق.
  @else للقراءة — تعديل الوحدات لمدير المرافق والصيانة، ولمدير الإدارة إدارته، ولمسؤول السلامة. @endif
</p>

{{-- الإدارات (المكاتب): من الهيكل التنظيمي، يُضاف الدور والموقع فقط --}}
@if(in_array('department', $types, true))
  <div class="card mb-3"><div class="card-body">
    <h2 class="sec-h"><i class="bi bi-diagram-3"></i> الإدارات في هذا المكان <span class="badge text-bg-dark">{{ $departments->count() }}</span></h2>
    <div class="small text-muted mb-2">الاسم من الهيكل التنظيمي. كل مدير يُدخل دور إدارته وموقعها.</div>
    @if($departments->isEmpty())<div class="text-muted small">لا إدارات مسجّلة لهذا المكان في الهيكل التنظيمي.</div>@endif
    <div class="d-grid gap-2">
      @foreach($departments as $d)
        <form method="post" action="{{ route('app.places.units.store', $place) }}" class="d-flex flex-wrap align-items-center gap-2 border rounded p-2" data-dept="{{ $d['unit']->code }}">@csrf
          <input type="hidden" name="type" value="department"><input type="hidden" name="organization_unit_id" value="{{ $d['unit']->id }}"><input type="hidden" name="name" value="{{ $d['unit']->name }}">
          <div class="fw-bold flex-grow-1" style="min-width:200px">{{ $d['unit']->name }}</div>
          @if($d['can'])
            <input class="form-control form-control-sm" style="max-width:120px" name="floor" placeholder="الدور" value="{{ $d['row']?->floor }}">
            <input class="form-control form-control-sm" style="max-width:220px" name="location" placeholder="الموقع (الجناح، الجهة…)" value="{{ $d['row']?->location }}">
            <button class="btn btn-sm btn-g">حفظ</button>
          @else
            <span class="small text-muted">{{ $d['row'] ? 'الدور '.$d['row']->floor.($d['row']->location ? ' · '.$d['row']->location : '') : 'لم يُدخل الدور والموقع بعد' }}</span>
          @endif
        </form>
      @endforeach
    </div>
  </div></div>
@endif

{{-- وحدات المبنى --}}
@php($bTypes = array_values(array_filter($types, fn ($t) => $t !== 'department')))
@if($bTypes)
  <div class="card mb-3"><div class="card-body">
    <h2 class="sec-h"><i class="bi bi-grid-3x3-gap"></i> الوحدات <span class="badge text-bg-dark">{{ $units->count() }}</span></h2>
    @if($units->isEmpty())<div class="text-muted small mb-2">لا وحدات بعد.</div>@endif
    {{-- على الجوال يتمرر الجدول أفقياً بدل أن تنضغط الحقول --}}
    <div class="table-responsive"><table class="table table-sm align-middle m-0" id="unitsTable" style="min-width:680px">
      <thead><tr><th>النوع</th><th>الاسم أو الرقم</th><th>الدور</th><th>الموقع</th><th>السعة</th><th>المشغّل</th><th></th></tr></thead>
      <tbody>
      @foreach($units as $u)
        @php($can = \App\Modules\Governance\Models\PlaceUnit::canManage(auth()->user(), $place, $u->type))
        <tr data-unit="{{ $u->id }}">
          <td class="text-nowrap">{{ $L[$u->type] ?? $u->type }}</td>
          @if($can)
            <form method="post" action="{{ route('app.places.units.update', [$place, $u]) }}" id="uf{{ $u->id }}">@csrf @method('PUT')</form>
            <td><input form="uf{{ $u->id }}" class="form-control form-control-sm" name="name" value="{{ $u->name }}" required style="min-width:150px"></td>
            <td><input form="uf{{ $u->id }}" class="form-control form-control-sm" name="floor" value="{{ $u->floor }}" style="min-width:70px"></td>
            <td><input form="uf{{ $u->id }}" class="form-control form-control-sm" name="location" value="{{ $u->location }}" style="min-width:120px"></td>
            <td><input form="uf{{ $u->id }}" class="form-control form-control-sm" name="capacity" type="number" min="1" value="{{ $u->capacity }}" style="width:90px"></td>
            <td><input form="uf{{ $u->id }}" class="form-control form-control-sm" name="operator" value="{{ $u->operator }}" style="min-width:100px"></td>
            <td class="text-nowrap"><button form="uf{{ $u->id }}" class="btn btn-sm btn-outline-secondary">حفظ</button>
              <form method="post" action="{{ route('app.places.units.destroy', [$place, $u]) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="إزالة من القائمة">✕</button></form></td>
          @else
            <td>{{ $u->name }}</td><td>{{ $u->floor }}</td><td>{{ $u->location }}</td><td>{{ $u->capacity }}</td><td>{{ $u->operator }}</td><td></td>
          @endif
        </tr>
      @endforeach
      </tbody>
    </table></div>
  </div></div>

  @if($manageTypes)
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
          <h2 class="sec-h"><i class="bi bi-plus-circle"></i> أضف وحدة</h2>
          <form method="post" action="{{ route('app.places.units.store', $place) }}" id="addUnit">@csrf
            <label class="form-label small">النوع</label>
            <select name="type" id="unitType" class="form-select mb-2">
              @foreach($manageTypes as $t)<option value="{{ $t }}" data-fields="{{ implode(',', $F[$t] ?? []) }}">{{ $L[$t] }}</option>@endforeach
            </select>
            <label class="form-label small">{{ $FL['name'] }} <span class="text-danger">*</span></label>
            <input class="form-control mb-2" name="name" required maxlength="120" placeholder="مثل: قاعة ٣١٢ · غرفة كهرباء ٢ · مطعم الدور الأرضي">
            @foreach(['floor', 'location', 'capacity', 'operator'] as $f)
              <div data-field="{{ $f }}"><label class="form-label small">{{ $FL[$f] }}</label>
                <input class="form-control mb-2" name="{{ $f }}" @if($f === 'capacity') type="number" min="1" @endif maxlength="200"></div>
            @endforeach
            <button class="btn btn-g">أضف</button>
          </form>
        </div></div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
          <h2 class="sec-h"><i class="bi bi-clipboard-plus"></i> ألصق جدولاً</h2>
          <div class="small text-muted mb-2">سطر لكل وحدة: <b>الاسم أو الرقم، الدور، السعة</b>. الفاصل فاصلة أو تبويب (من إكسل مباشرة).</div>
          <form method="post" action="{{ route('app.places.units.paste', $place) }}" id="pasteUnits">@csrf
            <select name="type" class="form-select mb-2">@foreach($manageTypes as $t)<option value="{{ $t }}">{{ $L[$t] }}</option>@endforeach</select>
            <textarea name="rows" class="form-control mb-2" rows="6" dir="auto" placeholder="٣٠١، ٣، ٢٥&#10;٣٠٢، ٣، ٢٥&#10;قاعة الملك عبدالعزيز، الأرضي، ٥٠٠"></textarea>
            <button class="btn btn-o">أدخل الجدول</button>
          </form>
        </div></div>
      </div>
    </div>
  @endif
@endif
@endsection
@push('scripts')
<script>
(function(){
  var s=document.getElementById('unitType'); if(!s) return;
  function show(){ var f=(s.selectedOptions[0].dataset.fields||'').split(',');
    document.querySelectorAll('#addUnit [data-field]').forEach(function(d){ d.hidden = f.indexOf(d.dataset.field) < 0; }); }
  s.addEventListener('change', show); show();
})();
</script>
@endpush
