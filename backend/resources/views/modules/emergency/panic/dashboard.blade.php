@extends('layouts.app')

@section('page_title', 'تنبيهات الذعر')

@push('styles')
<style>
    @keyframes pulse-red {
        0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }
    .alert-pulse {
        animation: pulse-red 1.5s infinite;
    }
    .severity-critical { background-color: #dc3545; color: white; }
    .severity-high { background-color: #fd7e14; color: white; }
    .severity-medium { background-color: #ffc107; color: #000; }
    .severity-low { background-color: #28a745; color: white; }
    .status-triggered { background-color: #dc3545; }
    .status-acknowledged { background-color: #ffc107; }
    .status-responding { background-color: #17a2b8; }
    .status-resolved { background-color: #28a745; }
    .status-false_alarm { background-color: #6c757d; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    {{-- Active Alerts Banner --}}
    @if($activeAlerts->isNotEmpty())
    <div class="alert alert-danger d-flex align-items-center mb-4 alert-pulse" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
        <div class="flex-grow-1">
            <strong>تنبيهات نشطة: {{ $activeAlerts->count() }}</strong>
            <span class="ms-2">الرجاء الاستجابة فوراً</span>
        </div>
    </div>
    @endif

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-bell-fill me-2"></i>تنبيهات الذعر
        </h4>
        <div>
            <a href="{{ route('emergency.dashboard') }}" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-right me-1"></i>العودة
            </a>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card h-100 {{ $stats['by_status']['triggered'] > 0 ? 'border-danger' : '' }}">
                <div class="card-body text-center">
                    <i class="bi bi-bell-fill fs-1 {{ $stats['by_status']['triggered'] > 0 ? 'text-danger' : 'text-muted' }} mb-2"></i>
                    <h3 class="mb-1 {{ $stats['by_status']['triggered'] > 0 ? 'text-danger' : '' }}">{{ $stats['by_status']['triggered'] }}</h3>
                    <p class="text-muted mb-0">تنبيهات جديدة</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle fs-1 text-success mb-2"></i>
                    <h3 class="mb-1">{{ $stats['by_status']['resolved'] }}</h3>
                    <p class="text-muted mb-0">تم حلها (هذا الشهر)</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100">
                <div class="card-body text-center">
                    <i class="bi bi-stopwatch fs-1 text-info mb-2"></i>
                    <h3 class="mb-1">{{ $stats['avg_response_time'] ? round($stats['avg_response_time'] / 60, 1) . ' د' : '-' }}</h3>
                    <p class="text-muted mb-0">متوسط وقت الاستجابة</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100">
                <div class="card-body text-center">
                    <i class="bi bi-graph-up fs-1 text-primary mb-2"></i>
                    <h3 class="mb-1">{{ $stats['total'] }}</h3>
                    <p class="text-muted mb-0">إجمالي التنبيهات (الشهر)</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Active Alerts --}}
        <div class="col-lg-7">
            <div class="card {{ $activeAlerts->isNotEmpty() ? 'border-danger' : '' }}">
                <div class="card-header d-flex justify-content-between align-items-center {{ $activeAlerts->isNotEmpty() ? 'bg-danger text-white' : '' }}">
                    <h5 class="mb-0">
                        <i class="bi bi-bell-fill me-2"></i>
                        التنبيهات النشطة
                        @if($activeAlerts->isNotEmpty())
                            <span class="badge bg-white text-danger ms-2">{{ $activeAlerts->count() }}</span>
                        @endif
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if($activeAlerts->isEmpty())
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle fs-1 text-success"></i>
                            <p class="mt-2">لا توجد تنبيهات نشطة</p>
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($activeAlerts as $alert)
                                <a href="{{ route('emergency.panic.show', $alert) }}" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="badge severity-{{ $alert->severity }} me-2">{{ $alert->getSeverityLabel() }}</span>
                                                <strong>{{ $alert->user->name }}</strong>
                                            </div>
                                            <p class="mb-1">
                                                <i class="bi bi-geo-alt me-1"></i>
                                                {{ $alert->getLocationString() ?? 'موقع غير محدد' }}
                                            </p>
                                            @if($alert->building)
                                                <small class="text-muted">
                                                    <i class="bi bi-building me-1"></i>{{ $alert->building->name }}
                                                </small>
                                            @endif
                                            @if($alert->message)
                                                <p class="mb-0 small text-muted mt-1">{{ Str::limit($alert->message, 80) }}</p>
                                            @endif
                                        </div>
                                        <div class="text-end">
                                            <span class="badge status-{{ $alert->status }} mb-2">{{ $alert->getStatusLabel() }}</span>
                                            <br>
                                            <small class="text-muted">{{ $alert->created_at->diffForHumans() }}</small>
                                            @if($alert->responders->count() > 0)
                                                <br>
                                                <small class="text-info">
                                                    <i class="bi bi-people me-1"></i>{{ $alert->responders->count() }} مستجيب
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Stats by Type --}}
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>التنبيهات حسب النوع</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center">
                                <i class="bi bi-exclamation-circle text-danger fs-4"></i>
                                <h4 class="mb-0">{{ $stats['by_type']['panic'] ?? 0 }}</h4>
                                <small class="text-muted">ذعر</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center">
                                <i class="bi bi-heart-pulse text-danger fs-4"></i>
                                <h4 class="mb-0">{{ $stats['by_type']['medical'] ?? 0 }}</h4>
                                <small class="text-muted">طبي</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center">
                                <i class="bi bi-fire text-orange fs-4" style="color: #fd7e14;"></i>
                                <h4 class="mb-0">{{ $stats['by_type']['fire'] ?? 0 }}</h4>
                                <small class="text-muted">حريق</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center">
                                <i class="bi bi-shield-exclamation text-warning fs-4"></i>
                                <h4 class="mb-0">{{ $stats['by_type']['security'] ?? 0 }}</h4>
                                <small class="text-muted">أمني</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Recent Resolved --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر التنبيهات المغلقة</h5>
                </div>
                <div class="card-body p-0">
                    @if($recentAlerts->isEmpty())
                        <div class="text-center py-4 text-muted">
                            <p class="mb-0">لا توجد تنبيهات سابقة</p>
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($recentAlerts->take(5) as $alert)
                                <a href="{{ route('emergency.panic.show', $alert) }}" class="list-group-item list-group-item-action py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge status-{{ $alert->status }} me-2">{{ $alert->getStatusLabel() }}</span>
                                            <span>{{ $alert->user->name }}</span>
                                        </div>
                                        <small class="text-muted">{{ $alert->created_at->diffForHumans() }}</small>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Auto-refresh every 30 seconds for active alerts
    @if($activeAlerts->isNotEmpty())
    setTimeout(function() {
        window.location.reload();
    }, 30000);
    @endif
</script>
@endpush
