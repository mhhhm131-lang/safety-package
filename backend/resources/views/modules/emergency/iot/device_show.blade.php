@extends('layouts.app')
@section('page_title', $device->name)
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-hdd-network"></i> {{ $device->name }} <small class="text-muted fs-6">{{ $device->getKindLabel() }} — {{ \App\Modules\Integration\Models\IotDevice::PROTOCOLS[$device->protocol] ?? $device->protocol }}</small></h1>
  @if($device->is_enabled)<span class="badge bg-success">مفعّل</span>@else<span class="badge bg-secondary">موقوف</span>@endif
  <div class="ms-auto d-flex gap-2">
    <form method="post" action="{{ route('emergency.iot.devices.test', $device) }}">@csrf<button class="btn btn-sm btn-outline-primary">اختبار الاتصال</button></form>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.iot.devices.edit', $device) }}">تعديل</a>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.iot.devices.index') }}">الأجهزة</a>
  </div>
</div>

@if(session('show_secret'))
<div class="alert alert-warning">
  <div class="fw-bold mb-1"><i class="bi bi-key"></i> مفتاح التوقيع — يظهر الآن مرة واحدة، انسخه إلى الجهاز:</div>
  <code dir="ltr" id="secret" class="user-select-all">{{ session('show_secret') }}</code>
</div>
@endif

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6">البيانات</h2>
      <table class="table table-sm mb-0">
        <tr><th style="width:160px">المبنى</th><td>{{ $device->building?->name ?? '—' }} @if($device->building?->fire_zones !== null)<span class="text-muted small">({{ $device->building->fire_zones }} منطقة حريق)</span>@endif</td></tr>
        <tr><th>المكان الافتراضي</th><td>{{ $device->place ? $device->place->code.' — '.$device->place->name : '—' }}</td></tr>
        <tr><th>العنوان</th><td dir="ltr" class="text-start">{{ $device->host ? $device->scheme.'://'.$device->host.($device->port ? ':'.$device->port : '').($device->base_path ?? '') : '—' }}</td></tr>
        <tr><th>Unit/Device ID</th><td>{{ $device->unit_id ?? '—' }}</td></tr>
        <tr><th>آخر إشارة</th><td>{{ $device->last_seen_at?->format('Y-m-d H:i:s') ?? '—' }}</td></tr>
        <tr><th>آخر حالة</th><td><code class="small">{{ $device->last_status ? json_encode($device->last_status, JSON_UNESCAPED_UNICODE) : '—' }}</code></td></tr>
        <tr><th>خريطة المناطق</th><td>
          @forelse(($device->config['zones'] ?? []) as $z => $code)<span class="badge bg-light text-dark border me-1">{{ $z }} ← {{ $code }}</span>@empty <span class="text-muted">— (يُستخدم المكان الافتراضي)</span>@endforelse
        </td></tr>
      </table>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6"><i class="bi bi-link-45deg"></i> Webhook هذا الجهاز</h2>
      <div class="small text-muted mb-1">العنوان:</div>
      <code dir="ltr" class="d-block mb-2 user-select-all">POST {{ $webhookUrl }}</code>
      <div class="small text-muted mb-1">الرؤوس:</div>
      <pre class="small bg-light p-2 rounded mb-2" dir="ltr">Content-Type: application/json
X-IPA-Signature: sha256=&lt;hex(HMAC_SHA256(body, secret))&gt;
X-IPA-Timestamp: &lt;unix seconds&gt;   (اختياري — يُرفض ما يزيد على ٥ دقائق)</pre>
      <div class="small text-muted mb-1">مثال الجسم:</div>
      <pre class="small bg-light p-2 rounded mb-2" dir="ltr">{{ $sample }}</pre>
      <div class="small text-muted">الأحداث التي تفتح حالة طارئة: لوحة الحريق <code>alarm</code>/<code>fire</code>؛ التكييف <code>smoke_detected</code>/<code>gas_detected</code>/<code>co_detected</code>/<code>high_temp</code>. وتُنبّه المركز: <code>trouble</code>, <code>supervisory</code>, <code>restore</code>, <code>forced_door</code>, <code>door_held</code>, <code>tamper</code>, <code>entrapment</code>, <code>fault</code>.</div>
      <form method="post" action="{{ route('emergency.iot.devices.rotate', $device) }}" class="mt-3" onsubmit="return confirm('توليد مفتاح جديد يُبطل المفتاح الحالي في الجهاز. متابعة؟')">@csrf<button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat"></i> توليد مفتاح توقيع جديد</button></form>
    </div></div>
  </div>
</div>

<div class="card mt-3"><div class="card-body p-0">
  <div class="p-2 border-bottom d-flex align-items-center"><h2 class="h6 m-0"><i class="bi bi-activity"></i> آخر ٥٠ إشارة</h2><a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.iot.events') }}">السجل الكامل</a></div>
  <table class="table table-sm align-middle mb-0" id="device-events">
    <thead><tr><th>الوقت</th><th>الحدث</th><th>المصدر</th><th>IP</th><th>التوقيع</th><th>ما فُعل</th><th>الحالة الطارئة</th><th>ملاحظة</th></tr></thead>
    <tbody>
    @forelse($events as $e)
      <tr data-event="{{ $e->id }}" data-action="{{ $e->action_taken }}">
        <td class="small">{{ $e->received_at?->format('m-d H:i:s') }}</td>
        <td><code>{{ $e->event_type }}</code> <span class="small text-muted">{{ isset($e->payload['zone_id']) ? 'منطقة '.$e->payload['zone_id'] : '' }}</span></td>
        <td class="small">{{ $e->source }}</td>
        <td class="small" dir="ltr">{{ $e->source_ip }}</td>
        <td>@if($e->signature_valid)<span class="text-success">✓</span>@else<span class="text-danger">✗</span>@endif</td>
        <td>{!! $e->getActionBadge() !!}</td>
        <td>@if($e->incident)<a href="{{ route('emergency.incidents.live', $e->incident) }}">{{ $e->incident->incident_code }}</a>@else —@endif</td>
        <td class="small text-muted">{{ $e->note }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="text-center text-muted py-3">لم تصل إشارات بعد</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
@endsection
