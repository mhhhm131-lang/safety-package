@extends('layouts.app')

@section('page_title', 'المشاريع')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">قائمة المشاريع</h5>
        @if(auth()->user()->can_('project.create'))<a href="{{ route('projects.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> مشروع جديد
        </a>@endif
    </div>

    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="color: var(--text-muted);">الاسم</th>
                            <th style="color: var(--text-muted);">الكود</th>
                            <th style="color: var(--text-muted);">المكان</th>
                            <th style="color: var(--text-muted);">الحالة</th>
                            <th style="color: var(--text-muted);">تاريخ البداية</th>
                            <th style="color: var(--text-muted);">تاريخ النهاية</th>
                            <th style="color: var(--text-muted);">المنسق</th>
                            <th style="color: var(--text-muted);">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($projects as $project)
                            @php
                                $pStatusColors = ['active' => 'success', 'planning' => 'info', 'completed' => 'dark', 'on_hold' => 'warning', 'cancelled' => 'secondary'];
                                $pStatusLabels = \App\Modules\Project\Models\Project::STATUSES;
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('projects.dashboard', $project) }}" style="color: var(--accent-color); text-decoration: none;">{{ $project->name }}</a>
                                </td>
                                <td style="color: var(--text-main);">{{ $project->code }}</td>
                                <td>{{ $project->place?->code ?? '—' }} <span class="small text-muted">{{ $project->place?->name }}</span></td>
                                <td>
                                    <span class="badge bg-{{ $pStatusColors[$project->status] ?? 'secondary' }}">
                                        {{ $pStatusLabels[$project->status] ?? $project->status }}
                                    </span>
                                </td>
                                <td style="color: var(--text-muted);">{{ $project->start_date?->format('Y-m-d') ?? '-' }}</td>
                                <td style="color: var(--text-muted);">{{ $project->end_date?->format('Y-m-d') ?? '-' }}</td>
                                <td style="color: var(--text-main);">{{ $project->assignedCoordinator->name ?? '-' }}</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('projects.dashboard', $project) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2"></i></a>
                                        <a href="{{ route('projects.contractors', $project) }}" class="btn btn-sm btn-outline-secondary" title="المقاولون"><i class="bi bi-people"></i></a>
                                        @if(auth()->user()->can_('project.edit'))<a href="{{ route('projects.edit', $project) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>@endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center py-4" style="color: var(--text-muted);">لا توجد مشاريع</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($projects->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $projects->links() }}
        </div>
    @endif
</div>
@endsection
