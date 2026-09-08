@extends('layouts.app')

@section('title', 'الأجهزة القابلة للارتداء')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-smartwatch me-2"></i>الأجهزة القابلة للارتداء
        </h1>
        <a href="{{ route('emergency.iot.wearables.alerts') }}" class="btn btn-outline-danger">
            <i class="bi bi-bell me-1"></i>سجل التنبيهات
            @if($stats['active_alerts'] > 0)
                <span class="badge bg-danger ms-1">{{ $stats['active_alerts'] }}</span>
            @endif
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">إجمالي الأجهزة</h6>
                            <h2 class="mb-0">{{ $stats['total_devices'] }}</h2>
                        </div>
                        <i class="bi bi-smartwatch fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">متصلة الآن</h6>
                            <h2 class="mb-0">{{ $stats['online_now'] }}</h2>
                        </div>
                        <i class="bi bi-broadcast fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">بطارية منخفضة</h6>
                            <h2 class="mb-0">{{ $stats['low_battery'] }}</h2>
                        </div>
                        <i class="bi bi-battery-half fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">تنبيهات نشطة</h6>
                            <h2 class="mb-0">{{ $stats['active_alerts'] }}</h2>
                        </div>
                        <i class="bi bi-exclamation-triangle-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Active Alerts -->
        @if($activeAlerts->count() > 0)
            <div class="col-12">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>تنبيهات نشطة تحتاج تدخل
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>المستخدم</th>
                                        <th>نوع التنبيه</th>
                                        <th>الوقت</th>
                                        <th>الموقع</th>
                                        <th>الحالة</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($activeAlerts as $alert)
                                        <tr class="{{ $alert->status === 'triggered' ? 'table-danger' : 'table-warning' }}">
                                            <td>
                                                <strong>{{ $alert->wearable->user?->name ?? 'غير معروف' }}</strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $alert->alert_type === 'panic' || $alert->alert_type === 'sos' ? 'danger' : ($alert->alert_type === 'fall' ? 'warning' : 'info') }}">
                                                    @switch($alert->alert_type)
                                                        @case('panic') <i class="bi bi-exclamation-diamond me-1"></i>زر الطوارئ @break
                                                        @case('fall') <i class="bi bi-person-falling me-1"></i>كشف سقوط @break
                                                        @case('heart_rate') <i class="bi bi-heart-pulse me-1"></i>نبض القلب @break
                                                        @case('low_battery') <i class="bi bi-battery-low me-1"></i>بطارية @break
                                                        @case('sos') <i class="bi bi-sos me-1"></i>SOS @break
                                                    @endswitch
                                                </span>
                                            </td>
                                            <td>
                                                <small>{{ $alert->created_at->diffForHumans() }}</small>
                                            </td>
                                            <td>
                                                @if($alert->latitude)
                                                    <a href="https://maps.google.com/?q={{ $alert->latitude }},{{ $alert->longitude }}"
                                                       target="_blank" class="text-decoration-none">
                                                        <i class="bi bi-geo-alt"></i> عرض الموقع
                                                    </a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $alert->status === 'triggered' ? 'danger' : 'warning' }}">
                                                    {{ $alert->status === 'triggered' ? 'جديد' : 'تم الاستلام' }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    @if($alert->status === 'triggered')
                                                        <button class="btn btn-warning" onclick="acknowledgeAlert({{ $alert->id }})">
                                                            <i class="bi bi-check"></i> استلام
                                                        </button>
                                                    @endif
                                                    <button class="btn btn-success" onclick="resolveAlert({{ $alert->id }})">
                                                        <i class="bi bi-check-all"></i> إغلاق
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Devices List -->
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>قائمة الأجهزة</h5>
                    <div class="input-group" style="max-width: 250px;">
                        <input type="text" class="form-control form-control-sm" id="searchDevice" placeholder="بحث...">
                        <button class="btn btn-outline-secondary btn-sm" type="button">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المستخدم</th>
                                    <th>الجهاز</th>
                                    <th>آخر اتصال</th>
                                    <th>البطارية</th>
                                    <th>النبض</th>
                                    <th>الموقع</th>
                                    <th>الميزات</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($wearables as $wearable)
                                    <tr class="{{ $wearable->isStale() ? 'table-secondary' : '' }}">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-light rounded-circle me-2 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-person text-secondary"></i>
                                                </div>
                                                <div>
                                                    <strong>{{ $wearable->user?->name ?? 'غير مرتبط' }}</strong>
                                                    <small class="text-muted d-block">{{ $wearable->device_id }}</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                @switch($wearable->device_type)
                                                    @case('smartwatch') <i class="bi bi-smartwatch me-1"></i>ساعة ذكية @break
                                                    @case('band') <i class="bi bi-watch me-1"></i>سوار @break
                                                    @case('beacon') <i class="bi bi-broadcast me-1"></i>منارة @break
                                                    @default {{ $wearable->device_type }}
                                                @endswitch
                                            </span>
                                            @if($wearable->device_model)
                                                <small class="text-muted d-block">{{ $wearable->device_model }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if($wearable->last_heartbeat_at)
                                                <small class="{{ $wearable->isStale() ? 'text-danger' : 'text-success' }}">
                                                    <i class="bi bi-{{ $wearable->isStale() ? 'x-circle' : 'check-circle' }} me-1"></i>
                                                    {{ $wearable->last_heartbeat_at->diffForHumans() }}
                                                </small>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($wearable->battery_level !== null)
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1" style="height: 8px; width: 60px;">
                                                        <div class="progress-bar bg-{{ $wearable->battery_level > 50 ? 'success' : ($wearable->battery_level > 20 ? 'warning' : 'danger') }}"
                                                             style="width: {{ $wearable->battery_level }}%"></div>
                                                    </div>
                                                    <small class="ms-2">{{ $wearable->battery_level }}%</small>
                                                </div>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($wearable->last_heart_rate)
                                                <span class="{{ $wearable->last_heart_rate > 100 || $wearable->last_heart_rate < 50 ? 'text-danger fw-bold' : '' }}">
                                                    <i class="bi bi-heart-pulse me-1"></i>{{ $wearable->last_heart_rate }}
                                                </span>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($wearable->last_latitude)
                                                <a href="https://maps.google.com/?q={{ $wearable->last_latitude }},{{ $wearable->last_longitude }}"
                                                   target="_blank" class="text-decoration-none">
                                                    <i class="bi bi-geo-alt"></i>
                                                </a>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($wearable->panic_enabled)
                                                <span class="badge bg-danger-subtle text-danger" title="زر الطوارئ">
                                                    <i class="bi bi-exclamation-diamond"></i>
                                                </span>
                                            @endif
                                            @if($wearable->fall_detection_enabled)
                                                <span class="badge bg-warning-subtle text-warning" title="كشف السقوط">
                                                    <i class="bi bi-person-falling"></i>
                                                </span>
                                            @endif
                                            @if($wearable->heart_rate_alert_enabled)
                                                <span class="badge bg-info-subtle text-info" title="مراقبة النبض">
                                                    <i class="bi bi-heart-pulse"></i>
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $wearable->is_active ? ($wearable->isStale() ? 'warning' : 'success') : 'secondary' }}">
                                                {{ $wearable->is_active ? ($wearable->isStale() ? 'غير متصل' : 'متصل') : 'غير نشط' }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-smartwatch fs-1 d-block mb-2"></i>
                                            لا توجد أجهزة مسجلة
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
async function acknowledgeAlert(alertId) {
    try {
        const response = await fetch(`/api/iot/wearables/alerts/${alertId}/acknowledge`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'حدث خطأ');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
}

async function resolveAlert(alertId) {
    if (!confirm('هل تريد إغلاق هذا التنبيه؟')) return;

    try {
        const response = await fetch(`/api/iot/wearables/alerts/${alertId}/resolve`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'حدث خطأ');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
}

// Search
document.getElementById('searchDevice')?.addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Auto refresh every 30 seconds
setTimeout(() => location.reload(), 30000);
</script>
@endpush
@endsection
