@extends('layouts.app')

@section('title', 'سجل تنبيهات الأجهزة')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('emergency.iot.wearables.dashboard') }}" class="btn btn-outline-secondary me-3">
            <i class="bi bi-arrow-right"></i>
        </a>
        <h1 class="h3 mb-0">
            <i class="bi bi-bell-fill me-2"></i>سجل تنبيهات الأجهزة
        </h1>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <select class="form-select form-select-sm" id="filterType">
                        <option value="">كل الأنواع</option>
                        <option value="panic">زر الطوارئ</option>
                        <option value="fall">كشف السقوط</option>
                        <option value="heart_rate">نبض القلب</option>
                        <option value="low_battery">بطارية منخفضة</option>
                        <option value="sos">SOS</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <select class="form-select form-select-sm" id="filterStatus">
                        <option value="">كل الحالات</option>
                        <option value="triggered">جديد</option>
                        <option value="acknowledged">تم الاستلام</option>
                        <option value="resolved">مغلق</option>
                        <option value="false_alarm">إنذار كاذب</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>المستخدم</th>
                            <th>نوع التنبيه</th>
                            <th>التاريخ والوقت</th>
                            <th>الموقع</th>
                            <th>النبض</th>
                            <th>الحالة</th>
                            <th>استلمه</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($alerts as $alert)
                            <tr class="alert-row"
                                data-type="{{ $alert->alert_type }}"
                                data-status="{{ $alert->status }}">
                                <td>{{ $alert->id }}</td>
                                <td>
                                    <strong>{{ $alert->wearable->user?->name ?? 'غير معروف' }}</strong>
                                    <small class="text-muted d-block">{{ $alert->wearable->device_id }}</small>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $alert->alert_type === 'panic' || $alert->alert_type === 'sos' ? 'danger' : ($alert->alert_type === 'fall' ? 'warning' : ($alert->alert_type === 'heart_rate' ? 'info' : 'secondary')) }}">
                                        @switch($alert->alert_type)
                                            @case('panic') <i class="bi bi-exclamation-diamond me-1"></i>زر الطوارئ @break
                                            @case('fall') <i class="bi bi-person-falling me-1"></i>كشف سقوط @break
                                            @case('heart_rate') <i class="bi bi-heart-pulse me-1"></i>نبض القلب @break
                                            @case('low_battery') <i class="bi bi-battery-low me-1"></i>بطارية منخفضة @break
                                            @case('sos') <i class="bi bi-sos me-1"></i>SOS @break
                                        @endswitch
                                    </span>
                                </td>
                                <td>
                                    <small>{{ $alert->created_at->format('Y/m/d H:i:s') }}</small>
                                    <small class="text-muted d-block">{{ $alert->created_at->diffForHumans() }}</small>
                                </td>
                                <td>
                                    @if($alert->latitude)
                                        <a href="https://maps.google.com/?q={{ $alert->latitude }},{{ $alert->longitude }}"
                                           target="_blank" class="text-decoration-none">
                                            <i class="bi bi-geo-alt me-1"></i>عرض
                                        </a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if($alert->heart_rate)
                                        <span class="{{ $alert->heart_rate > 100 || $alert->heart_rate < 50 ? 'text-danger fw-bold' : '' }}">
                                            {{ $alert->heart_rate }}
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $alert->status === 'triggered' ? 'danger' : ($alert->status === 'acknowledged' ? 'warning' : ($alert->status === 'resolved' ? 'success' : 'secondary')) }}">
                                        @switch($alert->status)
                                            @case('triggered') جديد @break
                                            @case('acknowledged') تم الاستلام @break
                                            @case('resolved') مغلق @break
                                            @case('false_alarm') إنذار كاذب @break
                                        @endswitch
                                    </span>
                                </td>
                                <td>
                                    @if($alert->acknowledgedBy)
                                        <small>
                                            {{ $alert->acknowledgedBy->name }}
                                            <span class="text-muted d-block">{{ $alert->acknowledged_at?->format('H:i') }}</span>
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    لا توجد تنبيهات
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($alerts->hasPages())
            <div class="card-footer">
                {{ $alerts->links() }}
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
function filterAlerts() {
    const type = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;

    document.querySelectorAll('.alert-row').forEach(row => {
        const matchType = !type || row.dataset.type === type;
        const matchStatus = !status || row.dataset.status === status;
        row.style.display = (matchType && matchStatus) ? '' : 'none';
    });
}

document.getElementById('filterType').addEventListener('change', filterAlerts);
document.getElementById('filterStatus').addEventListener('change', filterAlerts);
</script>
@endpush
@endsection
