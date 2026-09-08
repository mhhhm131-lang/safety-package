@extends('layouts.app')

@section('page_title', 'لوحة تحكم المشروع')

@section('content')
<div class="container-fluid">
    {{-- Project Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1" style="color: var(--text-main);">{{ $project->name }}</h5>
            <small style="color: var(--text-muted);">{{ $project->code }}</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('projects.contractors', $project) }}" class="btn btn-outline-primary">
                <i class="bi bi-people me-1"></i> المقاولون
            </a>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-right me-1"></i> العودة للمشاريع
            </a>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        @php
            $kpiCards = [
                ['label' => 'تصاريح نشطة',  'value' => $data['active_permits'] ?? '— (المرحلة ٦-ب)',  'color' => '#0dcaf0', 'icon' => 'bi-file-earmark-check', 'route' => null],
                ['label' => 'مخاطر مفتوحة', 'value' => $data['open_risks'],      'color' => '#ffc107', 'icon' => 'bi-shield-exclamation', 'route' => route('projects.risks', $project)],
                ['label' => 'حوادث',         'value' => $data['incidents_count'], 'color' => '#dc3545', 'icon' => 'bi-exclamation-triangle', 'route' => null],
                ['label' => 'عمال نشطين',   'value' => $data['active_workers'],  'color' => '#198754', 'icon' => 'bi-people', 'route' => null],
            ];
        @endphp
        @foreach($kpiCards as $kpi)
            <div class="col-md-3">
                @if($kpi['route'])
                <a href="{{ $kpi['route'] }}" class="text-decoration-none">
                @endif
                <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-top: 3px solid {{ $kpi['color'] }}; {{ $kpi['route'] ? 'cursor:pointer;' : '' }}">
                    <div class="card-body text-center">
                        <i class="bi {{ $kpi['icon'] }} fs-3" style="color: {{ $kpi['color'] }};"></i>
                        <h3 class="fw-bold mt-2 mb-0" style="color: var(--text-main);">{{ $kpi['value'] }}</h3>
                        <small style="color: var(--text-muted);">{{ $kpi['label'] }}</small>
                    </div>
                </div>
                @if($kpi['route'])
                </a>
                @endif
            </div>
        @endforeach
    </div>

    {{-- بطاقات المعدات/الرفاهية/البيئة/الطوارئ/CAPA (وحدة EPC في OHSMS) — المعدات تُنقل في المرحلة ٦-ب --}}

    @if($data['contractors']->isNotEmpty())
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="background: transparent; border-bottom: 1px solid var(--border-color);">
            <h6 class="mb-0 fw-bold" style="color: var(--text-main);">المقاولون</h6>
            <a href="{{ route('projects.contractors', $project) }}" class="btn btn-sm btn-outline-secondary">
                عرض الكل
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color: var(--text-main);">
                    <thead>
                        <tr style="color: var(--text-muted);">
                            <th>المقاول</th>
                            <th class="text-center">الحالة</th>
                            <th class="text-center">العمال</th>
                            <th class="text-center">ساعات العمل</th>
                            <th class="text-center">التصاريح النشطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['contractors'] as $c)
                            <tr>
                                <td>{{ $c['name'] }}</td>
                                <td class="text-center">
                                    @php
                                        $statusColors = [
                                            'pre_approved'   => 'info',
                                            'post_approved'  => 'success',
                                            'post_review'    => 'warning',
                                            'suspended'      => 'danger',
                                        ];
                                        $statusLabels = [
                                            'pre_approved'   => 'قبل التعاقد',
                                            'post_approved'  => 'مؤهل',
                                            'post_review'    => 'قيد المراجعة',
                                            'suspended'      => 'موقوف',
                                        ];
                                        $color = $statusColors[$c['status']] ?? 'secondary';
                                        $label = $statusLabels[$c['status']] ?? $c['status'];
                                    @endphp
                                    <span class="badge bg-{{ $color }}">{{ $label }}</span>
                                </td>
                                <td class="text-center">{{ number_format($c['workers']) }}</td>
                                <td class="text-center">{{ number_format($c['manhours']) }}</td>
                                <td class="text-center">
                                    <span class="badge bg-secondary">{{ $c['permits'] ?? '—' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    <div class="row g-4">
        {{-- Recent Risks --}}
        <div class="col-md-6">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">آخر المخاطر</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="color: var(--text-main);">
                            <thead>
                                <tr style="color: var(--text-muted);">
                                    <th>العنوان</th>
                                    <th>الدرجة</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($data['recent_risks'] as $risk)
                                    <tr>
                                        <td>
                                            <a href="{{ route('risk.show', $risk) }}"
                                               style="color: var(--accent-color); text-decoration: none;">
                                                {{ $risk->title }}
                                            </a>
                                        </td>
                                        <td><span class="badge bg-warning text-dark">{{ $risk->risk_score }}</span></td>
                                        <td style="color: var(--text-muted);">{{ $risk->status_label ?? $risk->status }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-3" style="color: var(--text-muted);">
                                            لا توجد مخاطر مسجّلة
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Manhour Summary --}}
        <div class="col-md-6">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">ملخص ساعات العمل</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <h4 class="fw-bold" style="color: var(--accent-color);">
                                {{ number_format($data['manhours']['total']) }}
                            </h4>
                            <small style="color: var(--text-muted);">إجمالي الساعات</small>
                        </div>
                        <div class="col-4">
                            <h4 class="fw-bold" style="color: #198754;">
                                {{ number_format($data['manhours']['safe']) }}
                            </h4>
                            <small style="color: var(--text-muted);">ساعات بدون حوادث</small>
                        </div>
                        <div class="col-4">
                            <h4 class="fw-bold" style="color: #ffc107;">
                                {{ $data['manhours']['ltir'] }}
                            </h4>
                            <small style="color: var(--text-muted);">LTIR</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
