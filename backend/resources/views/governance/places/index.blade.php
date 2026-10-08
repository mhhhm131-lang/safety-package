@extends('layouts.app')
@section('title', 'الأماكن')
@section('content')
<h1 class="h4 mb-1">الأماكن (٨+١) في كل مبنى</h1>
<div class="small text-muted mb-3">الأصناف التسعة ثابتة بالمرجعية SOURCE.md؛ الاسم فقط يُعدَّل. لكل مكان خطتا صنفه ونموذجه وملفه. الصنف الذي لا يوجد في مبنى يُعطَّل ولا يُحذف؛ وأماكن الملز ثابتة. أماكن مبنى جديد تُنشأ من <a href="{{ route('emergency.buildings.index') }}">شاشة المباني</a> بضغطة.</div>
@foreach($groups as $buildingId => $places)
@php($b = $places->first()->building)
<div class="card mb-3">
<div class="card-header d-flex align-items-center gap-2 flex-wrap">
  <strong><i class="bi bi-building"></i> {{ $b?->name ?? 'بلا مبنى' }}</strong>
  @if($b?->branchUnit)<span class="badge text-bg-light border">{{ $b->branchUnit->name }}</span>@elseif($b?->branch)<span class="badge text-bg-light border">{{ $b->branch }}</span>@endif
  @if($buildingId === $mainId)<span class="badge text-bg-success">الرئيسي — رموزه ثابتة</span>@endif
  @if($b)<a class="small ms-auto" href="{{ route('emergency.buildings.show', $b) }}">شاشة المبنى</a>@endif
</div>
<div class="table-responsive"><table class="table m-0">
<thead><tr><th>الصنف</th><th>الرمز</th><th>الاسم</th><th>الوثائق والنماذج</th><th>الوحدات التي تشغله</th><th>الحالة</th><th></th></tr></thead>
<tbody>
@foreach($places as $p)
<tr class="{{ $p->is_active ? '' : 'table-secondary text-muted' }}">
  <td dir="ltr" class="text-end">{{ $p->category }}</td>
  <td dir="ltr" class="text-end fw-bold">{{ $p->code }}</td>
  <td style="min-width:220px">
    <form method="post" action="{{ route('app.places.update', $p) }}" id="name-{{ $p->id }}">@csrf @method('PUT')
      <input class="form-control form-control-sm" name="name" value="{{ $p->name }}">
    </form>
  </td>
  <td class="small">
    @foreach($p->links() as [$label, $url])
      <a class="badge text-bg-light border text-decoration-none me-1 mb-1" href="{{ $url }}">{{ $label }}</a>
    @endforeach
  </td>
  <td>{{ $p->units_count }}</td>
  <td>
    @if($p->is_active)<span class="badge text-bg-success">فعّال</span>@else<span class="badge text-bg-secondary">معطَّل — لا يوجد في المبنى</span>@endif
  </td>
  <td class="text-nowrap">
    <button class="btn btn-sm btn-outline-secondary" form="name-{{ $p->id }}">حفظ</button>
    @if($buildingId !== $mainId)
      <form method="post" action="{{ route('app.places.active', $p) }}" class="d-inline">@csrf @method('PUT')
        <button class="btn btn-sm {{ $p->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">{{ $p->is_active ? 'تعطيل' : 'إعادة' }}</button>
      </form>
    @endif
  </td>
</tr>
@endforeach
</tbody></table></div></div>
@endforeach
@endsection
