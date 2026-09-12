@extends('layouts.app')
@section('title', 'الإعدادات')
@section('content')
{{-- المرحلة ١١-٤ (قرار ٣٤): الباب الثالث — ما يُضبط مرة --}}
<div class="d-flex align-items-center gap-2 mb-1"><h1 class="h4 m-0"><i class="bi bi-sliders"></i> الإعدادات</h1></div>
<p class="small text-muted mb-3">تُضبط مرة عند التشغيل ثم تُنسى. العمل اليومي كله في «ما ينتظرك».</p>
<div class="row g-3">
  @foreach($groups as $g)
    <div class="col-md-6 col-xl-4">
      <div class="card h-100" data-settings-group="{{ $g['title'] }}">
        <div class="card-header"><strong>{{ $g['title'] }}</strong></div>
        <div class="list-group list-group-flush">
          @foreach($g['items'] as [$label, $desc, $url])
            <a class="list-group-item list-group-item-action" href="{{ $url }}"><div class="fw-bold">{{ $label }}</div><div class="small text-muted">{{ $desc }}</div></a>
          @endforeach
        </div>
      </div>
    </div>
  @endforeach
</div>
@endsection
