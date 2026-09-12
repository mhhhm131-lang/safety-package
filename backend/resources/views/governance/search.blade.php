@extends('layouts.app')
@section('title', 'بحث')
@section('content')
{{-- المرحلة ١١-٤ (قرار ٣٤): الباب الثاني — صندوق واحد لكل السجلات --}}
<form method="get" action="{{ route('app.search') }}" class="mb-3">
  <div class="input-group input-group-lg">
    <input name="q" class="form-control" value="{{ $q }}" placeholder="رمز بلاغ، اسم خطر، تصريح، مقاول، عامل، مكان…" autofocus>
    <button class="btn btn-g"><i class="bi bi-search"></i> ابحث</button>
  </div>
  <div class="form-text">اكتب رمز البلاغ (ش-0012) أو الحالة (ط-0003) أو المكان (HZ-06) ليُفتح مباشرة.</div>
</form>
@if($q !== '')
  @forelse($groups as $g)
    <div class="card mb-3" data-group="{{ $g['title'] }}">
      <div class="card-header"><strong>{{ $g['title'] }}</strong> <span class="badge text-bg-light border">{{ $g['rows']->count() }}</span></div>
      <div class="list-group list-group-flush">
        @foreach($g['rows'] as $r)
          <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ $r['url'] }}">
            <span>{{ $r['label'] }}</span><small class="text-muted">{{ $r['meta'] }}</small>
          </a>
        @endforeach
      </div>
    </div>
  @empty
    <div class="card"><div class="card-body text-center text-muted py-4">لا نتائج لـ «{{ $q }}» فيما تملك الاطلاع عليه.</div></div>
  @endforelse
@endif
@endsection
