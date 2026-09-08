@extends('layouts.app')
@section('page_title', $device->exists ? 'تعديل جهاز' : 'تسجيل جهاز')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-hdd-network"></i> {{ $device->exists ? 'تعديل: '.$device->name : 'تسجيل جهاز موصول' }}</h1>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.iot.devices.index') }}">الأجهزة</a>
</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ $device->exists ? route('emergency.iot.devices.update', $device) : route('emergency.iot.devices.store') }}" class="card"><div class="card-body">
  @csrf @if($device->exists) @method('PUT') @endif
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">النظام</label>
      <select name="kind" class="form-select" required>
        @foreach(\App\Modules\Integration\Models\IotDevice::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $device->kind) === $k)>{{ $l }}</option>@endforeach
      </select></div>
    <div class="col-md-4"><label class="form-label">الاسم</label><input name="name" class="form-control" required maxlength="120" value="{{ old('name', $device->name) }}" placeholder="مثال: لوحة إنذار المبنى الرئيسي"></div>
    <div class="col-md-4"><label class="form-label">البروتوكول</label>
      <select name="protocol" class="form-select" id="protocol" required>
        @foreach(\App\Modules\Integration\Models\IotDevice::PROTOCOLS as $k => $l)<option value="{{ $k }}" @selected(old('protocol', $device->protocol) === $k)>{{ $l }}</option>@endforeach
      </select>
      <div class="form-text">Webhook فقط: الجهاز يرسل ولا نتصل به. REST/BACnet/Modbus: نقرأ حالته أيضاً. MQTT: يحتاج مستمع الخادم.</div></div>

    <div class="col-md-4"><label class="form-label">المبنى</label>
      <select name="building_id" class="form-select"><option value="">—</option>
        @foreach($buildings as $b)<option value="{{ $b->id }}" @selected(old('building_id', $device->building_id) == $b->id)>{{ $b->name }}</option>@endforeach
      </select></div>
    <div class="col-md-4"><label class="form-label">المكان الافتراضي (المعهد)</label>
      <select name="place_id" class="form-select"><option value="">—</option>
        @foreach($places as $p)<option value="{{ $p->id }}" @selected(old('place_id', $device->place_id) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach
      </select>
      <div class="form-text">يُستخدم إن لم تُطابق المنطقة في خريطة المناطق أدناه.</div></div>
    <div class="col-md-4"><label class="form-label">عدد مناطق الحريق في المبنى</label>
      <input type="number" name="fire_zones" class="form-control" min="0" max="999" value="{{ old('fire_zones', $device->building?->fire_zones) }}" placeholder="غير معروف بعد">
      <div class="form-text">عمود fire_zones (كان ناقصاً في OHSMS). يُحفظ على المبنى المختار.</div></div>

    <div class="col-md-2"><label class="form-label">المخطط</label><select name="scheme" class="form-select"><option value="http" @selected(old('scheme', $device->scheme) === 'http')>http</option><option value="https" @selected(old('scheme', $device->scheme) === 'https')>https</option></select></div>
    <div class="col-md-4"><label class="form-label">العنوان (IP/اسم)</label><input name="host" class="form-control" dir="ltr" value="{{ old('host', $device->host) }}" placeholder="192.168.10.5"></div>
    <div class="col-md-2"><label class="form-label">المنفذ</label><input type="number" name="port" class="form-control" dir="ltr" value="{{ old('port', $device->port) }}" placeholder="47808 / 502 / 1883"></div>
    <div class="col-md-2"><label class="form-label">المسار الأساسي</label><input name="base_path" class="form-control" dir="ltr" value="{{ old('base_path', $device->base_path) }}" placeholder="/api/v1"></div>
    <div class="col-md-2"><label class="form-label">Unit/Device ID</label><input type="number" name="unit_id" class="form-control" dir="ltr" value="{{ old('unit_id', $device->unit_id) }}"></div>

    <div class="col-md-3"><label class="form-label">اسم المستخدم</label><input name="username" class="form-control" dir="ltr" value="{{ old('username', $device->username) }}" autocomplete="off"></div>
    <div class="col-md-3"><label class="form-label">كلمة المرور {{ $device->exists ? '(اتركها فارغة للإبقاء)' : '' }}</label><input type="password" name="password" class="form-control" dir="ltr" autocomplete="new-password"></div>
    <div class="col-md-6"><label class="form-label">مفعّل</label>
      <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="en" @checked(old('is_enabled', $device->exists ? $device->is_enabled : true))><label class="form-check-label" for="en">يستقبل الإشارات ويُستعمل لحالة النظام</label></div></div>

    <div class="col-12"><label class="form-label">الإعدادات (JSON)</label>
      <textarea name="config" class="form-control font-monospace" dir="ltr" rows="6" placeholder='{"zones": {"1": "HZ-01", "2": "HZ-06"}, "bacnet": {"panel": {"type": 3, "instance": 1}, "zones": {"1": {"type": 3, "instance": 101}}}, "modbus": {"panel": {"type": "holding", "address": 0}, "zones": {"1": {"type": "coil", "address": 10}}}, "mqtt": {"topics": ["fire/+/alarm"]}, "recall_floor": 1}'>{{ old('config', $device->config ? json_encode($device->config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '') }}</textarea>
      <div class="form-text">zones: رقم المنطقة في الجهاز ← رمز المكان (HZ-00…HZ-08). bacnet/modbus: عناوين قراءة الحالة. mqtt.topics: مواضيع الاشتراك. recall_floor: طابق استدعاء المصاعد.</div></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-g">{{ $device->exists ? 'حفظ' : 'تسجيل' }}</button>
    <a class="btn btn-outline-secondary" href="{{ $device->exists ? route('emergency.iot.devices.show', $device) : route('emergency.iot.devices.index') }}">إلغاء</a>
  </div>
</div></form>
@if($device->exists)
<form method="post" action="{{ route('emergency.iot.devices.destroy', $device) }}" class="mt-3" onsubmit="return confirm('حذف الجهاز وسجل إشاراته؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">حذف الجهاز</button></form>
@endif
@endsection
