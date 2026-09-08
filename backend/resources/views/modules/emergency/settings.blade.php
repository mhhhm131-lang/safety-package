@extends('layouts.app')
@section('page_title', 'مهل التصعيد الآلي')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-clock-history"></i> مهل التصعيد الآلي للحالات الطارئة</h1>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.dashboard') }}">مركز الطوارئ</a>
</div>
<div class="alert alert-info py-2 small">بلا قيم افتراضية (كما مهل بلاغ الشاغل): القاعدة لا تعمل حتى تُدخل مهلتها. المؤقت في الخادم كل دقيقة. للاسترشاد فقط: مهل OHSMS كانت ٢ / ٥ / ٥ / ٣٠ دقيقة.</div>
<form method="post" action="{{ route('emergency.settings.update') }}" class="card"><div class="card-body">@csrf
  <table class="table table-sm align-middle">
    <thead><tr><th>القاعدة</th><th style="width:220px">المهلة بالدقائق</th><th>يُصعَّد إلى</th></tr></thead>
    <tbody>
    @php($to = ['emergency.escalation.no_ack_min' => 'المنسق والمناوب', 'emergency.escalation.no_team_min' => 'مسؤول السلامة والقيادة (الشؤون الإدارية والهندسية، المرافق، الأمن والسلامة)', 'emergency.escalation.missing_min' => 'الإدارة العليا ولجنة السلامة', 'emergency.escalation.duration_min' => 'مستوى لكل مهلة حتى الجهات الخارجية'])
    @foreach($rules as $r)
      <tr><td>{{ $r['label'] }}</td><td><input type="number" step="1" min="0" name="minutes[{{ $r['key'] }}]" class="form-control form-control-sm" value="{{ $r['minutes'] }}" placeholder="لم تُقرر"></td><td class="small text-muted">{{ $to[$r['key']] ?? '' }}</td></tr>
    @endforeach
    </tbody>
  </table>
  <div class="small text-muted mb-2">الخطورة «حرج» تُصعَّد فوراً إلى الإدارة العليا (قاعدة OHSMS بلا مهلة).</div>
  <button class="btn btn-g">حفظ</button>
</div></form>
@endsection
