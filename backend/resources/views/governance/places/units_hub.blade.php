@extends('layouts.app')
@section('title', 'وحدات الأماكن')
@section('content')
{{-- المرحلة ١٨-٣ (قرار ٤٧) ثم ١٩-١ (قرار ٤٨): الأماكن التسعة — كل مكان يفتح ملفه (وحداته وأنظمته وبلاغاته وفريقه وخطتاه) --}}
<h1 class="page-h">الأماكن</h1>
<p class="small text-muted mb-3">اضغط المكان لملفه: وحداته، أنظمته وآخر فحص، بلاغاته المفتوحة، فريقه الأولي بهواتفه، وخطتاه.</p>
<div class="row g-2" id="unitsHub">
  @foreach($places as $p)
    <div class="col-md-6 col-xl-4">
      <a class="card h-100 text-decoration-none" href="{{ route('app.places.units.file', $p) }}" data-place="{{ $p->code }}" title="{{ $counts[$p->id] ?? 0 }} وحدة">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div><div class="fw-bold">{{ $p->name }}</div><div class="small text-muted" dir="ltr">{{ $p->code }}</div></div>
          <span class="badge text-bg-dark fs-6">{{ $counts[$p->id] ?? 0 }}</span>
        </div>
      </a>
    </div>
  @endforeach
</div>
@endsection
