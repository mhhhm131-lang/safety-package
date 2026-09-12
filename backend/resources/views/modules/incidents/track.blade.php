@extends('layouts.public')
@section('page_title', 'تتبع بلاغ')
@section('content')
<div class="card p-4">
  <div class="card-h mb-1"><i class="bi bi-search me-1"></i> تتبع بلاغ برمزه</div>
  <div class="text-muted small mb-3">الرمز الذي أُعطيته عند الإرسال. يظهر الخط الزمني نفسه الذي يراه مركز السلامة، بلا أي هوية.</div>
  <form method="post" action="{{ route('incident.track.post') }}" class="d-flex gap-2">
    @csrf
    <input name="tracking_code" class="form-control form-control-lg text-center @error('tracking_code') is-invalid @enderror" dir="ltr" placeholder="رمز التتبع" required value="{{ old('tracking_code', $tracking_code) }}" maxlength="20" style="font-family:monospace;letter-spacing:2px">
    <button class="btn btn-g px-4">بحث</button>
  </form>
  @error('tracking_code')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
</div>

@if($incident)
<div class="card p-4 mt-3">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-bold">{{ $incident->code }}</span>
    <span class="badge text-bg-{{ \App\Modules\Incident\Models\Incident::STATUS_COLORS[$incident->status] ?? 'secondary' }} badge-st">{{ $incident->status_label }}</span>
    <span class="badge text-bg-light border">{{ $incident->type_label }}</span>
    @if($incident->isOverdue())<span class="badge text-bg-danger">متجاوز المهلة</span>@endif
    <span class="small text-muted ms-auto">{{ $incident->created_at->format('Y/m/d H:i') }}</span>
  </div>
  <div class="mt-2"><b>{{ $incident->title }}</b><div class="small text-muted">{{ $incident->place?->name ?? '' }}{{ $incident->location_text ? ' — '.$incident->location_text : '' }}</div></div>
  @if($incident->resolution_summary)<div class="mt-2 p-2 rounded small" style="background:#eef5f1"><b>ما تم:</b> {{ $incident->resolution_summary }}</div>@endif
  @if($incident->pending_closure && !$incident->reporter_approved_closure && $incident->actor_id === null)
    {{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: العادي لا يُغلق إلا بموافقة المبلّغ — وهنا يوافق برمزه --}}
    <div class="mt-3 p-3 rounded border" style="background:#fffbea" id="closureApproval">
      <div class="fw-bold mb-2">عولج بلاغك. هل عولج فعلاً؟ لا يُغلق إلا بموافقتك.</div>
      <div class="d-flex gap-2 flex-wrap align-items-start">
        <form method="post" action="{{ route('incident.track.approve') }}">@csrf<input type="hidden" name="tracking_code" value="{{ $incident->secret_tracking_code }}"><button class="btn btn-g"><i class="bi bi-hand-thumbs-up"></i> نعم، عولج</button></form>
        <form method="post" action="{{ route('incident.track.reject') }}" class="d-flex gap-2 flex-wrap">@csrf<input type="hidden" name="tracking_code" value="{{ $incident->secret_tracking_code }}">
          <input name="note" class="form-control form-control-sm" placeholder="لم يُعالج لأن…" minlength="5" required style="max-width:260px"><button class="btn btn-outline-danger btn-sm">لا، أعِده</button></form>
      </div>
    </div>
  @endif
  <hr>
  <div class="fw-bold mb-2">الخط الزمني</div>
  <div class="tl">
    @foreach($incident->events as $ev)
      <div class="ev"><b>{{ $ev->action_label }}</b> <small>· {{ $ev->created_at->format('Y/m/d H:i') }}</small>
        @if($ev->note && $ev->action !== 'create')<div class="small text-muted">{{ $ev->note }}</div>@endif</div>
    @endforeach
  </div>
</div>
@endif
@endsection
