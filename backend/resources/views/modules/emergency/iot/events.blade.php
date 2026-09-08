@extends('layouts.app')
@section('page_title', 'سجل إشارات الأجهزة')
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-activity"></i> سجل إشارات الأجهزة</h1>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.iot.dashboard') }}">أنظمة المبنى</a>
</div>
<div class="card"><div class="card-body p-0">
  <table class="table table-sm align-middle mb-0" id="events-table">
    <thead><tr><th>الوقت</th><th>الجهاز</th><th>النظام</th><th>الحدث</th><th>المصدر</th><th>التوقيع</th><th>ما فُعل</th><th>الحالة الطارئة</th><th>ملاحظة</th></tr></thead>
    <tbody>
    @forelse($events as $e)
      <tr data-event="{{ $e->id }}">
        <td class="small">{{ $e->received_at?->format('Y-m-d H:i:s') }}</td>
        <td>{{ $e->device?->name ?? '—' }}</td>
        <td class="small">{{ \App\Modules\Integration\Models\IotDevice::KINDS[$e->kind] ?? $e->kind }}</td>
        <td><code>{{ $e->event_type }}</code> <span class="small text-muted">{{ isset($e->payload['zone_id']) ? 'منطقة '.$e->payload['zone_id'] : '' }}</span></td>
        <td class="small">{{ $e->source }}</td>
        <td>@if($e->signature_valid)<span class="text-success">✓</span>@else<span class="text-danger">✗</span>@endif</td>
        <td>{!! $e->getActionBadge() !!}</td>
        <td>@if($e->incident)<a href="{{ route('emergency.incidents.live', $e->incident) }}">{{ $e->incident->incident_code }}</a>@else —@endif</td>
        <td class="small text-muted">{{ $e->note }}</td>
      </tr>
    @empty
      <tr><td colspan="9" class="text-center text-muted py-4">لا إشارات</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
<div class="mt-2">{{ $events->links() }}</div>
@endsection
