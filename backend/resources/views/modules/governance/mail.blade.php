@extends('layouts.app')
@section('page_title', 'البريد')
@section('content')

<div class="mb-3">
  <h1 class="h5 m-0">قناة البريد</h1>
  <div class="small text-muted">القناة الثانية للإشعارات. الأولى صندوق الوارد داخل النظام.</div>
</div>

@if($status['ready'])
  <div class="alert alert-success py-2" data-mail-state="ready">
    <i class="bi bi-check-circle"></i> القناة مضبوطة: المرسل <strong>{{ $status['mailer'] }}</strong>
    @if($status['host'] !== '—') عبر <span class="font-monospace">{{ $status['host'] }}</span> @endif
    من <span class="font-monospace">{{ $status['from'] }}</span>.
    <div class="small mt-1">الضبط لا يعني الوصول. أرسل رسالة اختبار وتأكّد بعينك.</div>
  </div>
@else
  <div class="alert alert-danger py-2" data-mail-state="down">
    <i class="bi bi-exclamation-triangle"></i> <strong>البريد لا يصل أحداً.</strong>
    <div class="small mt-1">{{ $status['reason'] }}</div>
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">الحالة</h2>
    <table class="table table-sm m-0" style="max-width:34rem">
      <tbody>
        <tr><td class="text-muted small">المرسل</td><td class="font-monospace" data-mail="mailer">{{ $status['mailer'] }}</td></tr>
        <tr><td class="text-muted small">الخادم</td><td class="font-monospace" data-mail="host">{{ $status['host'] }}</td></tr>
        <tr><td class="text-muted small">المرسِل</td><td class="font-monospace" data-mail="from">{{ $status['from'] }}</td></tr>
        <tr>
          <td class="text-muted small">آخر إرسال ناجح</td>
          <td data-mail="last_ok">{{ $status['last_ok'] ?? 'لم يُرسل شيء بعد' }}
            @if($status['last_to']) <span class="small text-muted">→ {{ $status['last_to'] }}</span>@endif
          </td>
        </tr>
        @if($status['last_error'])
          <tr><td class="text-muted small">آخر عطل</td><td class="text-danger small" data-mail="last_error">{{ $status['last_error'] }}</td></tr>
        @endif
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">رسالة اختبار</h2>
    <form method="post" action="{{ route('app.mail.test') }}" class="d-flex flex-wrap align-items-end gap-2">
      @csrf
      <div>
        <label class="form-label small mb-0 text-muted">إلى</label>
        <input type="email" name="to" class="form-control form-control-sm" style="min-width:18rem"
               value="{{ old('to', $default) }}" placeholder="name@example.com">
      </div>
      <button class="btn btn-sm btn-g"><i class="bi bi-send"></i> أرسل</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h2 class="h6 mb-2">كيف تُضبط</h2>
    <p class="small text-muted">
      المفاتيح تُدخل في <strong>متغيرات البيئة على Render</strong> ولا تُكتب في المستودع أبداً.
      أي خدمة تعطي SMTP تعمل — تُنشئ حساباً، وتوثّق نطاق المرسِل، وتأخذ منها اسم المستخدم وكلمة المرور.
    </p>
    <div class="table-responsive">
      <table class="table table-sm m-0" style="max-width:44rem">
        <thead><tr><th>المفتاح</th><th>القيمة</th></tr></thead>
        <tbody>
          <tr><td class="font-monospace small">MAIL_MAILER</td><td class="font-monospace small">smtp</td></tr>
          <tr><td class="font-monospace small">MAIL_HOST</td><td class="small">خادم الخدمة</td></tr>
          <tr><td class="font-monospace small">MAIL_PORT</td><td class="font-monospace small">587</td></tr>
          <tr><td class="font-monospace small">MAIL_USERNAME</td><td class="small">من الخدمة</td></tr>
          <tr><td class="font-monospace small">MAIL_PASSWORD</td><td class="small">من الخدمة — سرّ، لا يُكتب في المستودع</td></tr>
          <tr><td class="font-monospace small">MAIL_SCHEME</td><td class="font-monospace small">tls</td></tr>
          <tr><td class="font-monospace small">MAIL_FROM_ADDRESS</td><td class="small">عنوان مرسِل موثَّق لدى الخدمة</td></tr>
          <tr><td class="font-monospace small">MAIL_FROM_NAME</td><td class="small">منظومة السلامة</td></tr>
        </tbody>
      </table>
    </div>
    <p class="small text-muted mt-2 mb-0">بعد إدخالها تُعاد الخدمة، ثم تُرسل رسالة اختبار من هذه الشاشة.</p>
  </div>
</div>

@endsection
