@extends('layouts.app')
@section('title', 'وحدات الأماكن')
@section('content')
{{-- المرحلة ١٨-٣ (قرار ٤٧): مدخل وحدات الأماكن — الأماكن التسعة بعدد وحداتها؛ كل مكان يفتح شاشته --}}
<h1 class="page-h">وحدات الأماكن</h1>
<p class="small text-muted mb-3">كل مكان له وحدات: قاعات بأرقامها وسعتها، غرف كهرباء، مستودعات، مطاعم بمشغّليها، وإدارات بأدوارها. تُدخل مرة واحدة، ثم يحملها البلاغ والفحص والخطر.</p>
<div class="row g-2" id="unitsHub">
  @foreach($places as $p)
    <div class="col-md-6 col-xl-4">
      <a class="card h-100 text-decoration-none" href="{{ route('app.places.units.index', $p) }}" data-place="{{ $p->code }}">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div><div class="fw-bold">{{ $p->name }}</div><div class="small text-muted" dir="ltr">{{ $p->code }}</div></div>
          <span class="badge text-bg-dark fs-6">{{ $counts[$p->id] ?? 0 }}</span>
        </div>
      </a>
    </div>
  @endforeach
</div>
@endsection
