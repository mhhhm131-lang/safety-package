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
      @if($incident->incident_field_team_id) حُوّل تلقائياً إلى المعالج المختص. @else سيحيله مركز السلامة إلى المعالج المختص. @endif
      @if($incident->deadline_at) المهلة حتى استلام المعالج: {{ $incident->deadline_at->format('Y/m/d H:i') }}. @endif
    </div>
  @else
    <div class="text-muted small mt-2">الرمز: <span class="code">{{ $code }}</span></div>
  @endif
  <div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="{{ route('incident.landing') }}">بلاغ آخر</a></div>
</div>
@endsection
@if($incident)
@push('scripts')
<script>
/* ٢١-٧: الجهاز يتذكر — رمز التتبع (ipa-my-reports) فلا يُكتب ثانية، وآخر مكان (ipa-last-place) فلا يُختار ثانية. يبقى في المتصفح وحده. */
(function(){
  try{
    localStorage.setItem('ipa-last-place', {{ \Illuminate\Support\Js::from($incident->place?->code) }} || '');
    var code={{ \Illuminate\Support\Js::from($incident->secret_tracking_code) }};
    if(!code) return;
    var list=[]; try{ list=JSON.parse(localStorage.getItem('ipa-my-reports')||'[]'); }catch(e){}
    if(!Array.isArray(list)) list=[];
    list=list.filter(function(r){return r&&r.c!==code;});
    list.unshift({c:code,n:{{ \Illuminate\Support\Js::from($incident->code) }},p:{{ \Illuminate\Support\Js::from($incident->place?->name) }},d:{{ \Illuminate\Support\Js::from($incident->created_at->format('Y/m/d')) }}});
    localStorage.setItem('ipa-my-reports', JSON.stringify(list.slice(0,20)));
  }catch(e){}
})();
</script>
@endpush
@endif
