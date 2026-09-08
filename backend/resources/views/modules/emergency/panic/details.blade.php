@extends('layouts.app')

@section('page_title', 'تفاصيل التنبيه')

@push('styles')
<style>
    .severity-critical { background-color: #dc3545; color: white; }
    .severity-high { background-color: #fd7e14; color: white; }
    .severity-medium { background-color: #ffc107; color: #000; }
    .severity-low { background-color: #28a745; color: white; }
    .status-triggered { background-color: #dc3545; }
    .status-acknowledged { background-color: #ffc107; color: #000; }
    .status-responding { background-color: #17a2b8; }
    .status-resolved { background-color: #28a745; }
    .status-false_alarm { background-color: #6c757d; }
    .timeline-item { border-right: 3px solid #dee2e6; padding-right: 20px; margin-right: 10px; position: relative; }
    .timeline-item::before { content: ''; width: 12px; height: 12px; background: #007bff; border-radius: 50%; position: absolute; right: -7px; top: 5px; }
    .timeline-item.danger::before { background: #dc3545; }
    .timeline-item.success::before { background: #28a745; }
    .timeline-item.warning::before { background: #ffc107; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="{{ route('emergency.panic.dashboard') }}" class="text-muted text-decoration-none mb-2 d-inline-block">
                <i class="bi bi-arrow-right me-1"></i>العودة لتنبيهات الذعر
            </a>
            <h4 class="mb-0" style="color: var(--text-main);">
                <i class="bi bi-bell-fill me-2"></i>تنبيه #{{ $alert->id }}
                <span class="badge status-{{ $alert->status }} ms-2">{{ $alert->getStatusLabel() }}</span>
            </h4>
        </div>
        <div>
            @if($alert->canBeResolved())
                <form action="{{ route('api.emergency.panic.resolve', $alert) }}" method="POST" class="d-inline" id="resolveForm">
                    @csrf
                    <input type="hidden" name="resolution_notes" id="resolution_notes">
                    <button type="button" class="btn btn-success me-2" onclick="resolveAlert()">
                        <i class="bi bi-check-lg me-1"></i>إغلاق التنبيه
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resolveAlert(true)">
                        <i class="bi bi-x-lg me-1"></i>إنذار كاذب
                    </button>
                </form>
            @endif
            @if(!$alert->incident_id && $alert->isActive())
                <button class="btn btn-danger ms-2" onclick="escalateAlert()">
                    <i class="bi bi-arrow-up-circle me-1"></i>تصعيد لطوارئ
                </button>
            @endif
        </div>
    </div>

    <div class="row g-4">
        {{-- Main Info --}}
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>معلومات التنبيه</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">المُبلّغ</label>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-person-circle fs-4 me-2 text-primary"></i>
                                <div>
                                    <strong>{{ $alert->user->name }}</strong>
                                    @if($alert->user->phone)
                                        <br><a href="tel:{{ $alert->user->phone }}" class="text-decoration-none">
                                            <i class="bi bi-telephone me-1"></i>{{ $alert->user->phone }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">نوع التنبيه</label>
                            <div>
                                <span class="badge severity-{{ $alert->severity }} fs-6">{{ $alert->getTypeLabel() }}</span>
                                <span class="badge bg-secondary fs-6 ms-1">{{ $alert->getSeverityLabel() }}</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">الموقع</label>
                            <div>
                                <i class="bi bi-geo-alt me-1"></i>
                                {{ $alert->getLocationString() ?? 'غير محدد' }}
                                @if($alert->latitude && $alert->longitude)
                                    <br>
                                    <small class="text-muted">
                                        الإحداثيات: {{ $alert->latitude }}, {{ $alert->longitude }}
                                        @if($alert->accuracy_meters)
                                            (دقة: {{ round($alert->accuracy_meters) }}م)
                                        @endif
                                    </small>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">المبنى</label>
                            <div>
                                @if($alert->building)
                                    <i class="bi bi-building me-1"></i>
                                    <a href="{{ route('emergency.buildings.show', $alert->building) }}">{{ $alert->building->name }}</a>
                                @else
                                    <span class="text-muted">غير محدد</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">وقت التنبيه</label>
                            <div>
                                <i class="bi bi-clock me-1"></i>
                                {{ $alert->created_at->format('Y-m-d H:i:s') }}
                                <br>
                                <small class="text-muted">{{ $alert->created_at->diffForHumans() }}</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">وقت الاستجابة</label>
                            <div>
                                @if($alert->getResponseTimeSeconds())
                                    <span class="text-success">
                                        <i class="bi bi-stopwatch me-1"></i>
                                        {{ round($alert->getResponseTimeSeconds() / 60, 1) }} دقيقة
                                    </span>
                                @else
                                    <span class="text-warning">في انتظار الاستجابة</span>
                                @endif
                            </div>
                        </div>
                        @if($alert->message)
                            <div class="col-12">
                                <label class="text-muted small">رسالة</label>
                                <div class="p-3 bg-light rounded">
                                    {{ $alert->message }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Map --}}
            @if($alert->latitude && $alert->longitude)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-map me-2"></i>الموقع على الخريطة</h5>
                </div>
                <div class="card-body p-0">
                    <div id="alertMap" style="height: 300px;"></div>
                </div>
            </div>
            @endif

            {{-- Resolution --}}
            @if($alert->status === 'resolved' || $alert->status === 'false_alarm')
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-check-circle me-2"></i>معلومات الإغلاق</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">أغلق بواسطة</label>
                            <div>{{ $alert->resolvedBy->name ?? '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">وقت الإغلاق</label>
                            <div>{{ $alert->resolved_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                        </div>
                        @if($alert->resolution_notes)
                            <div class="col-12">
                                <label class="text-muted small">ملاحظات الإغلاق</label>
                                <div class="p-3 bg-light rounded">{{ $alert->resolution_notes }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Responders --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>المستجيبون ({{ $alert->responders->count() }})</h5>
                </div>
                <div class="card-body p-0">
                    @if($alert->responders->isEmpty())
                        <div class="text-center py-4 text-muted">
                            <p class="mb-0">لم يتم إشعار أي مستجيب بعد</p>
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($alert->responders as $responder)
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>{{ $responder->user->name }}</strong>
                                            @if($responder->response_type)
                                                <br><span class="badge bg-info">{{ $responder->getResponseLabel() }}</span>
                                            @endif
                                        </div>
                                        <div class="text-end">
                                            @if($responder->responded_at)
                                                <small class="text-success">
                                                    <i class="bi bi-check-circle me-1"></i>
                                                    {{ round($responder->getResponseTimeSeconds() / 60, 1) }} د
                                                </small>
                                            @elseif($responder->seen_at)
                                                <small class="text-warning">شاهد</small>
                                            @else
                                                <small class="text-muted">تم الإشعار</small>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Timeline --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>التسلسل الزمني</h5>
                </div>
                <div class="card-body">
                    <div class="timeline-item danger mb-3">
                        <strong>تم تفعيل التنبيه</strong>
                        <br><small class="text-muted">{{ $alert->created_at->format('H:i:s') }}</small>
                    </div>
                    @if($alert->acknowledged_at)
                        <div class="timeline-item warning mb-3">
                            <strong>تم الاستلام</strong>
                            <br><small>{{ $alert->acknowledgedBy->name ?? '-' }}</small>
                            <br><small class="text-muted">{{ $alert->acknowledged_at->format('H:i:s') }}</small>
                        </div>
                    @endif
                    @if($alert->resolved_at)
                        <div class="timeline-item success">
                            <strong>{{ $alert->status === 'false_alarm' ? 'إنذار كاذب' : 'تم الحل' }}</strong>
                            <br><small>{{ $alert->resolvedBy->name ?? '-' }}</small>
                            <br><small class="text-muted">{{ $alert->resolved_at->format('H:i:s') }}</small>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Linked Incident --}}
            @if($alert->incident)
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>حادثة مرتبطة</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>كود:</strong> {{ $alert->incident->incident_code }}
                    </p>
                    <p class="mb-2">
                        <strong>النوع:</strong> {{ $alert->incident->getTypeLabel() }}
                    </p>
                    <p class="mb-0">
                        <strong>الحالة:</strong>
                        <span class="badge bg-{{ $alert->incident->status === 'active' ? 'danger' : 'success' }}">
                            {{ $alert->incident->getStatusLabel() }}
                        </span>
                    </p>
                    <a href="{{ route('emergency.incidents.live', $alert->incident) }}" class="btn btn-outline-danger btn-sm mt-3 w-100">
                        <i class="bi bi-eye me-1"></i>عرض الحادثة
                    </a>
                </div>
            </div>
            @endif

            {{-- Attachments --}}
            @if($alert->voice_data || $alert->photo_data)
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>المرفقات</h5>
                </div>
                <div class="card-body">
                    @if($alert->voice_data)
                        <a href="{{ route('api.emergency.panic.recording', $alert) }}" class="btn btn-outline-primary w-100 mb-2" target="_blank">
                            <i class="bi bi-mic me-1"></i>تسجيل صوتي
                        </a>
                    @endif
                    @if($alert->photo_data)
                        <a href="{{ route('api.emergency.panic.photo', $alert) }}" class="btn btn-outline-primary w-100" target="_blank">
                            <i class="bi bi-image me-1"></i>صورة
                        </a>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
@endpush

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    @if($alert->latitude && $alert->longitude)
    const map = L.map('alertMap').setView([{{ $alert->latitude }}, {{ $alert->longitude }}], 17);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    const alertIcon = L.divIcon({
        html: '<div style="background: #dc3545; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.3);"></div>',
        className: '',
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });

    L.marker([{{ $alert->latitude }}, {{ $alert->longitude }}], { icon: alertIcon })
        .addTo(map)
        .bindPopup('<strong>موقع التنبيه</strong><br>{{ $alert->user->name }}');

    @if($alert->accuracy_meters)
    L.circle([{{ $alert->latitude }}, {{ $alert->longitude }}], {
        radius: {{ $alert->accuracy_meters }},
        color: '#dc3545',
        fillColor: '#dc3545',
        fillOpacity: 0.1
    }).addTo(map);
    @endif
    @endif

    function resolveAlert(isFalseAlarm = false) {
        const notes = prompt(isFalseAlarm ? 'سبب اعتباره إنذار كاذب:' : 'ملاحظات الإغلاق:');
        if (notes === null) return;
        if (!notes.trim()) {
            alert('الرجاء إدخال ملاحظات');
            return;
        }

        const form = document.getElementById('resolveForm');
        document.getElementById('resolution_notes').value = notes;

        if (isFalseAlarm) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'is_false_alarm';
            input.value = '1';
            form.appendChild(input);
        }

        form.submit();
    }

    function escalateAlert() {
        if (!confirm('هل أنت متأكد من تصعيد هذا التنبيه لحالة طوارئ كاملة؟')) return;

        fetch('{{ route("api.emergency.panic.escalate", $alert) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('تم التصعيد بنجاح');
                window.location.reload();
            } else {
                alert(data.message || 'حدث خطأ');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في الاتصال');
        });
    }
</script>
@endpush
