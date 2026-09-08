@extends('layouts.app')
@section('page_title', $project->name)
@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 style="color: var(--text-main);">{{ $project->name }}</h4>
            <span class="text-muted">{{ $project->code ?? '' }}</span>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('projects.contractors', $project) }}" class="btn btn-outline-primary"><i class="bi bi-people"></i> المقاولون</a>
            @if(auth()->user()->can_('project.edit'))<a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-warning"><i class="bi bi-pencil"></i> تعديل</a>@endif
            <a href="{{ route('projects.dashboard', $project) }}" class="btn btn-outline-info"><i class="bi bi-speedometer2"></i> لوحة المعلومات</a>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-right"></i> رجوع</a>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background:transparent;border-bottom:1px solid var(--border-color);"><h6 class="mb-0 fw-bold" style="color:var(--text-main);">بيانات المشروع</h6></div>
                <div class="card-body" style="color:var(--text-main);">
                    <p><strong>الحالة:</strong> <span class="badge bg-secondary">{{ $project->getStatusLabel() }}</span></p>
                    <p><strong>المكان:</strong> {{ $project->place ? $project->place->code.' · '.$project->place->name : 'غير محدد' }}</p>
                    <p><strong>الإدارة المالكة:</strong> {{ $project->organizationUnit?->name ?? 'غير محددة' }}</p>
                    <p><strong>تاريخ البداية:</strong> {{ $project->start_date?->toDateString() ?? 'غير محدد' }}</p>
                    <p><strong>تاريخ النهاية:</strong> {{ $project->end_date?->toDateString() ?? 'غير محدد' }}</p>
                    <p><strong>المقاولون:</strong> @forelse($project->projectContractors as $pc)<a href="{{ route('projects.contractors', $project) }}">{{ $pc->externalParty?->name }}</a> <span class="badge bg-light text-dark border">{{ $pc->getStatusLabel() }}</span> @empty لا مقاولون بعد @endforelse</p>
                    <p><strong>المنسق:</strong> {{ $project->assignedCoordinator->name ?? 'غير محدد' }}</p>
                    <p><strong>الوصف:</strong></p>
                    <p style="color:var(--text-muted);">{{ $project->description ?? 'لا يوجد وصف' }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card mb-3" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header d-flex justify-content-between" style="background:transparent;border-bottom:1px solid var(--border-color);"><h6 class="mb-0 fw-bold" style="color:var(--text-main);">المخاطر</h6><a href="{{ route('projects.risks', $project) }}" class="btn btn-sm btn-outline-primary">عرض الكل</a></div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0" style="color:var(--text-main);">
                        <thead><tr><th style="background:var(--bg-dark);color:var(--text-muted);">العنوان</th><th style="background:var(--bg-dark);color:var(--text-muted);">الدرجة</th><th style="background:var(--bg-dark);color:var(--text-muted);">الحالة</th></tr></thead>
                        <tbody>
                            @forelse($risks ?? [] as $risk)
                            <tr><td>{{ $risk->title }}</td><td><x-risk-score-badge :score="(int)($risk->risk_score ?? 0)" /></td><td><span class="badge bg-secondary">{{ $risk->status }}</span></td></tr>
                            @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">لا توجد مخاطر</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><a href="{{ route('projects.manhours', $project) }}" class="btn btn-outline-primary w-100 py-3"><i class="bi bi-clock-history d-block fs-3 mb-1"></i> ساعات العمل</a></div>
                <div class="col-md-4"><a href="{{ route('projects.comparison', $project) }}" class="btn btn-outline-success w-100 py-3"><i class="bi bi-bar-chart d-block fs-3 mb-1"></i> مقارنة المقاولين</a></div>
                <div class="col-md-4"><a href="{{ route('projects.risks', $project) }}" class="btn btn-outline-warning w-100 py-3"><i class="bi bi-shield-exclamation d-block fs-3 mb-1"></i> سجل المخاطر</a></div>
            </div>
        </div>
    </div>
</div>
@endsection
