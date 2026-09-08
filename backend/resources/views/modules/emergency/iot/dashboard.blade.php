@extends('layouts.app')

@section('page_title', 'أنظمة المبنى')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-cpu text-primary me-2"></i>
                أنظمة المبنى الموصولة
            </h4>
            <p class="text-muted mb-0">لوحة الإنذار والأبواب والمصاعد والتكييف والشاشات — إشارة الجهاز تفتح حالة طارئة فوراً</p>
        </div>
        <div class="d-flex gap-2">
            <select id="buildingSelector" class="form-select" style="width: 250px;">
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}" {{ $selectedBuilding == $building->id ? 'selected' : '' }}>
                        {{ $building->name }}
                    </option>
                @endforeach
            </select>
            <button class="btn btn-outline-secondary" onclick="refreshAllSystems()">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('emergency.dashboard') }}">مركز الطوارئ</a>
        </div>
    </div>

    {{-- الأجهزة المسجّلة (المرحلة ٥) --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header d-flex align-items-center">
            <h6 class="mb-0"><i class="bi bi-hdd-network me-2"></i>الأجهزة المسجّلة</h6>
            <span class="badge bg-secondary ms-2" id="devices-count">{{ $devices->count() }}</span>
            @if(\App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'integration.manage'))
              <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('emergency.iot.devices.index') }}">إدارة الأجهزة</a>
            @endif
        </div>
        <div class="card-body py-2">
            @if($devices->isEmpty())
              <div class="text-muted small" id="no-devices">لا أجهزة مسجّلة بعد — الأنظمة الخمس تظهر «غير متصل» حتى يُسجَّل جهاز لكل نظام (بانتظار جواب إدارة المرافق عن أنظمة المبنى وبروتوكولاتها).</div>
            @else
              <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                <thead><tr><th>النظام</th><th>الاسم</th><th>البروتوكول</th><th>المبنى</th><th>المكان</th><th>الحالة</th><th>آخر إشارة</th></tr></thead>
                <tbody>
                @foreach($devices as $d)
                  <tr data-device="{{ $d->id }}">
                    <td>{{ $d->getKindLabel() }}</td><td>{{ $d->name }}</td><td><code>{{ $d->protocol }}</code></td>
                    <td>{{ $d->building?->name ?? '—' }}</td><td>{{ $d->place?->name ?? '—' }}</td>
                    <td>@if($d->is_enabled)<span class="badge bg-success">مفعّل</span>@else<span class="badge bg-secondary">موقوف</span>@endif</td>
                    <td class="small text-muted">{{ $d->last_seen_at?->diffForHumans() ?? 'لم تصل إشارة' }}</td>
                  </tr>
                @endforeach
                </tbody></table></div>
            @endif
        </div>
    </div>

    {{-- System Status Cards --}}
    <div class="row g-3 mb-4">
        {{-- Fire Panel --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm system-card" data-system="fire_panel">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-fire fs-1 text-danger"></i>
                    </div>
                    <h6 class="mb-1">نظام الإنذار</h6>
                    <span class="badge bg-secondary status-badge" id="fire_panel_status">جاري التحميل...</span>
                </div>
            </div>
        </div>

        {{-- Access Control --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm system-card" data-system="access_control">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-door-open fs-1 text-info"></i>
                    </div>
                    <h6 class="mb-1">التحكم بالأبواب</h6>
                    <span class="badge bg-secondary status-badge" id="access_control_status">جاري التحميل...</span>
                </div>
            </div>
        </div>

        {{-- Elevators --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm system-card" data-system="elevator">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-arrow-down-up fs-1 text-primary"></i>
                    </div>
                    <h6 class="mb-1">المصاعد</h6>
                    <span class="badge bg-secondary status-badge" id="elevator_status">جاري التحميل...</span>
                </div>
            </div>
        </div>

        {{-- HVAC --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm system-card" data-system="hvac">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-wind fs-1 text-success"></i>
                    </div>
                    <h6 class="mb-1">التكييف والتهوية</h6>
                    <span class="badge bg-secondary status-badge" id="hvac_status">جاري التحميل...</span>
                </div>
            </div>
        </div>

        {{-- Digital Signage --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm system-card" data-system="signage">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-display fs-1 text-warning"></i>
                    </div>
                    <h6 class="mb-1">شاشات العرض</h6>
                    <span class="badge bg-secondary status-badge" id="signage_status">جاري التحميل...</span>
                </div>
            </div>
        </div>

        {{-- Occupancy --}}
        <div class="col-md-4 col-lg-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="system-icon mb-2">
                        <i class="bi bi-people fs-1 text-purple"></i>
                    </div>
                    <h6 class="mb-1">الإشغال الحالي</h6>
                    <span class="fs-4 fw-bold text-primary" id="current_occupancy">--</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>إجراءات الطوارئ السريعة</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <button class="btn btn-danger w-100 py-3" onclick="initiateFullLockdown()">
                        <i class="bi bi-lock-fill fs-4 d-block mb-1"></i>
                        إغلاق كامل
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-warning w-100 py-3" onclick="emergencyUnlockAll()">
                        <i class="bi bi-unlock-fill fs-4 d-block mb-1"></i>
                        فتح طوارئ
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-info w-100 py-3 text-white" onclick="activateFireRecall()">
                        <i class="bi bi-arrow-down-circle fs-4 d-block mb-1"></i>
                        استدعاء المصاعد
                    </button>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-success w-100 py-3" onclick="broadcastAllClear()">
                        <i class="bi bi-check-circle fs-4 d-block mb-1"></i>
                        إعلان الأمان
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Fire Panel Details --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-fire text-danger me-2"></i>نظام إنذار الحريق</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-warning" onclick="silenceAlarm()">إسكات</button>
                        <button class="btn btn-outline-secondary" onclick="resetFirePanel()">إعادة ضبط</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="fireZonesList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-danger" role="status"></div>
                            <p class="mt-2 text-muted">جاري تحميل المناطق...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Access Control Details --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-door-open text-info me-2"></i>التحكم بالأبواب</h5>
                    <span class="badge bg-info" id="doorsCount">-- باب</span>
                </div>
                <div class="card-body">
                    <div id="doorsList" style="max-height: 300px; overflow-y: auto;">
                        <div class="text-center py-4">
                            <div class="spinner-border text-info" role="status"></div>
                            <p class="mt-2 text-muted">جاري تحميل الأبواب...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Elevator Status --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-arrow-down-up text-primary me-2"></i>حالة المصاعد</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-danger" onclick="activateFireRecall()">Fire Recall</button>
                        <button class="btn btn-outline-success" onclick="returnElevatorsNormal()">عادي</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="elevatorsList" class="row g-2">
                        <div class="text-center py-4 col-12">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">جاري تحميل المصاعد...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- HVAC Status --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-wind text-success me-2"></i>التكييف والتهوية</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-warning" onclick="activateSmokeControl()">Smoke Control</button>
                        <button class="btn btn-outline-danger" onclick="hvacShutdown()">إيقاف</button>
                        <button class="btn btn-outline-success" onclick="hvacNormal()">عادي</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="hvacStatus">
                        <div class="text-center py-4">
                            <div class="spinner-border text-success" role="status"></div>
                            <p class="mt-2 text-muted">جاري تحميل الحالة...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Digital Signage --}}
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-display text-warning me-2"></i>شاشات العرض</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-danger" onclick="pushEmergencyToSignage()">تنبيه طوارئ</button>
                        <button class="btn btn-outline-info" onclick="showEvacuationRoutes()">مسارات الإخلاء</button>
                        <button class="btn btn-outline-success" onclick="signageAllClear()">إعلان الأمان</button>
                        <button class="btn btn-outline-secondary" onclick="signageNormal()">عادي</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="displaysList" class="row g-2">
                        <div class="text-center py-4 col-12">
                            <div class="spinner-border text-warning" role="status"></div>
                            <p class="mt-2 text-muted">جاري تحميل الشاشات...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- آخر أحداث الأجهزة --}}
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header d-flex align-items-center">
            <h6 class="mb-0"><i class="bi bi-activity me-2"></i>آخر إشارات الأجهزة</h6>
            <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.iot.events') }}">السجل الكامل</a>
        </div>
        <div class="card-body py-2">
            @if($recentEvents->isEmpty())
              <div class="text-muted small">لا إشارات بعد.</div>
            @else
              <table class="table table-sm mb-0 align-middle" id="recent-events">
                <thead><tr><th>الوقت</th><th>الجهاز</th><th>الحدث</th><th>المصدر</th><th>ما فُعل</th><th>الحالة الطارئة</th></tr></thead>
                <tbody>
                @foreach($recentEvents as $e)
                  <tr data-event="{{ $e->id }}" data-action="{{ $e->action_taken }}">
                    <td class="small">{{ $e->received_at?->format('m-d H:i:s') }}</td>
                    <td>{{ $e->device?->name ?? '—' }}</td>
                    <td><code>{{ $e->event_type }}</code></td>
                    <td class="small">{{ $e->source }}</td>
                    <td>{!! $e->getActionBadge() !!}</td>
                    <td>@if($e->incident)<a href="{{ route('emergency.incidents.live', $e->incident) }}">{{ $e->incident->incident_code }}</a>@else —@endif</td>
                  </tr>
                @endforeach
                </tbody>
              </table>
            @endif
        </div>
    </div>
</div>

{{-- Emergency Alert Modal --}}
<div class="modal fade" id="emergencyAlertModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>إرسال تنبيه طوارئ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">نوع الطوارئ</label>
                    <select id="emergencyType" class="form-select">
                        <option value="fire">حريق</option>
                        <option value="evacuation">إخلاء</option>
                        <option value="security">تهديد أمني</option>
                        <option value="chemical">تسرب كيميائي</option>
                        <option value="earthquake">زلزال</option>
                        <option value="medical">طوارئ طبية</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">الرسالة (إنجليزي)</label>
                    <textarea id="messageEn" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">الرسالة (عربي)</label>
                    <textarea id="messageAr" class="form-control" rows="2" dir="rtl"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" onclick="sendEmergencyAlert()">
                    <i class="bi bi-broadcast me-1"></i>إرسال التنبيه
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Lockdown Modal --}}
<div class="modal fade" id="lockdownModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-lock-fill me-2"></i>تفعيل الإغلاق</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">مستوى الإغلاق</label>
                    <select id="lockdownLevel" class="form-select">
                        <option value="soft">إغلاق خفيف - تأمين المحيط فقط</option>
                        <option value="modified">إغلاق معدّل - تقييد الحركة</option>
                        <option value="full">إغلاق كامل - جميع الأبواب</option>
                        <option value="shelter">احتماء في المكان</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">السبب</label>
                    <input type="text" id="lockdownReason" class="form-control" placeholder="أدخل سبب الإغلاق">
                </div>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    سيتم إغلاق جميع الأبواب وإرسال تنبيهات لجميع الموظفين.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-danger" onclick="confirmLockdown()">
                    <i class="bi bi-lock-fill me-1"></i>تفعيل الإغلاق
                </button>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .system-card {
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .system-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15) !important;
    }
    .system-icon {
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .zone-item {
        padding: 0.75rem;
        border-radius: 0.5rem;
        margin-bottom: 0.5rem;
        background: #f8f9fa;
    }
    .zone-item.alarm {
        background: #f8d7da;
        animation: pulse-danger 1s infinite;
    }
    .zone-item.trouble {
        background: #fff3cd;
    }
    @keyframes pulse-danger {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    .door-item {
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem;
        margin-bottom: 0.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8f9fa;
    }
    .elevator-card {
        text-align: center;
        padding: 1rem;
        border-radius: 0.5rem;
        background: #f8f9fa;
    }
    .elevator-card.fire-recall {
        background: #f8d7da;
    }
    .display-card {
        padding: 0.75rem;
        border-radius: 0.5rem;
        background: #f8f9fa;
        text-align: center;
    }
    .display-card.emergency {
        background: #f8d7da;
    }
    .text-purple {
        color: #6f42c1;
    }
</style>
@endpush

@push('scripts')
<script>
const buildingId = document.getElementById('buildingSelector').value;
const apiBase = '/api/iot';

document.getElementById('buildingSelector').addEventListener('change', function() {
    window.location.href = '?building=' + this.value;
});

function refreshAllSystems() {
    loadFirePanel();
    loadAccessControl();
    loadElevators();
    loadHvac();
    loadSignage();
    loadOccupancy();
}

// Load Fire Panel
async function loadFirePanel() {
    try {
        const response = await fetch(`${apiBase}/fire-panel/buildings/${buildingId}/status`);
        const data = await response.json();

        const statusEl = document.getElementById('fire_panel_status');
        if (data.online) {
            statusEl.textContent = data.state === 'normal' ? 'طبيعي' : (data.state === 'alarm' ? 'إنذار' : data.state);
            statusEl.className = `badge ${data.state === 'normal' ? 'bg-success' : 'bg-danger'}`;
        } else {
            statusEl.textContent = 'غير متصل';
            statusEl.className = 'badge bg-secondary';
        }

        // Load zones
        const zonesResponse = await fetch(`${apiBase}/fire-panel/buildings/${buildingId}/zones`);
        const zones = await zonesResponse.json();
        renderFireZones(zones);
    } catch (e) {
        document.getElementById('fire_panel_status').textContent = 'خطأ';
        document.getElementById('fire_panel_status').className = 'badge bg-danger';
    }
}

function renderFireZones(zones) {
    const container = document.getElementById('fireZonesList');
    if (!zones || zones.length === 0) {
        container.innerHTML = '<p class="text-muted text-center">لا توجد مناطق</p>';
        return;
    }

    container.innerHTML = zones.map(zone => `
        <div class="zone-item ${zone.state}">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>${zone.name}</strong>
                    <span class="badge ${zone.state === 'normal' ? 'bg-success' : zone.state === 'alarm' ? 'bg-danger' : 'bg-warning'} ms-2">
                        ${zone.state === 'normal' ? 'طبيعي' : zone.state === 'alarm' ? 'إنذار' : 'عطل'}
                    </span>
                </div>
                <small class="text-muted">${zone.devices?.length || 0} جهاز</small>
            </div>
        </div>
    `).join('');
}

// Load Access Control
async function loadAccessControl() {
    try {
        const response = await fetch(`${apiBase}/access-control/buildings/${buildingId}/doors`);
        const data = await response.json();

        const statusEl = document.getElementById('access_control_status');
        const doors = data.doors || [];
        const lockedCount = doors.filter(d => d.state === 'locked').length;

        if (data.enabled === false || (doors.length === 0 && data.success === false)) { statusEl.textContent = 'غير متصل'; statusEl.className = 'badge bg-secondary'; }
        else { statusEl.textContent = `${lockedCount}/${doors.length} مغلق`; statusEl.className = 'badge bg-success'; }

        document.getElementById('doorsCount').textContent = `${doors.length} باب`;
        renderDoors(doors);
    } catch (e) {
        document.getElementById('access_control_status').textContent = 'خطأ';
        document.getElementById('access_control_status').className = 'badge bg-danger';
    }
}

function renderDoors(doors) {
    const container = document.getElementById('doorsList');
    if (!doors || doors.length === 0) {
        container.innerHTML = '<p class="text-muted text-center">لا توجد أبواب</p>';
        return;
    }

    container.innerHTML = doors.map(door => `
        <div class="door-item">
            <div>
                <i class="bi bi-door-${door.state === 'locked' ? 'closed' : 'open'} me-2"></i>
                ${door.name || door.id}
            </div>
            <div>
                <span class="badge ${door.state === 'locked' ? 'bg-success' : 'bg-warning'}">${door.state === 'locked' ? 'مغلق' : 'مفتوح'}</span>
                <button class="btn btn-sm btn-outline-primary ms-2" onclick="toggleDoor('${door.id}', '${door.state}')">
                    ${door.state === 'locked' ? 'فتح' : 'إغلاق'}
                </button>
            </div>
        </div>
    `).join('');
}

// Load Elevators
async function loadElevators() {
    try {
        const response = await fetch(`${apiBase}/elevator/buildings/${buildingId}/status`);
        const data = await response.json();

        const statusEl = document.getElementById('elevator_status');
        if (data.success) {
            const elevators = data.elevators || [];
            const inService = elevators.filter(e => e.mode !== 'out_of_service').length;
            statusEl.textContent = `${inService}/${elevators.length} يعمل`;
            statusEl.className = 'badge bg-success';
            renderElevators(elevators);
        } else {
            statusEl.textContent = 'غير متصل';
            statusEl.className = 'badge bg-secondary';
        }
    } catch (e) {
        document.getElementById('elevator_status').textContent = 'خطأ';
        document.getElementById('elevator_status').className = 'badge bg-danger';
    }
}

function renderElevators(elevators) {
    const container = document.getElementById('elevatorsList');
    if (!elevators || elevators.length === 0) {
        container.innerHTML = '<div class="col-12"><p class="text-muted text-center">لا توجد مصاعد</p></div>';
        return;
    }

    container.innerHTML = elevators.map(elev => `
        <div class="col-md-3">
            <div class="elevator-card ${elev.mode === 'fire_recall' ? 'fire-recall' : ''}">
                <i class="bi bi-arrow-down-up fs-2 text-primary"></i>
                <div class="fw-bold mt-2">${elev.name || elev.id}</div>
                <div class="text-muted">الطابق ${elev.floor || '--'}</div>
                <span class="badge ${elev.mode === 'normal' ? 'bg-success' : 'bg-warning'} mt-1">
                    ${elev.mode === 'normal' ? 'عادي' : elev.mode === 'fire_recall' ? 'Fire Recall' : elev.mode}
                </span>
            </div>
        </div>
    `).join('');
}

// Load HVAC
async function loadHvac() {
    try {
        const response = await fetch(`${apiBase}/hvac/buildings/${buildingId}/status`);
        const data = await response.json();

        const statusEl = document.getElementById('hvac_status');
        if (data.success) {
            const mode = data.data?.mode || 'normal';
            statusEl.textContent = mode === 'normal' ? 'طبيعي' : mode;
            statusEl.className = `badge ${mode === 'normal' ? 'bg-success' : 'bg-warning'}`;
            renderHvac(data.data);
        } else {
            statusEl.textContent = 'غير متصل';
            statusEl.className = 'badge bg-secondary';
        }
    } catch (e) {
        document.getElementById('hvac_status').textContent = 'خطأ';
        document.getElementById('hvac_status').className = 'badge bg-danger';
    }
}

function renderHvac(hvacData) {
    const container = document.getElementById('hvacStatus');
    if (!hvacData) {
        container.innerHTML = '<p class="text-muted text-center">لا توجد بيانات</p>';
        return;
    }

    container.innerHTML = `
        <div class="row text-center">
            <div class="col-4">
                <div class="p-3 rounded bg-light">
                    <i class="bi bi-thermometer-half fs-3 text-danger"></i>
                    <div class="fw-bold mt-2">${hvacData.temperature || '--'}°C</div>
                    <small class="text-muted">درجة الحرارة</small>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded bg-light">
                    <i class="bi bi-droplet-half fs-3 text-info"></i>
                    <div class="fw-bold mt-2">${hvacData.humidity || '--'}%</div>
                    <small class="text-muted">الرطوبة</small>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded bg-light">
                    <i class="bi bi-wind fs-3 text-success"></i>
                    <div class="fw-bold mt-2">${hvacData.mode || 'normal'}</div>
                    <small class="text-muted">الوضع</small>
                </div>
            </div>
        </div>
    `;
}

// Load Signage
async function loadSignage() {
    try {
        const response = await fetch(`${apiBase}/signage/buildings/${buildingId}/displays`);
        const data = await response.json();

        const statusEl = document.getElementById('signage_status');
        const displays = data.displays || [];
        const onlineCount = displays.filter(d => d.status === 'online').length;

        if (data.enabled === false || (displays.length === 0 && data.success === false)) { statusEl.textContent = 'غير متصل'; statusEl.className = 'badge bg-secondary'; }
        else { statusEl.textContent = `${onlineCount}/${displays.length} متصل`; statusEl.className = 'badge bg-success'; }
        renderDisplays(displays);
    } catch (e) {
        document.getElementById('signage_status').textContent = 'خطأ';
        document.getElementById('signage_status').className = 'badge bg-danger';
    }
}

function renderDisplays(displays) {
    const container = document.getElementById('displaysList');
    if (!displays || displays.length === 0) {
        container.innerHTML = '<div class="col-12"><p class="text-muted text-center">لا توجد شاشات</p></div>';
        return;
    }

    container.innerHTML = displays.map(display => `
        <div class="col-md-2">
            <div class="display-card ${display.mode === 'emergency' ? 'emergency' : ''}">
                <i class="bi bi-display fs-3 ${display.status === 'online' ? 'text-success' : 'text-muted'}"></i>
                <div class="small mt-1">${display.name || display.id}</div>
                <span class="badge ${display.status === 'online' ? 'bg-success' : 'bg-secondary'} mt-1">
                    ${display.status === 'online' ? 'متصل' : 'غير متصل'}
                </span>
            </div>
        </div>
    `).join('');
}

// Load Occupancy
async function loadOccupancy() {
    try {
        const response = await fetch(`${apiBase}/access-control/buildings/${buildingId}/occupancy`);
        const data = await response.json();
        document.getElementById('current_occupancy').textContent = (data.success === false || data.enabled === false) ? '—' : (data.total || 0);
    } catch (e) {
        document.getElementById('current_occupancy').textContent = '--';
    }
}

// Quick Actions
function initiateFullLockdown() {
    const modal = new bootstrap.Modal(document.getElementById('lockdownModal'));
    modal.show();
}

async function confirmLockdown() {
    const level = document.getElementById('lockdownLevel').value;
    const reason = document.getElementById('lockdownReason').value;

    try {
        const response = await fetch(`${apiBase}/lockdown/buildings/${buildingId}/initiate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ level, reason })
        });

        const data = await response.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('lockdownModal')).hide();
            alert('تم تفعيل الإغلاق بنجاح');
            refreshAllSystems();
        } else {
            alert('فشل تفعيل الإغلاق: ' + (data.error || 'خطأ غير معروف'));
        }
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

async function emergencyUnlockAll() {
    if (!confirm('هل أنت متأكد من فتح جميع الأبواب؟')) return;

    try {
        const response = await fetch(`${apiBase}/access-control/buildings/${buildingId}/emergency-unlock`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        const data = await response.json();
        if (data.success) {
            alert(`تم فتح ${data.doors_affected || 'جميع'} الأبواب`);
            refreshAllSystems();
        }
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

async function activateFireRecall() {
    if (!confirm('هل تريد تفعيل Fire Recall لجميع المصاعد؟')) return;

    try {
        const response = await fetch(`${apiBase}/elevator/buildings/${buildingId}/fire-recall`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        const data = await response.json();
        if (data.success) {
            alert('تم تفعيل Fire Recall');
            refreshAllSystems();
        }
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

async function broadcastAllClear() {
    if (!confirm('هل تريد إعلان انتهاء الطوارئ؟')) return;

    try {
        await fetch(`${apiBase}/signage/buildings/${buildingId}/all-clear`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        await fetch(`${apiBase}/lockdown/buildings/${buildingId}/lift`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        alert('تم إعلان الأمان');
        refreshAllSystems();
    } catch (e) {
        alert('خطأ في الاتصال');
    }
}

// Other actions
async function silenceAlarm() {
    try {
        await fetch(`${apiBase}/fire-panel/buildings/${buildingId}/silence`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadFirePanel();
    } catch (e) { alert('خطأ'); }
}

async function resetFirePanel() {
    if (!confirm('هل تريد إعادة ضبط نظام الإنذار؟')) return;
    try {
        await fetch(`${apiBase}/fire-panel/buildings/${buildingId}/reset`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadFirePanel();
    } catch (e) { alert('خطأ'); }
}

async function returnElevatorsNormal() {
    try {
        await fetch(`${apiBase}/elevator/buildings/${buildingId}/normal`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadElevators();
    } catch (e) { alert('خطأ'); }
}

async function activateSmokeControl() {
    try {
        await fetch(`${apiBase}/hvac/buildings/${buildingId}/smoke-control`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadHvac();
    } catch (e) { alert('خطأ'); }
}

async function hvacShutdown() {
    if (!confirm('هل تريد إيقاف نظام التكييف؟')) return;
    try {
        await fetch(`${apiBase}/hvac/buildings/${buildingId}/shutdown`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadHvac();
    } catch (e) { alert('خطأ'); }
}

async function hvacNormal() {
    try {
        await fetch(`${apiBase}/hvac/buildings/${buildingId}/normal`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadHvac();
    } catch (e) { alert('خطأ'); }
}

function pushEmergencyToSignage() {
    const modal = new bootstrap.Modal(document.getElementById('emergencyAlertModal'));
    modal.show();
}

async function sendEmergencyAlert() {
    const type = document.getElementById('emergencyType').value;
    const messageEn = document.getElementById('messageEn').value;
    const messageAr = document.getElementById('messageAr').value;

    try {
        await fetch(`${apiBase}/signage/buildings/${buildingId}/emergency-alert`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ type, message_en: messageEn, message_ar: messageAr })
        });

        bootstrap.Modal.getInstance(document.getElementById('emergencyAlertModal')).hide();
        alert('تم إرسال التنبيه');
        loadSignage();
    } catch (e) { alert('خطأ'); }
}

async function showEvacuationRoutes() {
    try {
        await fetch(`${apiBase}/signage/buildings/${buildingId}/evacuation-routes`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadSignage();
    } catch (e) { alert('خطأ'); }
}

async function signageAllClear() {
    try {
        await fetch(`${apiBase}/signage/buildings/${buildingId}/all-clear`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadSignage();
    } catch (e) { alert('خطأ'); }
}

async function signageNormal() {
    try {
        await fetch(`${apiBase}/signage/buildings/${buildingId}/normal`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        loadSignage();
    } catch (e) { alert('خطأ'); }
}

async function toggleDoor(doorId, currentState) {
    const action = currentState === 'locked' ? 'unlock' : 'lock';
    try {
        await fetch(`${apiBase}/access-control/buildings/${buildingId}/${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ door_id: doorId })
        });
        loadAccessControl();
    } catch (e) { alert('خطأ'); }
}

// Initialize
document.addEventListener('DOMContentLoaded', refreshAllSystems);

// Auto-refresh every 30 seconds
setInterval(refreshAllSystems, 30000);
</script>
@endpush
@endsection
