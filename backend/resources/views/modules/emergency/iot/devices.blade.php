@extends('layouts.app')
@section('page_title', 'الأجهزة الموصولة')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-hdd-network"></i> الأجهزة الموصولة</h1>
  <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.iot.dashboard') }}">أنظمة المبنى</a>
  <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.iot.events') }}">سجل الإشارات</a>
  <a class="btn btn-sm btn-g ms-auto" href="{{ route('emergency.iot.devices.create') }}"><i class="bi bi-plus-lg"></i> تسجيل جهاز</a>
</div>
<div class="alert alert-info py-2 small">لكل جهاز مفتاح توقيع خاص: الجهاز (أو وسيطه) يرسل الحدث إلى عنوان Webhook الجهاز موقّعاً بـ HMAC-SHA256، فتُفتح حالة طارئة فوراً في مكان المنطقة. الأجهزة الفعلية تُسجَّل بعد جواب إدارة المرافق (البند ٨ س٤).</div>

<div class="card mb-3"><div class="card-body p-0">
  <table class="table table-sm align-middle mb-0" id="devices-table">
    <thead><tr><th>#</th><th>النظام</th><th>الاسم</th><th>البروتوكول</th><th>العنوان</th><th>المبنى</th><th>المكان</th><th>مفعّل</th><th>الإشارات</th><th>آخر إشارة</th><th></th></tr></thead>
    <tbody>
    @forelse($devices as $d)
      <tr data-device="{{ $d->id }}">
        <td>{{ $d->id }}</td>
        <td>{{ $d->getKindLabel() }}</td>
        <td><a href="{{ route('emergency.iot.devices.show', $d) }}">{{ $d->name }}</a></td>
        <td><code>{{ $d->protocol }}</code></td>
        <td class="small text-muted" dir="ltr">{{ $d->host ? $d->host.($d->port ? ':'.$d->port : '') : '—' }}</td>
        <td>{{ $d->building?->name ?? '—' }}</td>
        <td>{{ $d->place?->code ?? '—' }}</td>
        <td>@if($d->is_enabled)<span class="badge bg-success">نعم</span>@else<span class="badge bg-secondary">لا</span>@endif</td>
        <td>{{ $d->events_count }}</td>
        <td class="small text-muted">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
        <td class="text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.iot.devices.edit', $d) }}">تعديل</a>
          <form method="post" action="{{ route('emergency.iot.devices.test', $d) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">اختبار</button></form>
        </td>
      </tr>
    @empty
      <tr><td colspan="11" class="text-center text-muted py-4">لا أجهزة مسجّلة</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>

<form method="post" action="{{ route('emergency.iot.devices.allowed') }}" class="card"><div class="card-body">@csrf
  <h2 class="h6"><i class="bi bi-shield-lock"></i> العناوين المسموح بها لاختبارات البروتوكولات</h2>
  <div class="small text-muted mb-2">مسارات BACnet/Modbus/MQTT المباشرة لا تفتح اتصالاً إلا إلى عناوين الأجهزة المسجّلة أعلاه أو ما يُدرج هنا (الإصلاح المقرر ٥-٤: كانت في OHSMS تفتح سوكتاً إلى أي عنوان). عناوين مفصولة بفاصلة أو سطر.</div>
  <textarea name="allowed_hosts" class="form-control form-control-sm" rows="2" dir="ltr" placeholder="192.168.10.5, 192.168.10.255">{{ $allowed }}</textarea>
  <button class="btn btn-sm btn-g mt-2">حفظ</button>
</div></form>
@endsection
