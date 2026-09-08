@extends('layouts.public')
@section('page_title', 'وصل بلاغك')
@section('content')
<div class="card p-4 text-center">
  <i class="bi bi-check-circle-fill" style="font-size:3rem;color:var(--g)"></i>
  <div class="card-h mt-2">وصل بلاغك مركز السلامة</div>
  @if($incident)
    <div class="text-muted small mt-1">{{ $incident->code }} · {{ $incident->created_at->format('Y/m/d H:i') }} · {{ $incident->place?->name ?? 'بلا مكان' }}{{ $incident->location_text ? ' — '.$incident->location_text : '' }}</div>
    <div class="mt-3 p-3 rounded" style="background:#eef5f1">
      @if($incident->secret_tracking_code)
        <div class="small text-muted">رمز التتبع — احفظه، به تتابع بلاغك وخطه الزمني</div>
        <div class="code my-1" id="code">{{ $incident->secret_tracking_code }}</div>
        <div class="d-flex gap-2 justify-content-center flex-wrap mt-2">
          <button class="btn btn-sm btn-outline-success" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('code').textContent).then(()=>this.textContent='نُسخ ✓')">نسخ الرمز</button>
          <a class="btn btn-sm btn-g" href="{{ route('incident.track', ['code' => $incident->secret_tracking_code]) }}">تتبع الآن</a>
        </div>
      @else
        <div class="small">أُرسل باسمك. تابعه من <a href="{{ route('incidents.show', $id ?? $incident->id) }}">صفحة البلاغ</a>، وستُطلب موافقتك قبل إغلاقه.</div>
      @endif
    </div>
    <div class="small text-muted mt-3">
      @if($incident->incident_field_team_id) حُوّل تلقائياً إلى فني المكان. @else سيحيله مركز السلامة إلى فني المكان. @endif
      @if($incident->deadline_at) المهلة حتى وصول الفني: {{ $incident->deadline_at->format('Y/m/d H:i') }}. @endif
    </div>
  @else
    <div class="text-muted small mt-2">الرمز: <span class="code">{{ $code }}</span></div>
  @endif
  <div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="{{ route('incident.landing') }}">بلاغ آخر</a></div>
</div>
@endsection
