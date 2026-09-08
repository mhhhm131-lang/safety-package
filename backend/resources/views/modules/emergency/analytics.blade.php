@extends('layouts.app')
@section('page_title', 'تحليلات الطوارئ')
@section('content')
<div class="container-fluid">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 style="color: var(--text-main);">تحليلات الطوارئ</h4>
            <small style="color: var(--text-muted);">
                {{ $from->format('Y-m-d') }} إلى {{ $to->format('Y-m-d') }}
            </small>
        </div>
        <div class="d-flex gap-2">
            <form action="{{ route('emergency.analytics.index') }}" method="GET" class="d-flex gap-2">
                <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="form-control form-control-sm"
                       style="background: var(--bg-card); color: var(--text-main); border-color: var(--border-color);">
                <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="form-control form-control-sm"
                       style="background: var(--bg-card); color: var(--text-main); border-color: var(--border-color);">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i> تصفية
                </button>
            </form>
            <a href="{{ route('emergency.analytics.export', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download me-1"></i> تصدير
            </a>
        </div>
    </div>

    {{-- Executive Summary --}}
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-header">
            <h5 class="mb-0" style="color: var(--text-main);">
                <i class="bi bi-clipboard-data me-2"></i>الملخص التنفيذي
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="p-3 rounded" style="background: var(--bg-main);">
                        <small style="color: var(--text-muted);">إجمالي الحوادث</small>
                        <h3 style="color: var(--accent);">{{ $summary['key_metrics']['total_incidents'] }}</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded" style="background: var(--bg-main);">
                        <small style="color: var(--text-muted);">معدل الحل</small>
                        <h3 style="color: #10b981;">{{ $summary['key_metrics']['resolution_rate'] }}</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded" style="background: var(--bg-main);">
                        <small style="color: var(--text-muted);">متوسط وقت الاستجابة</small>
                        <h3 style="color: #3b82f6;">{{ $summary['key_metrics']['avg_response_time'] }}</h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded" style="background: var(--bg-main);">
                        <small style="color: var(--text-muted);">نجاح الإشعارات</small>
                        <h3 style="color: #8b5cf6;">{{ $summary['key_metrics']['notification_success_rate'] }}</h3>
                    </div>
                </div>
            </div>

            @if(!empty($summary['highlights']) || !empty($summary['concerns']) || !empty($summary['recommendations']))
            <div class="row mt-4 g-3">
                @if(!empty($summary['highlights']))
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);">
                        <h6 style="color: #10b981;"><i class="bi bi-star me-1"></i>النقاط الإيجابية</h6>
                        <ul class="mb-0 ps-3" style="color: var(--text-main);">
                            @foreach($summary['highlights'] as $highlight)
                                <li>{{ $highlight }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endif

                @if(!empty($summary['concerns']))
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3);">
                        <h6 style="color: #ef4444;"><i class="bi bi-exclamation-triangle me-1"></i>نقاط القلق</h6>
                        <ul class="mb-0 ps-3" style="color: var(--text-main);">
                            @foreach($summary['concerns'] as $concern)
                                <li>{{ $concern }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endif

                @if(!empty($summary['recommendations']))
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3);">
                        <h6 style="color: #3b82f6;"><i class="bi bi-lightbulb me-1"></i>التوصيات</h6>
                        <ul class="mb-0 ps-3" style="color: var(--text-main);">
                            @foreach($summary['recommendations'] as $rec)
                                <li>{{ $rec }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Charts Row 1 --}}
    <div class="row g-4 mb-4">
        {{-- Incident Trends Chart --}}
        <div class="col-lg-8">
            <div class="card h-100" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">اتجاه الحوادث الشهري</h6>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" height="300"></canvas>
                </div>
            </div>
        </div>

        {{-- Type Distribution --}}
        <div class="col-lg-4">
            <div class="card h-100" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">توزيع أنواع الحوادث</h6>
                </div>
                <div class="card-body">
                    <canvas id="typeChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts Row 2 --}}
    <div class="row g-4 mb-4">
        {{-- Response Time by Type --}}
        <div class="col-lg-6">
            <div class="card h-100" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">وقت الاستجابة حسب النوع</h6>
                </div>
                <div class="card-body">
                    <canvas id="responseTimeChart" height="250"></canvas>
                </div>
            </div>
        </div>

        {{-- Notification Channels --}}
        <div class="col-lg-6">
            <div class="card h-100" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">أداء قنوات الإشعارات</h6>
                </div>
                <div class="card-body">
                    <canvas id="notificationChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Building Risk Scores --}}
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0" style="color: var(--text-main);">
                <i class="bi bi-building me-2"></i>تقييم مخاطر المباني
            </h6>
        </div>
        <div class="card-body">
            @if(count($metrics['building_risk_scores']) > 0)
            <div class="table-responsive">
                <table class="table table-sm" style="color: var(--text-main);">
                    <thead>
                        <tr style="border-color: var(--border-color);">
                            <th>المبنى</th>
                            <th>درجة الخطر</th>
                            <th>المستوى</th>
                            <th>حوادث (سنة)</th>
                            <th>فرق طوارئ</th>
                            <th>نقاط تجمع</th>
                            <th>آخر تمرين</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($metrics['building_risk_scores'] as $building)
                        <tr style="border-color: var(--border-color);">
                            <td>{{ $building['building_name'] }}</td>
                            <td>
                                <div class="progress" style="height: 20px; background: var(--bg-main);">
                                    @php
                                        $color = match($building['risk_level']) {
                                            'low' => '#10b981',
                                            'medium' => '#f59e0b',
                                            'high' => '#ef4444',
                                            'critical' => '#dc2626',
                                            default => '#6b7280',
                                        };
                                    @endphp
                                    <div class="progress-bar" role="progressbar"
                                         style="width: {{ min($building['risk_score'], 100) }}%; background: {{ $color }};">
                                        {{ $building['risk_score'] }}
                                    </div>
                                </div>
                            </td>
                            <td>
                                @php
                                    $levelLabels = [
                                        'low' => ['منخفض', 'success'],
                                        'medium' => ['متوسط', 'warning'],
                                        'high' => ['عالي', 'danger'],
                                        'critical' => ['حرج', 'danger'],
                                    ];
                                    $level = $levelLabels[$building['risk_level']] ?? ['غير محدد', 'secondary'];
                                @endphp
                                <span class="badge bg-{{ $level[1] }}">{{ $level[0] }}</span>
                            </td>
                            <td>{{ $building['factors']['incidents_last_year'] }}</td>
                            <td>
                                @if($building['factors']['has_teams'])
                                    <i class="bi bi-check-circle text-success"></i>
                                @else
                                    <i class="bi bi-x-circle text-danger"></i>
                                @endif
                            </td>
                            <td>
                                @if($building['factors']['has_assembly_points'])
                                    <i class="bi bi-check-circle text-success"></i>
                                @else
                                    <i class="bi bi-x-circle text-danger"></i>
                                @endif
                            </td>
                            <td>
                                @if($building['factors']['days_since_last_drill'] < 90)
                                    <span class="text-success">{{ $building['factors']['days_since_last_drill'] }} يوم</span>
                                @elseif($building['factors']['days_since_last_drill'] < 180)
                                    <span class="text-warning">{{ $building['factors']['days_since_last_drill'] }} يوم</span>
                                @else
                                    <span class="text-danger">{{ $building['factors']['days_since_last_drill'] }} يوم</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <p class="text-center" style="color: var(--text-muted);">لا توجد مباني مسجلة</p>
            @endif
        </div>
    </div>

    {{-- Time Distribution --}}
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">الحوادث حسب يوم الأسبوع</h6>
                </div>
                <div class="card-body">
                    <canvas id="dayOfWeekChart" height="200"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header">
                    <h6 class="mb-0" style="color: var(--text-main);">الحوادث حسب الساعة</h6>
                </div>
                <div class="card-body">
                    <canvas id="hourChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const chartColors = {
        primary: '#e94560',
        success: '#10b981',
        warning: '#f59e0b',
        danger: '#ef4444',
        info: '#3b82f6',
        purple: '#8b5cf6',
        gray: '#6b7280',
    };

    const chartDefaults = {
        color: '#94a3b8',
        borderColor: 'rgba(255,255,255,0.1)',
    };

    Chart.defaults.color = chartDefaults.color;
    Chart.defaults.borderColor = chartDefaults.borderColor;

    // Incident Trends Chart
    const trendsData = @json($metrics['incident_trends']['monthly']);
    const trendsLabels = Object.keys(trendsData);
    const incidentTypes = ['fire', 'earthquake', 'chemical_spill', 'gas_leak', 'bomb_threat', 'medical', 'security', 'other'];
    const typeColors = {
        fire: '#ef4444',
        earthquake: '#f59e0b',
        chemical_spill: '#8b5cf6',
        gas_leak: '#6366f1',
        bomb_threat: '#ec4899',
        medical: '#10b981',
        security: '#3b82f6',
        other: '#6b7280',
    };

    const trendsDatasets = incidentTypes.map(type => ({
        label: type,
        data: trendsLabels.map(month => trendsData[month]?.[type] || 0),
        borderColor: typeColors[type],
        backgroundColor: typeColors[type] + '20',
        fill: true,
        tension: 0.4,
    })).filter(ds => ds.data.some(v => v > 0));

    new Chart(document.getElementById('trendsChart'), {
        type: 'line',
        data: {
            labels: trendsLabels,
            datasets: trendsDatasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Type Distribution Pie Chart
    const typeDistribution = @json($metrics['incident_trends']['type_distribution']);
    new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(typeDistribution),
            datasets: [{
                data: Object.values(typeDistribution),
                backgroundColor: Object.keys(typeDistribution).map(t => typeColors[t] || '#6b7280'),
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Response Time Chart
    const responseByType = @json($metrics['response_times']['by_type']);
    new Chart(document.getElementById('responseTimeChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(responseByType),
            datasets: [{
                label: 'متوسط الوقت (دقيقة)',
                data: Object.values(responseByType).map(v => v.avg_minutes),
                backgroundColor: chartColors.primary,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Notification Channels Chart
    const notificationStats = @json($metrics['notification_stats']['by_channel']);
    new Chart(document.getElementById('notificationChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(notificationStats),
            datasets: [
                {
                    label: 'تم الإرسال',
                    data: Object.values(notificationStats).map(v => v.sent),
                    backgroundColor: chartColors.success,
                },
                {
                    label: 'فشل',
                    data: Object.values(notificationStats).map(v => v.failed),
                    backgroundColor: chartColors.danger,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true }
            }
        }
    });

    // Day of Week Chart
    const dayOfWeekData = @json($metrics['incident_trends']['by_day_of_week']);
    const dayLabels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    new Chart(document.getElementById('dayOfWeekChart'), {
        type: 'bar',
        data: {
            labels: dayLabels,
            datasets: [{
                label: 'عدد الحوادث',
                data: dayLabels.map((_, i) => dayOfWeekData[i + 1] || 0),
                backgroundColor: chartColors.info,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Hour Chart
    const hourData = @json($metrics['incident_trends']['by_hour']);
    new Chart(document.getElementById('hourChart'), {
        type: 'line',
        data: {
            labels: Array.from({length: 24}, (_, i) => i + ':00'),
            datasets: [{
                label: 'عدد الحوادث',
                data: Array.from({length: 24}, (_, i) => hourData[i] || 0),
                borderColor: chartColors.purple,
                backgroundColor: chartColors.purple + '20',
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
</script>
@endpush
@endsection
