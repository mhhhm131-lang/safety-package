@extends('layouts.app')
@section('title', 'نماذج الفحص')
@section('content')
{{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: النماذج العشرة بضغطة — من «أريد أن…» --}}
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-clipboard-check"></i> نماذج الفحص</h1>
  <span class="small text-muted">٩ أماكن، ٧٨ نظاماً: ٦٦ في تسعة نماذج + ١٢ نظام حريق مركزي. الجولات والبلاغات تُسجَّل داخل النموذج.</span>
</div>
<div class="row g-3">
  @foreach($rows as $r)
    <div class="col-md-6 col-xl-4">
      <div class="card h-100" data-form="{{ $r['key'] }}">
        <div class="card-body d-flex flex-column">
          <div class="d-flex align-items-center gap-2"><span class="badge text-bg-secondary">{{ $r['hz'] }}</span><strong>{{ $r['name'] }}</strong><span class="small text-muted ms-auto">{{ $r['label'] }}</span></div>
          <div class="small text-muted mt-2 flex-grow-1">
            @if(!$r['exists']) لم يُفتح بعد على هذا الخادم.
            @else آخر جولة: <b>{{ $r['last'] ?? '—' }}</b> · الجولات {{ $r['rounds'] }} · البلاغات {{ $r['reports'] }} (مفتوحة <b class="{{ $r['open'] ? 'text-danger' : '' }}">{{ $r['open'] }}</b>)
            @endif
          </div>
          <a class="btn btn-g mt-2" href="/{{ $r['file'] }}"><i class="bi bi-box-arrow-up-left"></i> افتح النموذج</a>
        </div>
      </div>
    </div>
  @endforeach
</div>
@endsection
